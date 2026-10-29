<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Sender address and the way to the SMTP server.
 *
 * Everything the mail plugin needs to know before it sends: which address the
 * mail comes from, which server to hand it to, and how to describe a failure
 * so that a human can act on it without the password ending up in the log.
 *
 * The password is never read from config, settings.php or an environment
 * variable: it lives in a Key entity, referenced by its id in smtp_key.
 */
final class MailTransport {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?KeyRepositoryInterface $keyRepository = NULL,
  ) {}

  /**
   * TRUE only when SMTP is switched on and a server address is set.
   *
   * A switched-on SMTP without a server address is not SMTP; otherwise the
   * mail would quietly disappear.
   */
  public function isSmtp(): bool {
    $config = $this->config();
    return (bool) $config->get('smtp_enabled') && trim((string) $config->get('smtp_host')) !== '';
  }

  /**
   * The Symfony mailer DSN for the configured transport.
   *
   * Not SMTP -> exactly 'sendmail://default'. Otherwise 'smtps://' for
   * implicit TLS, 'smtp://' for STARTTLS or no encryption, with the user name
   * and the password (when a key was found) rawurlencoded, and
   * '?auto_tls=false' when the administrator asked for an unencrypted
   * connection.
   */
  public function dsn(): string {
    if (!$this->isSmtp()) {
      return 'sendmail://default';
    }
    $config = $this->config();
    $security = (string) $config->get('smtp_security');
    $dsn = $security === 'ssl' ? 'smtps://' : 'smtp://';
    $user = trim((string) $config->get('smtp_user'));
    if ($user !== '') {
      $dsn .= rawurlencode($user);
      $password = $this->password();
      if ($password !== '') {
        $dsn .= ':' . rawurlencode($password);
      }
      $dsn .= '@';
    }
    $dsn .= trim((string) $config->get('smtp_host')) . ':' . (int) $config->get('smtp_port');
    if ($security === 'none') {
      $dsn .= '?auto_tls=false';
    }
    return $dsn;
  }

  /**
   * The sender: ['address' => ..., 'name' => ...], or [] for the site default.
   *
   * The name may be ''.
   *
   * @return array{address?: string, name?: string}
   */
  public function from(): array {
    $config = $this->config();
    $address = (string) $config->get('mail_from');
    if (trim($address) === '') {
      return [];
    }
    return ['address' => $address, 'name' => (string) $config->get('mail_from_name')];
  }

  /**
   * Where replies go; '' means the sender address.
   */
  public function replyTo(): string {
    return trim((string) $this->config()->get('mail_reply_to'));
  }

  /**
   * TRUE when a key id is configured but the Key module is switched off.
   *
   * The caller can then log a reason instead of sending unauthenticated mail
   * into a void. FALSE in every other case, including an empty smtp_key.
   */
  public function keyMissing(): bool {
    return $this->keyRepository === NULL && trim((string) $this->config()->get('smtp_key')) !== '';
  }

  /**
   * One line for the log: the reason, the place in the application and the
   * place in the code.
   *
   * The password (plain and urlencoded) is removed before returning: Symfony
   * puts the complete DSN into its connection errors, so without this the
   * password lands in the log the moment the server is unreachable.
   */
  public function describeFailure(\Throwable $e, string $where): string {
    $line = $e->getMessage() . ' (' . $where . ', ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    $password = $this->password();
    if ($password !== '') {
      $line = str_replace([$password, rawurlencode($password)], '***', $line);
    }
    return $line;
  }

  /**
   * The SMTP password, read only through the Key entity.
   *
   * A missing key or an empty id means no password, which is not an error.
   */
  private function password(): string {
    $keyId = trim((string) $this->config()->get('smtp_key'));
    if ($keyId === '' || $this->keyRepository === NULL) {
      return '';
    }
    $value = $this->keyRepository->getKey($keyId)?->getKeyValue();
    return $value === NULL ? '' : (string) $value;
  }

  /**
   * The hwdesk.settings config, read on every call (tests change it at
   * runtime).
   */
  private function config(): \Drupal\Core\Config\ImmutableConfig {
    return $this->configFactory->get('hwdesk.settings');
  }

}
