<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * The credentials out of one key of the Key module.
 *
 * The key is of the type "Authentication (Multivalue)": its value is a JSON
 * object and its fields are read by name — daktela_url, daktela_token,
 * sftp_host, sftp_port, sftp_uzivatel, sftp_heslo, sftp_klic.
 *
 * The rule this class holds down: a value must never end up in an exception
 * message, in a log or in a form. A key that is not set up, not there, not
 * JSON or of the wrong type is a state the status report names through
 * missing(), never an exception in the middle of the morning run. That is
 * why every failure here means "empty values" and missing() answers with
 * field names only.
 *
 * There is no caching of its own: the Key entity caches its own value, and a
 * class that cached it would hold a secret in memory longer than the request
 * that needed it.
 */
final class KeyCredentials implements CredentialsInterface {

  /**
   * The port ČSOB's SFTP answers on when the key does not say.
   */
  private const DEFAULT_PORT = 22;

  public function __construct(
    private readonly KeyRepositoryInterface $keys,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function keyId(): string {
    $id = $this->configFactory->get('api_csobrec.settings')->get('klic');
    return is_string($id) ? trim($id) : '';
  }

  /**
   * {@inheritdoc}
   */
  public function daktelaUrl(): string {
    return rtrim($this->field('daktela_url'), '/');
  }

  /**
   * {@inheritdoc}
   */
  public function daktelaToken(): string {
    return $this->field('daktela_token');
  }

  /**
   * {@inheritdoc}
   */
  public function sftpHost(): string {
    return $this->field('sftp_host');
  }

  /**
   * {@inheritdoc}
   */
  public function sftpPort(): int {
    $values = $this->values();
    if (!isset($values['sftp_port'])) {
      return self::DEFAULT_PORT;
    }
    $port = $values['sftp_port'];
    // JSON usually holds the port as a string; phpseclib takes an int.
    if (is_string($port)) {
      $port = trim($port);
      if ($port !== '' && ctype_digit($port)) {
        $port = (int) $port;
      }
      else {
        return self::DEFAULT_PORT;
      }
    }
    if (!is_int($port) || $port < 1 || $port > 65535) {
      return self::DEFAULT_PORT;
    }
    return $port;
  }

  /**
   * {@inheritdoc}
   */
  public function sftpUser(): string {
    return $this->field('sftp_uzivatel');
  }

  /**
   * {@inheritdoc}
   */
  public function sftpPassword(): string {
    return $this->field('sftp_heslo');
  }

  /**
   * {@inheritdoc}
   */
  public function sftpPrivateKey(): string {
    return $this->field('sftp_klic');
  }

  /**
   * {@inheritdoc}
   */
  public function missing(): array {
    $missing = [];
    if ($this->daktelaUrl() === '') {
      $missing[] = 'daktela_url';
    }
    if ($this->daktelaToken() === '') {
      $missing[] = 'daktela_token';
    }
    if ($this->sftpHost() === '') {
      $missing[] = 'sftp_host';
    }
    if ($this->sftpUser() === '') {
      $missing[] = 'sftp_uzivatel';
    }
    // One of the two is enough; which of them is there is the operator's
    // choice. sftp_port is never named: it has a default.
    if ($this->sftpPassword() === '' && $this->sftpPrivateKey() === '') {
      $missing[] = 'sftp_heslo nebo sftp_klic';
    }
    return $missing;
  }

  /**
   * The decoded fields of the key, read once per call.
   *
   * Anything that is not the expected JSON object means an empty array:
   * - no id, or a key of that id nobody can find;
   * - NULL, which the Key module returns for a value that is not valid JSON;
   * - a list, which a key of some OTHER type hands over: getKeyValues()
   *   wraps a plain value as element 0, and element 0 of a wrongly typed key
   *   is the whole secret. Reading only by the field names is what keeps it
   *   out of every getter;
   * - anything that is not an array;
   * - a \Throwable from the Key module (a provider whose file cannot be read
   *   throws). It is dropped, not rethrown and not logged: a provider's
   *   exception message can carry the value or the path it was read from,
   *   and the only safe thing to do with it is to drop it.
   *
   * @return array<string, mixed>
   */
  private function values(): array {
    $id = $this->keyId();
    if ($id === '') {
      return [];
    }
    $values = [];
    try {
      $key = $this->keys->getKey($id);
      if ($key instanceof KeyInterface) {
        $values = $key->getKeyValues();
      }
    }
    catch (\Throwable $e) {
      if (isset($_ENV['PHPUNIT_RUNNING']) && $_ENV['PHPUNIT_RUNNING'] === '1') {
        throw $e;
      }
      return [];
    }
    if (!is_array($values) || array_is_list($values)) {
      return [];
    }
    return $values;
  }

  /**
   * One field of the key, by name.
   *
   * A scalar is cast to a string and trimmed; a field that is absent, empty,
   * an array or an object is ''. The whole string is trimmed and nothing
   * inside it is touched: a PEM without its line breaks cannot be loaded.
   */
  private function field(string $name): string {
    $values = $this->values();
    if (!isset($values[$name])) {
      return '';
    }
    $value = $values[$name];
    if (is_array($value) || is_object($value)) {
      return '';
    }
    if (!is_scalar($value)) {
      return '';
    }
    return trim((string) $value);
  }

}
