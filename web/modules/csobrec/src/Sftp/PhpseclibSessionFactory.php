<?php

declare(strict_types=1);

namespace Drupal\csobrec\Sftp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\csobrec\Service\HostKeyCheck;
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
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Settings $settings,
    private readonly HostKeyCheck $hostKey,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function open(): SftpSessionInterface {
    $config = $this->configFactory->get('csobrec.settings');
    $host = (string) $config->get('sftp_host');
    $port = (int) ($config->get('sftp_port') ?: 22);
    $user = (string) $config->get('sftp_uzivatel');
    if ($host === '' || $user === '') {
      throw new \RuntimeException('SFTP ČSOB není nastavené: chybí adresa nebo přihlašovací jméno.');
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
    $this->loggerFactory->get('csobrec')->info('Otisk klíče serveru @host:@port je @otisk.', [
      '@host' => $host,
      '@port' => $port,
      '@otisk' => $fingerprint,
    ]);

    $keyPath = (string) $config->get('sftp_klic');
    if ($keyPath !== '') {
      $contents = @file_get_contents($keyPath);
      if ($contents === FALSE) {
        throw new \RuntimeException(sprintf('Privátní klíč %s se nedá přečíst.', $keyPath));
      }
      $credential = PublicKeyLoader::load($contents);
    }
    else {
      $credential = (string) $this->settings->get('csobrec_sftp_heslo', '');
      if ($credential === '') {
        throw new \RuntimeException("Není čím se přihlásit: nastavte cestu ke klíči, nebo \$settings['csobrec_sftp_heslo'] v settings.php.");
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
