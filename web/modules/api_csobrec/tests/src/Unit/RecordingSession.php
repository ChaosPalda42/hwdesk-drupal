<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Unit;

use Drupal\api_csobrec\Sftp\SftpSessionInterface;

/**
 * An SFTP session that only writes down what it was asked to do.
 */
final class RecordingSession implements SftpSessionInterface {

  /** @var list<string> */
  public array $listed = [];

  /** @var list<string> */
  public array $uploaded = [];

  /** @var array<string, string> */
  public array $local = [];

  public function __construct(
    private readonly bool $listFails = FALSE,
    private readonly int $failOnPut = 0,
  ) {}

  public function listDirectory(string $path): array {
    if ($this->listFails) {
      throw new \RuntimeException(sprintf('No such directory: %s', $path));
    }
    $this->listed[] = $path;
    return [];
  }

  public function put(string $remotePath, string $localFile): void {
    if ($this->failOnPut > 0 && count($this->uploaded) + 1 === $this->failOnPut) {
      throw new \RuntimeException('Connection reset');
    }
    $this->uploaded[] = $remotePath;
    $this->local[$remotePath] = $localFile;
  }

}
