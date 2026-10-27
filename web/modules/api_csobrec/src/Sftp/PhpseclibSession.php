<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Sftp;

use phpseclib3\Net\SFTP;

/**
 * An open phpseclib session, narrowed to what the upload needs.
 */
final class PhpseclibSession implements SftpSessionInterface {

  public function __construct(private readonly SFTP $sftp) {}

  public function listDirectory(string $path): array {
    $found = $this->sftp->nlist($path);
    if ($found === FALSE) {
      throw new \RuntimeException(sprintf('Adresář %s na SFTP nelze přečíst: %s', $path, $this->sftp->getLastSFTPError()));
    }
    return array_values(array_map('strval', $found));
  }

  public function put(string $remotePath, string $localFile): void {
    if (!$this->sftp->put($remotePath, $localFile, SFTP::SOURCE_LOCAL_FILE)) {
      throw new \RuntimeException(sprintf('Nahrání %s selhalo: %s', $remotePath, $this->sftp->getLastSFTPError()));
    }
  }

  /**
   * Hangs up; the batch is either complete on their side or it is not.
   */
  public function __destruct() {
    $this->sftp->disconnect();
  }

}
