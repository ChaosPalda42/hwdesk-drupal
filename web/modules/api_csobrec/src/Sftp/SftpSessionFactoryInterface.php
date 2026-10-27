<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Sftp;

/**
 * Opens a logged-in SFTP session to ČSOB.
 *
 * Everything that knows about the address, the account, the password and the
 * server's host key lives behind this one method, so the run itself can be
 * tested without a server and so there is exactly one place to look when the
 * login breaks.
 */
interface SftpSessionFactoryInterface {

  /**
   * Connects, verifies the server's host key, logs in.
   *
   * @throws \RuntimeException
   *   When the module is not configured, when the host key does not match the
   *   configured fingerprint — in which case no password has been sent — or
   *   when the login fails. The exception never carries the password.
   */
  public function open(): SftpSessionInterface;

}
