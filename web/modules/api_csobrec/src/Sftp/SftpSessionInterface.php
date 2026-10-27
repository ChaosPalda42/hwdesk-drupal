<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Sftp;

/**
 * An open SFTP session, as little of it as the upload needs.
 *
 * The uploader never opens a connection: it is handed a session that is
 * already logged in. That keeps the upload rules (order of files, the .don
 * marker last) testable without a server, and keeps everything that knows
 * about keys, fingerprints and passwords in one adapter.
 */
interface SftpSessionInterface {

  /**
   * Lists a remote directory.
   *
   * @return list<string>
   *   The entry names.
   *
   * @throws \RuntimeException
   *   When the directory cannot be listed — it does not exist, or the account
   *   may not read it. The exception must not carry the password.
   */
  public function listDirectory(string $path): array;

  /**
   * Uploads one local file to a remote path.
   *
   * @throws \RuntimeException
   *   When the transfer fails.
   */
  public function put(string $remotePath, string $localFile): void;

}
