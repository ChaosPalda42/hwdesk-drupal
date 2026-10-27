<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

/**
 * Puts one finished batch together on disk under private://.
 *
 * The .don file is ČSOB's marker for 'this batch is complete'. It is written
 * LAST and only when everything before it worked: a batch without its marker
 * is retried, while a marker without its recordings is a silent loss.
 *
 * The sources are plain filesystem paths because ffmpeg writes real files;
 * the target is a stream URI because it is customer data under private://.
 * All writes therefore go through the file system service so the stream
 * wrapper is honoured.
 */
final class BatchBuilder {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly Naming $naming,
    private readonly IndexXml $indexXml,
  ) {
  }

  /**
   * Copies the recordings, writes the index and the .don marker.
   *
   * @param string $directoryUri
   *   The stream URI of the batch folder, e.g.
   *   private://api_csobrec/davky/DA_2026102680903.
   * @param \DateTimeImmutable $time
   *   The batch time, in its own timezone.
   * @param array<array{source: string, line: array<string, string>}> $items
   *   One entry per recorded call: the plain path of the converted MP3 and
   *   its index line.
   *
   * @return array{directory: string, recordings: int, xml: string, don: string, bytes: int}
   *   The batch folder, the number of recordings, the URIs of the index and
   *   the marker, and the sum of the sizes of every file in the folder.
   *
   * @throws \InvalidArgumentException
   *   When the batch is empty, when a line has no usable 'soubor' file name,
   *   or when two lines claim the same file name. Nothing is written when
   *   any of these fails.
   * @throws \RuntimeException
   *   When a source file does not exist. The message names it. Nothing is
   *   written when this fails.
   */
  public function build(string $directoryUri, \DateTimeImmutable $time, array $items): array {
    if ($items === []) {
      throw new \InvalidArgumentException('A batch without recordings is refused.');
    }

    // Every item is checked before anything is written: half a batch on disk
    // with no marker is a folder somebody has to clean up by hand.
    $seen = [];
    foreach ($items as $item) {
      $line = $item['line'];
      $name = $line['soubor'] ?? '';
      if ($name === '') {
        throw new \InvalidArgumentException('Every index line needs a non-empty "soubor" file name.');
      }
      if (basename($name) !== $name) {
        throw new \InvalidArgumentException('The "soubor" file name must not contain a path.');
      }
      if (isset($seen[$name])) {
        throw new \InvalidArgumentException('Two index lines claim the same file name; one recording would be lost.');
      }
      $seen[$name] = TRUE;
      if (!is_file($item['source'])) {
        throw new \RuntimeException('The recording ' . $item['source'] . ' does not exist.');
      }
    }

    $this->fileSystem->prepareDirectory($directoryUri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    foreach ($items as $item) {
      $this->fileSystem->copy($item['source'], $directoryUri . '/' . $item['line']['soubor'], FileExists::Replace);
    }

    $xmlUri = $directoryUri . '/' . $this->naming->xml($time);
    $this->fileSystem->saveData($this->indexXml->build(array_column($items, 'line')), $xmlUri, FileExists::Replace);

    // The marker goes last: it tells ČSOB the batch is complete.
    $donUri = $directoryUri . '/' . $this->naming->don($time);
    $this->fileSystem->saveData('', $donUri, FileExists::Replace);

    return [
      'directory' => $directoryUri,
      'recordings' => count($items),
      'xml' => $xmlUri,
      'don' => $donUri,
      'bytes' => $this->directoryBytes($directoryUri),
    ];
  }

  /**
   * Sums the sizes of every file directly in the batch folder.
   */
  private function directoryBytes(string $directoryUri): int {
    $real = $this->fileSystem->realpath($directoryUri);
    if ($real === FALSE || !is_dir($real)) {
      return 0;
    }
    $sum = 0;
    foreach (scandir($real) ?: [] as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $path = $real . '/' . $name;
      if (is_file($path)) {
        $sum += (int) filesize($path);
      }
    }
    return $sum;
  }

}
