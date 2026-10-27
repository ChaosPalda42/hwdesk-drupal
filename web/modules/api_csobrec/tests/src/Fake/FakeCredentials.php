<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Fake;

use Drupal\api_csobrec\Service\CredentialsInterface;

/**
 * The credentials a test hands over, without a key and without the Key module.
 *
 * Every test but KeyCredentialsTest uses this one: what the tests are about is
 * what the module does with an address and a token, not where it reads them
 * from.
 */
final class FakeCredentials implements CredentialsInterface {

  public function __construct(
    private readonly string $daktelaUrl = 'https://conectart.daktela.com',
    private readonly string $daktelaToken = 'TOKEN-DAKTELY',
    private readonly string $sftpHost = 'sftp.zkouska',
    private readonly int $sftpPort = 22,
    private readonly string $sftpUser = 'conectart',
    private readonly string $sftpPassword = 'heslo-zkouska',
    private readonly string $sftpPrivateKey = '',
    private readonly string $keyId = 'api_csobrec',
  ) {}

  public function keyId(): string {
    return $this->keyId;
  }

  public function daktelaUrl(): string {
    // The same promise the real one makes: the client builds paths onto it.
    return rtrim($this->daktelaUrl, '/');
  }

  public function daktelaToken(): string {
    return $this->daktelaToken;
  }

  public function sftpHost(): string {
    return $this->sftpHost;
  }

  public function sftpPort(): int {
    return $this->sftpPort;
  }

  public function sftpUser(): string {
    return $this->sftpUser;
  }

  public function sftpPassword(): string {
    return $this->sftpPassword;
  }

  public function sftpPrivateKey(): string {
    return $this->sftpPrivateKey;
  }

  public function missing(): array {
    $missing = [];
    foreach (['daktela_url' => $this->daktelaUrl, 'daktela_token' => $this->daktelaToken, 'sftp_host' => $this->sftpHost, 'sftp_uzivatel' => $this->sftpUser] as $field => $value) {
      if ($value === '') {
        $missing[] = $field;
      }
    }
    if ($this->sftpPassword === '' && $this->sftpPrivateKey === '') {
      $missing[] = 'sftp_heslo nebo sftp_klic';
    }
    return $missing;
  }

}
