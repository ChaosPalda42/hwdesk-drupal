<?php

declare(strict_types=1);

namespace Drupal\csobrec\Service;

use Drupal\csobrec\Call;
use Drupal\csobrec\Item;
use Drupal\csobrec\QueueMode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * One day, from Daktela's activities to a finished batch on disk.
 *
 * The whole morning in one method. Every rule lives in the service that owns
 * it — the selection in Selection, the file names in Naming, the index in
 * CallMapper, the ledger in SentLedger — this class only orders the steps and
 * counts what happened, because the counts are what the operator sees.
 *
 * The counts are the only customer data this class may let through: the
 * result is printed on the operator's terminal and written into the log, so
 * a phone number, a name or a proposal number must never end up in it.
 */
final class DayRun {

  /**
   * Dispositions that trigger the Logic hand-over.
   *
   * @var string[]
   */
  private const TRIGGER_DISPOSITIONS = [
    '[CSOB] Nový prodej',
    '[CSOB] Nový prodej TZ',
  ];

  public function __construct(
    private readonly DaktelaClient $daktela,
    private readonly CallMapper $mapper,
    private readonly Selection $selection,
    private readonly Naming $naming,
    private readonly Mp3Converter $converter,
    private readonly SentLedger $ledger,
    private readonly BatchBuilder $builder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Runs the whole day: queues, history, selection, recordings, batch.
   *
   * @param \DateTimeImmutable $day
   *   Any moment of the day; only its date matters.
   * @param \DateTimeImmutable $batchTime
   *   The batch time, in its own timezone; it names the index and the marker.
   * @param string $directoryUri
   *   Where the batch is assembled (a private:// stream URI, customer data).
   * @param string $workDir
   *   A plain existing filesystem directory for the downloads and the
   *   conversion: ffmpeg cannot write into a stream wrapper. The working
   *   files are left behind for the caller to clean up or to diagnose from.
   *
   * @return array{calls: int, unmappable: int, selected: int, xsellCopies: int, withoutRecording: int, failed: int, alreadySent: int, inBatch: int, historyQueries: int, historyCalls: int, activities: list<array{hovor: string, fronta: string}>, batch: array<string, mixed>|null}
   *   The counts of the run, one row per call that made it into the batch,
   *   and the built batch — NULL when there was nothing to send.
   *
   * @throws \InvalidArgumentException
   *   When a queue's mode label in csobrec.settings is not a known mode.
   *   Thrown before any request is sent: a typo in the mode must not quietly
   *   mean 'send everything' or 'send nothing'.
   * @throws \RuntimeException
   *   When $workDir is not an existing writable directory.
   */
  public function build(\DateTimeImmutable $day, \DateTimeImmutable $batchTime, string $directoryUri, string $workDir): array {
    if (!is_dir($workDir) || !is_writable($workDir)) {
      throw new \RuntimeException(sprintf('The working directory %s is not an existing writable directory; ffmpeg needs plain files, not a stream wrapper.', $workDir));
    }
    $logger = $this->loggerFactory->get('csobrec');

    // STEP 1: the queues.
    $modes = $this->modes();
    // PHP stores a numeric string array key as an int, so the keys of $modes
    // are ints however they were cast going in. Call::$queue is a string and
    // '5003' === 5003 is FALSE, so the queue numbers have to be strings again;
    // $modes[$queue] still finds them, because a lookup casts the other way.
    $queueNumbers = array_map('strval', array_keys($modes));

    // STEP 2: the day, one request per queue.
    $calls = [];
    $unmappable = 0;
    foreach ($this->daktela->callsOfDay($day, $queueNumbers) as $activity) {
      try {
        $call = $this->mapper->fromActivity($activity);
      }
      catch (\InvalidArgumentException $e) {
        // A live instance holds rows nobody can read; one of them must not
        // cost us the morning.
        $unmappable++;
        continue;
      }
      $calls[$call->activity] = $call;
    }

    // STEP 3: the history of the triggering numbers on the Logic queues.
    $historyQueries = 0;
    $historyCalls = 0;
    foreach ($queueNumbers as $queue) {
      if ($modes[$queue] !== QueueMode::Logic) {
        continue;
      }
      $phones = [];
      foreach ($calls as $call) {
        if ($call->queue === $queue
          && $call->phone !== ''
          && in_array($call->disposition, self::TRIGGER_DISPOSITIONS, TRUE)) {
          $phones[$call->phone] = TRUE;
        }
      }
      foreach (array_keys($phones) as $phone) {
        // The keys came from Call::$phone, which is a string, but the cast
        // keeps the intent: callsOfNumber takes a string and strict types
        // would throw a TypeError on an int.
        $historyQueries++;
        try {
          $history = $this->daktela->callsOfNumber((string) $phone, $queue);
        }
        catch (\InvalidArgumentException $e) {
          // A number too short to ask about safely: skip it the same way as
          // an unmappable row, with a warning in the log.
          $logger->warning('The history of a number on queue @queue could not be asked: @reason', [
            '@queue' => $queue,
            '@reason' => $e->getMessage(),
          ]);
          continue;
        }
        foreach ($history as $activity) {
          try {
            $call = $this->mapper->fromActivity($activity);
          }
          catch (\InvalidArgumentException $e) {
            $unmappable++;
            continue;
          }
          if (!isset($calls[$call->activity])) {
            $calls[$call->activity] = $call;
            $historyCalls++;
          }
        }
      }
    }

    // STEP 4: the selection.
    $items = $this->selection->select(array_values($calls), $modes);
    $selected = 0;
    $xsellCopies = 0;
    foreach ($items as $item) {
      if ($item->copy === 1) {
        $selected++;
      }
      else {
        $xsellCopies++;
      }
    }

    // STEP 5: what ČSOB already has. Before anything is downloaded: their
    // importer refuses a recording it already has, and we must not fetch
    // customer audio we are not going to send.
    $alreadySent = 0;
    $sent = $this->ledger->alreadySent(array_unique(array_map(static fn (Item $item): string => $item->call->activity, $items)));
    if ($sent !== []) {
      $alreadySent = count($sent);
      $sentMap = array_fill_keys($sent, TRUE);
      $kept = [];
      foreach ($items as $item) {
        if (isset($sentMap[$item->call->activity])) {
          continue;
        }
        $kept[] = $item;
      }
      $items = $kept;
    }

    // STEP 6: the recordings, once per call and not once per item.
    $withoutRecording = 0;
    $failed = 0;
    $mp3 = [];
    foreach (array_unique(array_map(static fn (Item $item): string => $item->call->activity, $items)) as $activity) {
      $recording = $this->daktela->recording((string) $activity);
      if ($recording === NULL) {
        // A line in the index with no file behind it is a batch ČSOB rejects.
        $withoutRecording++;
        continue;
      }
      $source = $workDir . '/' . $activity . '.' . $this->recordingExtension($recording);
      $target = $workDir . '/' . $activity . '.mp3';
      try {
        $this->daktela->download((string) $activity, $source);
        $this->converter->convert($source, $target);
      }
      catch (\RuntimeException $e) {
        // One broken recording must not cost us the whole morning.
        $failed++;
        $logger->error('The recording @activity could not be fetched or converted: @reason', [
          '@activity' => $activity,
          '@reason' => $e->getMessage(),
        ]);
        continue;
      }
      $mp3[$activity] = $target;
    }

    // STEP 7: the batch. Both copies point at the SAME converted mp3: it is
    // one recording handed over twice under two proposal numbers.
    $batchItems = [];
    $activities = [];
    foreach ($items as $item) {
      $activity = $item->call->activity;
      if (!isset($mp3[$activity])) {
        continue;
      }
      $name = $this->naming->mp3($item->call->time, $item->copy === 1 ? $activity : $activity . '_2');
      $batchItems[] = [
        'source' => $mp3[$activity],
        'line' => $this->mapper->lineFromItem($item, $name),
      ];
      if (!isset($activities[$activity])) {
        $activities[$activity] = ['hovor' => $activity, 'fronta' => $item->call->queue];
      }
    }
    $batch = NULL;
    if ($batchItems !== []) {
      $batch = $this->builder->build($directoryUri, $batchTime, $batchItems);
    }

    return [
      'calls' => count($calls),
      'unmappable' => $unmappable,
      'selected' => $selected,
      'xsellCopies' => $xsellCopies,
      'withoutRecording' => $withoutRecording,
      'failed' => $failed,
      'alreadySent' => $alreadySent,
      'inBatch' => count($batchItems),
      'historyQueries' => $historyQueries,
      'historyCalls' => $historyCalls,
      'activities' => array_values($activities),
      'batch' => $batch,
    ];
  }

  /**
   * The queue table from csobrec.settings:fronty, queue number => mode.
   *
   * Careful: PHP turns a numeric string array key into an int, so the keys
   * come back as ints (5003, not '5003'). They are cast to strings before
   * they are compared with Call::$queue, which is a string — '5003' === 5003
   * is FALSE under strict types and the whole Logic branch would silently do
   * nothing.
   *
   * @return array<string, \Drupal\csobrec\QueueMode>
   *
   * @throws \InvalidArgumentException
   *   When a label is not a known mode, naming the queue and the label.
   */
  private function modes(): array {
    $fronty = $this->configFactory->get('csobrec.settings')->get('fronty');
    if (!is_array($fronty)) {
      $fronty = [];
    }
    $modes = [];
    foreach ($fronty as $queue => $label) {
      $queue = (string) $queue;
      $label = is_string($label) ? $label : NULL;
      $mode = QueueMode::fromLabel($label);
      if ($mode === NULL) {
        throw new \InvalidArgumentException(sprintf('Queue %s has an unknown mode label %s in csobrec.settings:fronty; it must be one of the known modes.', $queue, $label === NULL ? '(none)' : $label));
      }
      $modes[$queue] = $mode;
    }
    return $modes;
  }

  /**
   * The extension of a recording answer.
   *
   * The extension of the 'recording' value, or its 'format' when that has
   * none, or 'ogg'.
   *
   * @param array<string, mixed> $recording
   *   The {recording, encrypt, format} object from Daktela.
   */
  private function recordingExtension(array $recording): string {
    $extension = pathinfo((string) ($recording['recording'] ?? ''), PATHINFO_EXTENSION);
    if ($extension !== '') {
      return $extension;
    }
    $format = (string) ($recording['format'] ?? '');
    return $format !== '' ? $format : 'ogg';
  }


}
