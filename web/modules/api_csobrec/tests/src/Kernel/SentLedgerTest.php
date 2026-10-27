<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Kernel;

use Drupal\api_csobrec\Service\SentLedger;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_csobrec\Traits\ApiCsobrecFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The ledger of what ČSOB already has.
 *
 * This is the only safeguard against sending the same recording twice, so the
 * module keeps it even when it is uninstalled. It holds identifiers and counts
 * and nothing else: no phone numbers, no names, no policy numbers.
 */
#[Group('api_csobrec')]
final class SentLedgerTest extends KernelTestBase {

  use ApiCsobrecFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'api_csobrec'];

  protected function setUp(): void {
    parent::setUp();
    $this->installApiCsobrec();
  }

  private function ledger(): SentLedger {
    return $this->container->get('api_csobrec.sent_ledger');
  }

  private function day(string $iso = '2026-10-26'): \DateTimeImmutable {
    return new \DateTimeImmutable($iso . ' 00:00:00');
  }

  /**
   * @param list<string> $calls
   * @return list<array{hovor: string, fronta: string}>
   */
  private function rows(array $calls, string $queue = '5003'): array {
    return array_map(static fn (string $call): array => ['hovor' => $call, 'fronta' => $queue], $calls);
  }

  public function testACallIsUnknownUntilItIsRecorded(): void {
    $this->assertFalse($this->ledger()->wasSent('aktivita-1'));
    $this->ledger()->record('DA_2026102605', $this->rows(['aktivita-1']));
    $this->assertTrue($this->ledger()->wasSent('aktivita-1'));
    $this->assertFalse($this->ledger()->wasSent('aktivita-2'));
  }

  public function testRecordingStoresTheQueueTheBatchAndWhen(): void {
    $this->ledger()->record('DA_2026102605', $this->rows(['aktivita-1'], '5004'));
    $row = $this->container->get('database')
      ->select('api_csobrec_odeslano', 'o')
      ->fields('o', ['hovor', 'fronta', 'davka', 'odeslano'])
      ->execute()
      ->fetchAssoc();
    $this->assertSame('aktivita-1', $row['hovor']);
    $this->assertSame('5004', $row['fronta']);
    $this->assertSame('DA_2026102605', $row['davka']);
    $this->assertSame((int) $this->container->get('datetime.time')->getRequestTime(), (int) $row['odeslano']);
  }

  public function testRecordingReturnsHowManyCallsWereNew(): void {
    $this->assertSame(3, $this->ledger()->record('DA_1', $this->rows(['a1', 'a2', 'a3'])));
    $this->assertSame(1, $this->ledger()->record('DA_2', $this->rows(['a3', 'a4'])));
  }

  public function testACallKeepsTheBatchItFirstWentOutIn(): void {
    // Rewriting history would make the ledger lie about what ČSOB received.
    $this->ledger()->record('DA_1', $this->rows(['a1']));
    $this->ledger()->record('DA_2', $this->rows(['a1']));
    $batches = $this->container->get('database')
      ->select('api_csobrec_odeslano', 'o')
      ->fields('o', ['davka'])
      ->condition('hovor', 'a1')
      ->execute()
      ->fetchCol();
    $this->assertSame(['DA_1'], $batches);
  }

  public function testAlreadySentReturnsOnlyTheCallsThatAreInTheLedger(): void {
    $this->ledger()->record('DA_1', $this->rows(['a1', 'a3']));
    $found = $this->ledger()->alreadySent(['a1', 'a2', 'a3', 'a4']);
    sort($found);
    $this->assertSame(['a1', 'a3'], $found);
  }

  public function testAlreadySentOfNothingIsNothing(): void {
    $this->assertSame([], $this->ledger()->alreadySent([]));
  }

  public function testAlreadySentSurvivesMoreCallsThanADatabaseTakesPlaceholdersFor(): void {
    // A backfill over a long outage asks about thousands of calls at once;
    // one statement with thousands of placeholders is refused by the database.
    $calls = [];
    for ($i = 0; $i < 2500; $i++) {
      $calls[] = sprintf('a%04d', $i);
    }
    $this->ledger()->record('DA_1', $this->rows(array_slice($calls, 0, 10)));
    $found = $this->ledger()->alreadySent($calls);
    sort($found);
    $this->assertSame(array_slice($calls, 0, 10), $found);
  }

  public function testRecordingNothingIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->ledger()->record('DA_1', []);
  }

  public function testARowWithoutAQueueIsRefusedAndNothingIsWritten(): void {
    try {
      $this->ledger()->record('DA_1', [['hovor' => 'a1', 'fronta' => '5003'], ['hovor' => 'a2']]);
      $this->fail('A row without a queue must be refused.');
    }
    catch (\InvalidArgumentException) {
      // Expected.
    }
    $this->assertSame(0, (int) $this->container->get('database')->select('api_csobrec_odeslano', 'o')->countQuery()->execute()->fetchField());
  }

  public function testAnEmptyIdentifierIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->ledger()->record('DA_1', [['hovor' => '', 'fronta' => '5003']]);
  }

  // --- the run's own row ---------------------------------------------------

  public function testAnOpenedBatchIsRunningAndCountsNothingYet(): void {
    $id = $this->ledger()->openBatch($this->day(), 'DA_2026102605', 12);
    $row = $this->batchRow($id);
    $this->assertSame('2026-10-26', $row['den']);
    $this->assertSame('DA_2026102605', $row['jmeno']);
    $this->assertSame(12, (int) $row['hovoru']);
    $this->assertSame(0, (int) $row['nahrano']);
    $this->assertSame(0, (int) $row['bajtu']);
    $this->assertSame('bezi', $row['stav']);
    $this->assertNull($row['chyba']);
    $this->assertSame((int) $this->container->get('datetime.time')->getRequestTime(), (int) $row['zalozeno']);
  }

  public function testAFinishedBatchCarriesWhatWentOut(): void {
    $id = $this->ledger()->openBatch($this->day(), 'DA_2026102605', 12);
    $this->ledger()->finishBatch($id, 11, 307_000_000);
    $row = $this->batchRow($id);
    $this->assertSame('odeslano', $row['stav']);
    $this->assertSame(11, (int) $row['nahrano']);
    $this->assertSame(307_000_000, (int) $row['bajtu']);
    $this->assertNull($row['chyba']);
  }

  public function testAFailedBatchCarriesTheReason(): void {
    $id = $this->ledger()->openBatch($this->day(), 'DA_2026102605', 12);
    $this->ledger()->failBatch($id, 'cílová složka /upload/nahravky neexistuje');
    $row = $this->batchRow($id);
    $this->assertSame('chyba', $row['stav']);
    $this->assertSame('cílová složka /upload/nahravky neexistuje', $row['chyba']);
  }

  public function testADayWithNothingToSendIsWrittenDownAsSkipped(): void {
    // A silent nothing and a broken run must not look the same in the overview.
    $id = $this->ledger()->openBatch($this->day(), 'DA_2026102605', 0);
    $this->ledger()->skipBatch($id, 'žádné hovory k předání');
    $row = $this->batchRow($id);
    $this->assertSame('preskoceno', $row['stav']);
    $this->assertSame('žádné hovory k předání', $row['chyba']);
  }

  public function testAnUnknownBatchRowIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->ledger()->finishBatch(4242, 1, 1);
  }

  public function testTheLastBatchOfADayIsTheLatestRun(): void {
    $this->assertNull($this->ledger()->lastBatchOfDay($this->day()));
    $first = $this->ledger()->openBatch($this->day(), 'DA_2026102605', 3);
    $this->ledger()->failBatch($first, 'spojení selhalo');
    $second = $this->ledger()->openBatch($this->day(), 'DA_2026102607', 3);
    $this->ledger()->finishBatch($second, 3, 100);

    $last = $this->ledger()->lastBatchOfDay($this->day());
    $this->assertNotNull($last);
    $this->assertSame('DA_2026102607', $last['jmeno']);
    $this->assertSame('odeslano', $last['stav']);
    $this->assertSame($second, (int) $last['id']);
    $this->assertNull($this->ledger()->lastBatchOfDay($this->day('2026-10-25')));
  }

  public function testTheCountsAreJustNumbers(): void {
    $this->ledger()->record('DA_1', $this->rows(['a1', 'a2']));
    $id = $this->ledger()->openBatch($this->day(), 'DA_1', 2);
    $this->ledger()->finishBatch($id, 2, 50);
    $this->assertSame(['hovoru' => 2, 'davek' => 1], $this->ledger()->counts());
  }

  /**
   * @return array<string, mixed>
   */
  private function batchRow(int $id): array {
    $row = $this->container->get('database')
      ->select('api_csobrec_davka', 'd')
      ->fields('d')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row, sprintf('Batch row %d is missing.', $id));
    return $row;
  }

}
