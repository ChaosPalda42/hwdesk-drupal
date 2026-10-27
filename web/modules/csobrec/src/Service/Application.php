<?php

declare(strict_types=1);

namespace Drupal\csobrec\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\csobrec\Sftp\SftpSessionFactoryInterface;

/**
 * The morning run as a whole: build, upload, write it down, clean up.
 *
 * The order of the last three is the whole safety of the transfer. The batch
 * is uploaded BEFORE the ledger is written, so a failed transfer leaves the
 * ledger untouched and the next run tries again; the downloaded recordings
 * are deleted only after ČSOB has them; and a day that already went out is
 * not sent a second time, because their importer refuses a second batch for
 * the same day.
 *
 * The result is printed on the operator's terminal and written into the log,
 * so it carries counts and the batch name — nothing else.
 */
final class Application {

  public function __construct(
    private readonly DayRun $dayRun,
    private readonly SftpUploader $uploader,
    private readonly SentLedger $ledger,
    private readonly Naming $naming,
    private readonly SftpSessionFactoryInterface $sessions,
    private readonly FileSystemInterface $fileSystem,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Runs the whole morning for one day.
   *
   * @param \DateTimeImmutable $day
   *   Any moment of the day; only its date matters.
   * @param bool $upload
   *   FALSE builds the batch and leaves it on disk to be looked at, without
   *   handing anything over or writing it down as handed over.
   * @param bool $force
   *   TRUE sends the day again even though a batch of it already went out.
   * @param \DateTimeImmutable|null $batchTime
   *   The batch time, in its own timezone; it names the batch and its
   *   folder, so a forced second run of the same day lands in its own
   *   folder. Defaults to now.
   *
   * @return array<string, int|string|bool>
   *   The counts of the run (every one an int), 'uploaded' — the number of
   *   files that arrived at ČSOB — and 'batch', the batch name. The 'skipped'
   *   key exists only when the day already went out and was not forced.
   *
   * @throws \RuntimeException
   *   When the transfer fails, or when csobrec.settings:sftp_cil is empty.
   *   The failed run is written down as 'chyba' before the exception is
   *   rethrown: a transfer that did not happen must not be reported as a
   *   success.
   */
  public function run(\DateTimeImmutable $day, bool $upload = TRUE, bool $force = FALSE, ?\DateTimeImmutable $batchTime = NULL): array {
    $batchTime ??= new \DateTimeImmutable('now');
    $logger = $this->loggerFactory->get('csobrec');
    $batchName = $this->naming->batch($batchTime);

    // STEP 1: has this day already gone out? Their importer refuses a second
    // batch for the same day, so only a batch that really went out blocks
    // the day; a failed or a skipped morning must not lock it out for good.
    $last = $this->ledger->lastBatchOfDay($day);
    if ($last !== NULL && $last['stav'] === 'odeslano' && !$force) {
      $logger->info('The day @day already went out as @batch; nothing is sent again.', [
        '@day' => $day->format('Y-m-d'),
        '@batch' => (string) $last['jmeno'],
      ]);
      return ['skipped' => TRUE, 'batch' => (string) $last['jmeno']];
    }

    // STEP 2: the two directories, both derived from the day and the batch
    // time, so a forced second run can never share a folder with the first.
    // The work directory is prepared here and handed to the day run as a
    // REAL path, because ffmpeg cannot write into a stream wrapper. The
    // batch directory is NOT created here: the builder creates it, and a
    // day with nothing to send must leave no empty folder behind.
    $date = $day->format('Y-m-d');
    $time = $batchTime->format('His');
    $batchUri = 'private://csobrec/davky/' . $date . '/' . $time;
    $workUri = 'private://csobrec/prac/' . $date . '/' . $time;
    $this->fileSystem->prepareDirectory($workUri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $workPath = $this->fileSystem->realpath($workUri);
    if ($workPath === FALSE) {
      throw new \RuntimeException(sprintf('The working directory %s could not be prepared.', $workUri));
    }

    // STEP 3: the day, from Daktela's activities to a finished batch on disk.
    $run = $this->dayRun->build($day, $batchTime, $batchUri, $workPath);

    // STEP 4: the run is written down as started, whether or not anything
    // will be uploaded, so a failed or an empty morning is visible in the
    // overview instead of leaving no trace.
    $id = $this->ledger->openBatch($day, $batchName, (int) $run['inBatch']);

    // STEP 5: nothing to send, or a test run. The batch stays on disk to be
    // looked at; nothing is deleted and nothing is written into
    // csobrec_odeslano. A quiet day and a broken run must not look the same
    // in the overview, and a test run must claim nothing was handed over.
    if ($run['batch'] === NULL || !$upload) {
      $why = $run['batch'] === NULL
        ? sprintf('Nic k odeslání: v dávce je %d hovorů.', (int) $run['inBatch'])
        : sprintf('Testový běh: dávka je sestavená, %d hovorů se neodesílalo.', (int) $run['inBatch']);
      $this->ledger->skipBatch($id, $why);
      $logger->info('The day @day was not sent: @why', [
        '@day' => $date,
        '@why' => $why,
      ]);
      return $this->result($run, 0, $batchName);
    }

    // STEP 6: the upload, and only then the writing down. The ledger is what
    // tells tomorrow's run what ČSOB already has, so it is written only
    // about files that really arrived.
    $remoteDir = (string) $this->configFactory->get('csobrec.settings')->get('sftp_cil');
    try {
      if ($remoteDir === '') {
        throw new \RuntimeException('The target path csobrec.settings:sftp_cil is not set; without it the batch would be uploaded into the session\'s home directory, where nobody looks.');
      }
      $session = $this->sessions->open();
      $batchPath = $this->fileSystem->realpath($batchUri);
      if ($batchPath === FALSE) {
        throw new \RuntimeException(sprintf('The batch directory %s does not exist.', $batchUri));
      }
      $uploaded = $this->uploader->upload($session, $batchPath, $remoteDir);
    }
    catch (\Throwable $e) {
      // The working files are left where they are: that is what the
      // diagnosis is done from. Nothing goes into csobrec_odeslano.
      $this->ledger->failBatch($id, $e->getMessage());
      $logger->error('The day @day could not be sent as @batch: @reason', [
        '@day' => $date,
        '@batch' => $batchName,
        '@reason' => $e->getMessage(),
      ]);
      throw $e;
    }

    if ($run['activities'] !== []) {
      $this->ledger->record($batchName, $run['activities']);
    }
    $this->ledger->finishBatch($id, count($uploaded), (int) $run['batch']['bytes']);
    $logger->info('The day @day went out as @batch: @files files, @bytes bytes.', [
      '@day' => $date,
      '@batch' => $batchName,
      '@files' => count($uploaded),
      '@bytes' => (int) $run['batch']['bytes'],
    ]);

    // STEP 7: the clean-up. With the upload behind us, the work directory of
    // that day goes — customer audio on our disk is a liability — while the
    // batch folder stays as the proof of what was handed over.
    $this->deleteTree('private://csobrec/prac/' . $date);

    return $this->result($run, count($uploaded), $batchName);
  }

  /**
   * Clears finished batches off the disk.
   *
   * csobrec.settings:davky_dni is the number of days to keep; 0 (or less)
   * means keep everything. In both private://csobrec/davky and
   * private://csobrec/prac, every immediate subdirectory whose name is a
   * Y-m-d date strictly older than $now minus that many days is deleted. A
   * name that is not a date is left alone — somebody put it there on
   * purpose. The ledger is NEVER touched: the disk is cleared, the record
   * of what ČSOB received is forever.
   *
   * @param \DateTimeImmutable $now
   *   The moment the pruning runs.
   *
   * @return int
   *   How many directories were deleted (a day in both places counts twice).
   */
  public function prune(\DateTimeImmutable $now): int {
    $days = (int) $this->configFactory->get('csobrec.settings')->get('davky_dni');
    if ($days <= 0) {
      return 0;
    }
    // The comparison is date against date: a folder named 2026-09-27 is not
    // older than the cutoff 2026-09-27, whatever the time of day the pruning
    // runs.
    $cutoff = $now->modify(sprintf('-%d days', $days))->format('Y-m-d');
    $removed = 0;
    foreach (['private://csobrec/davky', 'private://csobrec/prac'] as $uri) {
      $real = $this->fileSystem->realpath($uri);
      if ($real === FALSE || !is_dir($real)) {
        continue;
      }
      foreach (scandir($real) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
          continue;
        }
        $path = $real . '/' . $name;
        if (!is_dir($path)) {
          continue;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $name);
        if ($parsed === FALSE || $parsed->format('Y-m-d') !== $name) {
          // Not a date: somebody put it there on purpose.
          continue;
        }
        if ($parsed->format('Y-m-d') < $cutoff) {
          $this->deleteTree($uri . '/' . $name);
          $removed++;
        }
      }
    }
    return $removed;
  }

  /**
   * Deletes a directory tree under private://, files through the file system
   * service so the stream wrapper is honoured.
   *
   * @param string $uri
   *   The stream URI of the directory.
   */
  private function deleteTree(string $uri): void {
    $real = $this->fileSystem->realpath($uri);
    if ($real === FALSE || !is_dir($real)) {
      return;
    }
    foreach (scandir($real) ?: [] as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $path = $real . '/' . $name;
      if (is_dir($path)) {
        $this->deleteTree($uri . '/' . $name);
      }
      else {
        $this->fileSystem->delete($uri . '/' . $name);
      }
    }
    rmdir($real);
  }

  /**
   * The printed result: the counts of the run, nothing else.
   *
   * @param array<string, mixed> $run
   *   The day run's result.
   * @param int $uploaded
   *   The number of files that arrived at ČSOB.
   * @param string $batchName
   *   The batch name.
   *
   * @return array<string, int|string>
   */
  private function result(array $run, int $uploaded, string $batchName): array {
    $result = [];
    foreach ($run as $key => $value) {
      if ($key === 'activities' || $key === 'batch') {
        continue;
      }
      $result[$key] = (int) $value;
    }
    $result['uploaded'] = $uploaded;
    $result['batch'] = $batchName;
    return $result;
  }

}
