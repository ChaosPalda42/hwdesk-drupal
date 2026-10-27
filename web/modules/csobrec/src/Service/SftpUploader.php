<?php

declare(strict_types=1);

namespace Drupal\csobrec\Service;

use Drupal\csobrec\Sftp\SftpSessionInterface;

/**
 * Uploads one finished batch to ČSOB over an already-open SFTP session.
 *
 * ČSOB collects the folder every morning and reads the .don file as 'the
 * batch is complete'. The upload order therefore serves one rule: the
 * recordings go up first (in name order), then the index, and the .don
 * marker LAST — a failure part way through must leave no marker, because a
 * batch without its marker is retried, while a marker without its recordings
 * is a silent loss.
 *
 * The uploader never opens a connection and never creates the remote
 * directory: a typo in the path would ship the batch where nobody looks
 * while the ledger claims ČSOB has it.
 */
final class SftpUploader {

  /**
   * Uploads the plain files of one finished batch.
   *
   * The batch is the plain files directly in $localDir: exactly one *.xml,
   * exactly one *.don and at least one *.mp3. Anything else (other
   * extensions, subdirectories) is ignored.
   *
   * @param \Drupal\csobrec\Sftp\SftpSessionInterface $session
   *   An already-logged-in session.
   * @param string $localDir
   *   The directory holding the batch files.
   * @param string $remoteDir
   *   The directory on the ČSOB side; it must already exist and be listable.
   *
   * @return list<string>
   *   The remote paths in upload order, the .don marker last.
   *
   * @throws \InvalidArgumentException
   *   When $localDir is not a readable directory, or the batch is
   *   incomplete. The message carries counts only — the file names hold the
   *   activity ids.
   * @throws \RuntimeException
   *   When the remote directory cannot be listed, or a transfer fails.
   */
  public function upload(SftpSessionInterface $session, string $localDir, string $remoteDir): array {
    if (!is_dir($localDir) || !is_readable($localDir)) {
      throw new \InvalidArgumentException('The local batch directory is not a readable directory.');
    }

    $recordings = [];
    $indexes = [];
    $markers = [];
    foreach (scandir($localDir) as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $path = $localDir . '/' . $name;
      if (!is_file($path)) {
        continue;
      }
      $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if ($extension === 'mp3') {
        $recordings[] = $name;
      }
      elseif ($extension === 'xml') {
        $indexes[] = $name;
      }
      elseif ($extension === 'don') {
        $markers[] = $name;
      }
    }

    $problems = [];
    if (count($recordings) < 1) {
      $problems[] = count($recordings) . ' recording(s)';
    }
    if (count($indexes) !== 1) {
      $problems[] = count($indexes) . ' index file(s)';
    }
    if (count($markers) !== 1) {
      $problems[] = count($markers) . ' marker file(s)';
    }
    if ($problems !== []) {
      throw new \InvalidArgumentException('Incomplete batch: ' . implode(', ', $problems) . '.');
    }

    // The remote directory must already exist and be readable by the
    // account. It is listed once, before anything is uploaded; the module
    // never creates it on their side.
    try {
      $session->listDirectory($remoteDir);
    }
    catch (\RuntimeException $e) {
      throw new \RuntimeException('Cannot list the remote directory ' . $remoteDir . ': ' . $e->getMessage(), 0, $e);
    }

    sort($recordings);
    $order = [...$recordings, $indexes[0], $markers[0]];

    $base = rtrim($remoteDir, '/');
    $uploaded = [];
    foreach ($order as $name) {
      $remote = $base . '/' . $name;
      $session->put($remote, $localDir . '/' . $name);
      $uploaded[] = $remote;
    }
    return $uploaded;
  }

}
