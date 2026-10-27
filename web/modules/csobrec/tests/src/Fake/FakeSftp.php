<?php

declare(strict_types=1);

namespace Drupal\Tests\csobrec\Fake;

use Drupal\csobrec\Sftp\SftpSessionFactoryInterface;
use Drupal\csobrec\Sftp\SftpSessionInterface;

/**
 * ČSOB's SFTP server, as far as a test needs it: it remembers what arrived.
 */
final class FakeSftp implements SftpSessionFactoryInterface, SftpSessionInterface {

  /**
   * Remote paths that were uploaded, in order.
   *
   * @var list<string>
   */
  public array $uploaded = [];

  /**
   * The bytes that arrived, by remote path.
   *
   * @var array<string, string>
   */
  public array $bytes = [];

  public int $opened = 0;

  public function __construct(
    public bool $openFails = FALSE,
    public bool $uploadFails = FALSE,
  ) {}

  public function open(): SftpSessionInterface {
    $this->opened++;
    if ($this->openFails) {
      throw new \RuntimeException('otisk klíče serveru nesouhlasí — nic se neposílá');
    }
    return $this;
  }

  public function listDirectory(string $path): array {
    return [];
  }

  public function put(string $remotePath, string $localFile): void {
    if ($this->uploadFails) {
      throw new \RuntimeException('spojení selhalo');
    }
    $this->uploaded[] = $remotePath;
    $this->bytes[$remotePath] = (string) file_get_contents($localFile);
  }

}
