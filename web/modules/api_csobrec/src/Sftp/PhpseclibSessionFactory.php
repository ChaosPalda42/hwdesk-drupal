<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Sftp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\api_csobrec\Service\CredentialsInterface;
use Drupal\api_csobrec\Service\HostKeyCheck;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * Opens the session to ČSOB's SFTP: host key first, password second.
 *
 * The order of the two is the whole security of this transfer and it is not
 * obvious from the library's API — getServerPublicHostKey() connects and
 * hands over the key BEFORE login() sends anything, so the fingerprint is
 * compared while the password is still ours. A library call that logs in on
 * its own (paramiko's Transport::connect() does, and that cost us an
 * afternoon in the Python version) would send the password to whoever
 * answered the port.
 */
final class PhpseclibSessionFactory implements SftpSessionFactoryInterface {

  public function __construct(
    private readonly CredentialsInterface $credentials,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly HostKeyCheck $hostKey,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function open(): SftpSessionInterface {
    $config = $this->configFactory->get('api_csobrec.settings');
    $host = $this->credentials->sftpHost();
    $port = $this->credentials->sftpPort();
    $user = $this->credentials->sftpUser();
    if ($host === '' || $user === '') {
      throw new \RuntimeException('SFTP ČSOB není nastavené: v klíči chybí sftp_host nebo sftp_uzivatel.');
    }

    $sftp = new SFTP($host, $port, 60);

    // Connects and reads the key; nothing of ours has been sent yet.
    $fingerprint = $this->hostKey->verify(
      $sftp->getServerPublicHostKey(),
      (string) $config->get('sftp_otisk'),
      (bool) $config->get('sftp_overit_klic'),
    );
    // Logged on every run: until ČSOB confirms their key, this is the only
    // place the fingerprint can be read from. It is not a confirmation —
    // a forger's key would be logged just as happily.
    $this->loggerFactory->get('api_csobrec')->info('Otisk klíče serveru @host:@port je @otisk.', [
      '@host' => $host,
      '@port' => $port,
      '@otisk' => $fingerprint,
    ]);

    // The private key itself is in the key, not a path to a file on our disk.
    // PublicKeyLoader::load() throws when it cannot read it, and its message
    // says only that — nothing of the key's contents.
    $pem = $this->credentials->sftpPrivateKey();
    if ($pem !== '') {
      $credential = PublicKeyLoader::load($pem);
    }
    else {
      $credential = $this->credentials->sftpPassword();
      if ($credential === '') {
        throw new \RuntimeException('Není čím se přihlásit: v klíči musí být sftp_heslo, nebo sftp_klic.');
      }
    }

    // Never include the credential in the message: the exception travels into
    // the log and onto the operator's terminal.
    if (!$sftp->login($user, $credential)) {
      throw new \RuntimeException(sprintf('Přihlášení uživatele %s na %s:%d odmítnuto.', $user, $host, $port));
    }

    return new PhpseclibSession($sftp);
  }

}
