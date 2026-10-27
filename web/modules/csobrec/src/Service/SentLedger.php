<?php

declare(strict_types=1);

namespace Drupal\csobrec\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * The ledger of what ČSOB already has.
 *
 * Two tables: csobrec_odeslano holds the calls that went out (one row per
 * call, keyed by the call, so a recording can never be sent twice) and
 * csobrec_davka holds one row per run of the hand-over, so the overview can
 * tell a finished run, a broken run and a day with nothing to send apart.
 *
 * Everything in here is identifiers and counts: no phone numbers, no names,
 * no policy numbers.
 */
final class SentLedger {

  /**
   * The most calls one statement may ask about at once.
   *
   * A backfill over a long outage asks about thousands of calls; one
   * statement with thousands of placeholders is refused by the database.
   */
  private const CHUNK_SIZE = 500;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Whether the call already went out to ČSOB.
   */
  public function wasSent(string $call): bool {
    return (bool) $this->database->select('csobrec_odeslano', 'o')
      ->condition('hovor', $call)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * The subset of the given calls that is already in the ledger.
   *
   * @param list<string> $calls
   *   The calls to check, in any order.
   *
   * @return list<string>
   *   The calls that are in csobrec_odeslano, in the input order.
   */
  public function alreadySent(array $calls): array {
    if ($calls === []) {
      return [];
    }
    $found = [];
    foreach (array_chunk($calls, self::CHUNK_SIZE) as $chunk) {
      $rows = $this->database->select('csobrec_odeslano', 'o')
        ->fields('o', ['hovor'])
        ->condition('hovor', $chunk, 'IN')
        ->execute()
        ->fetchCol();
      $present = array_fill_keys($rows, TRUE);
      foreach ($chunk as $call) {
        if (isset($present[$call])) {
          $found[] = $call;
        }
      }
    }
    return $found;
  }

  /**
   * Records the calls of a batch as sent.
   *
   * A call that is already in the ledger keeps the batch it first went out
   * in: rewriting that would make the ledger lie about what ČSOB received.
   *
   * @param string $batch
   *   The batch name, e.g. DA_2026102605.
   * @param list<array{hovor: string, fronta: string}> $rows
   *   The calls of the batch.
   *
   * @return int
   *   How many of the calls were new to the ledger.
   *
   * @throws \InvalidArgumentException
   *   When $rows is empty or a row has no hovor or no fronta.
   */
  public function record(string $batch, array $rows): int {
    if ($rows === []) {
      throw new \InvalidArgumentException('Nothing to record: the batch has no calls.');
    }
    // Validate every row before anything is written.
    foreach ($rows as $row) {
      if (!is_array($row)
        || !isset($row['hovor']) || !is_string($row['hovor']) || $row['hovor'] === ''
        || !isset($row['fronta']) || !is_string($row['fronta']) || $row['fronta'] === '') {
        throw new \InvalidArgumentException('Every recorded call needs both a hovor and a fronta.');
      }
    }
    // A call that is already in the ledger keeps the batch it first went out
    // in, so only the calls that are not there yet are written.
    $known = $this->alreadySent(array_map(static fn (array $row): string => $row['hovor'], $rows));
    $known = array_fill_keys($known, TRUE);
    $values = [];
    foreach ($rows as $row) {
      if (isset($known[$row['hovor']])) {
        continue;
      }
      $values[] = [
        'hovor' => $row['hovor'],
        'fronta' => $row['fronta'],
        'davka' => $batch,
        'odeslano' => $this->time->getRequestTime(),
      ];
    }
    if ($values === []) {
      return 0;
    }
    $insert = $this->database->insert('csobrec_odeslano');
    $insert->fields(['hovor', 'fronta', 'davka', 'odeslano']);
    foreach ($values as $value) {
      $insert->values($value);
    }
    $insert->execute();
    return count($values);
  }

  /**
   * Opens the run's own row in csobrec_davka.
   *
   * @return int
   *   The id of the new row.
   */
  public function openBatch(\DateTimeImmutable $day, string $name, int $calls): int {
    $this->database->insert('csobrec_davka')
      ->fields(['den', 'jmeno', 'hovoru', 'nahrano', 'bajtu', 'zalozeno', 'stav', 'chyba'])
      ->values([
        'den' => $day->format('Y-m-d'),
        'jmeno' => $name,
        'hovoru' => $calls,
        'nahrano' => 0,
        'bajtu' => 0,
        'zalozeno' => $this->time->getRequestTime(),
        'stav' => 'bezi',
        'chyba' => NULL,
      ])
      ->execute();
    return (int) $this->database->lastInsertId('csobrec_davka');
  }

  /**
   * Marks the run as finished with what actually went out.
   *
   * @throws \InvalidArgumentException
   *   When the id is not in csobrec_davka.
   */
  public function finishBatch(int $id, int $uploaded, int $bytes): void {
    $this->updateBatch($id, ['stav' => 'odeslano', 'nahrano' => $uploaded, 'bajtu' => $bytes]);
  }

  /**
   * Marks the run as broken, with the reason.
   *
   * @throws \InvalidArgumentException
   *   When the id is not in csobrec_davka.
   */
  public function failBatch(int $id, string $error): void {
    $this->updateBatch($id, ['stav' => 'chyba', 'chyba' => $error]);
  }

  /**
   * Marks the run as skipped, with the reason.
   *
   * A day with nothing to send and a broken run must not look the same in
   * the overview.
   *
   * @throws \InvalidArgumentException
   *   When the id is not in csobrec_davka.
   */
  public function skipBatch(int $id, string $why): void {
    $this->updateBatch($id, ['stav' => 'preskoceno', 'chyba' => $why]);
  }

  /**
   * The latest run of a day, or NULL when the day has none.
   *
   * @return array<string, mixed>|NULL
   *   The row as an associative array.
   */
  public function lastBatchOfDay(\DateTimeImmutable $day): ?array {
    $row = $this->database->select('csobrec_davka', 'd')
      ->fields('d')
      ->condition('den', $day->format('Y-m-d'))
      ->orderBy('zalozeno', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * How many calls are in the ledger and how many runs it knows.
   *
   * @return array{hovoru: int, davek: int}
   */
  public function counts(): array {
    return [
      'hovoru' => (int) $this->database->select('csobrec_odeslano', 'o')->countQuery()->execute()->fetchField(),
      'davek' => (int) $this->database->select('csobrec_davka', 'd')->countQuery()->execute()->fetchField(),
    ];
  }

  /**
   * Updates one run's row, refusing ids that are not in the table.
   *
   * @param array<string, mixed> $values
   *
   * @throws \InvalidArgumentException
   *   When the id is not in csobrec_davka.
   */
  private function updateBatch(int $id, array $values): void {
    $exists = $this->database->select('csobrec_davka', 'd')
      ->fields('d', ['id'])
      ->condition('id', $id)
      ->execute()
      ->fetchField();
    if ($exists === FALSE) {
      throw new \InvalidArgumentException(sprintf('Unknown batch row %d.', $id));
    }
    $this->database->update('csobrec_davka')
      ->condition('id', $id)
      ->fields($values)
      ->execute();
  }
}
