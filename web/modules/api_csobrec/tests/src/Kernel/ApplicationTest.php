<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Kernel;

use Drupal\api_csobrec\Service\Application;
use Drupal\api_csobrec\Service\Mp3Converter;
use Drupal\api_csobrec\Service\Naming;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_csobrec\Fake\FakeCredentials;
use Drupal\Tests\api_csobrec\Fake\FakeDaktela;
use Drupal\Tests\api_csobrec\Fake\FakeSftp;
use Drupal\Tests\api_csobrec\Traits\ApiCsobrecFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The morning run as a whole: build, upload, write it down, clean up.
 *
 * The order of the last three is the point. The batch is uploaded BEFORE the
 * ledger is written, so a failed transfer leaves the ledger untouched and the
 * next run tries again; the downloaded recordings are deleted only after ČSOB
 * has them; and a day that already went out is not sent a second time, because
 * their importer refuses a second batch for the same day.
 */
#[Group('api_csobrec')]
final class ApplicationTest extends KernelTestBase {

  use ApiCsobrecFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'key', 'api_csobrec'];

  private FakeDaktela $daktela;

  private FakeSftp $sftp;

  protected function setUp(): void {
    parent::setUp();
    $this->installApiCsobrec();
    // What the module reads the token and the addresses from is
    // KeyCredentialsTest's business; here they are simply handed over.
    $this->container->set('api_csobrec.credentials', new FakeCredentials());
    $this->config('api_csobrec.settings')
      ->set('fronty', ['5003' => 'VSE'])
      ->set('sftp_cil', '/upload/nahravky')
      ->set('davky_dni', 30)
      ->save();

    $this->daktela = new FakeDaktela();
    $this->container->set('http_client', $this->daktela->client());
    $this->sftp = new FakeSftp();
    $this->container->set('api_csobrec.sftp_sessions', $this->sftp);
    $this->container->set('api_csobrec.mp3_converter', new Mp3Converter(
      $this->container->get('config.factory'),
      static function (array $command): array {
        copy((string) $command[(int) array_search('-i', $command, TRUE) + 1], (string) $command[count($command) - 1]);
        return [0, ''];
      },
    ));
  }

  private function app(): Application {
    return $this->container->get('api_csobrec.application');
  }

  private function day(string $iso = '2026-10-26'): \DateTimeImmutable {
    return new \DateTimeImmutable($iso . ' 00:00:00');
  }

  private function batchTime(): \DateTimeImmutable {
    return new \DateTimeImmutable('2026-10-27 05:00:00');
  }

  private function twoCalls(): void {
    $this->daktela->addCall('5003', 'a1', '2026-10-26 08:00:00', '420777111111');
    $this->daktela->addCall('5003', 'a2', '2026-10-26 09:00:00', '420777222222');
  }

  /**
   * @return array<string, mixed>
   */
  private function morning(bool $upload = TRUE, bool $force = FALSE, ?\DateTimeImmutable $batchTime = NULL): array {
    return $this->app()->run($this->day(), $upload, $force, $batchTime ?? $this->batchTime());
  }

  private function batchName(): string {
    return (new Naming())->batch($this->batchTime());
  }

  public function testTheWholeBatchArrivesWithTheMarkerLast(): void {
    $this->twoCalls();
    $result = $this->morning();

    $this->assertSame(1, $this->sftp->opened);
    $this->assertSame([
      '/upload/nahravky/DA_20261026080000_a1.mp3',
      '/upload/nahravky/DA_20261026090000_a2.mp3',
      '/upload/nahravky/' . $this->batchName() . '.xml',
      '/upload/nahravky/' . $this->batchName() . '.don',
    ], $this->sftp->uploaded);
    $this->assertSame(4, $result['uploaded']);
    $this->assertSame('', $this->sftp->bytes['/upload/nahravky/' . $this->batchName() . '.don']);
  }

  public function testWhatWentOutIsWrittenDownUnderTheBatchName(): void {
    $this->twoCalls();
    $this->morning();

    $ledger = $this->container->get('api_csobrec.sent_ledger');
    $this->assertTrue($ledger->wasSent('a1'));
    $this->assertTrue($ledger->wasSent('a2'));
    $this->assertSame(['hovoru' => 2, 'davek' => 1], $ledger->counts());

    $batch = $ledger->lastBatchOfDay($this->day());
    $this->assertNotNull($batch);
    // The name in the ledger is the name ČSOB sees in the index file, not the
    // folder the run worked in.
    $this->assertSame($this->batchName(), $batch['jmeno']);
    $this->assertSame('odeslano', $batch['stav']);
    $this->assertSame(2, (int) $batch['hovoru']);
    $this->assertSame(4, (int) $batch['nahrano']);
    $this->assertGreaterThan(0, (int) $batch['bajtu']);
    $this->assertNull($batch['chyba']);
    $this->assertSame('2026-10-26', $batch['den']);
  }

  public function testTheDownloadedRecordingsAreDeletedOnceCsobHasThem(): void {
    // Customer audio on our disk is a liability; the batch folder stays as the
    // proof of what was handed over.
    $this->twoCalls();
    $this->morning();
    $this->assertSame([], $this->contents('private://api_csobrec/prac/2026-10-26'));
    $this->assertNotEmpty($this->contents('private://api_csobrec/davky/2026-10-26'));
  }

  public function testADayThatAlreadyWentOutIsNotSentAgain(): void {
    $this->twoCalls();
    $this->morning();
    $this->sftp->uploaded = [];

    $again = $this->morning();
    $this->assertTrue($again['skipped']);
    $this->assertSame($this->batchName(), $again['batch']);
    $this->assertSame([], $this->sftp->uploaded);
    $this->assertSame(1, $this->sftp->opened, 'No second connection was opened.');
    $this->assertSame(['hovoru' => 2, 'davek' => 1], $this->container->get('api_csobrec.sent_ledger')->counts());
  }

  public function testTheSameDayCanBeForcedAndLandsInItsOwnFolder(): void {
    // The repair tool: a second batch of the same day, with its own timestamp,
    // so two indexes never share a folder.
    $this->twoCalls();
    $this->morning();
    // A call that arrived late, after the morning batch had gone.
    $this->daktela->addCall('5003', 'a3', '2026-10-26 23:30:00', '420777333333');
    $result = $this->morning(force: TRUE, batchTime: $this->batchTime()->modify('+1 hour'));

    $this->assertArrayNotHasKey('skipped', $result);
    $this->assertSame(['050000', '060000'], $this->contents('private://api_csobrec/davky/2026-10-26'));
    // The two that already went out are left alone; only a3 is new.
    $this->assertSame(2, $result['alreadySent']);
    $this->assertSame(1, $result['inBatch']);
    $this->assertSame(['DA_20261026233000_a3.mp3'], array_values(array_filter(
      $this->contents('private://api_csobrec/davky/2026-10-26/060000'),
      static fn (string $name): bool => str_ends_with($name, '.mp3'),
    )));
  }

  public function testAFailedTransferLeavesTheLedgerUntouchedSoTomorrowRetries(): void {
    $this->twoCalls();
    $this->sftp->uploadFails = TRUE;
    try {
      $this->morning();
      $this->fail('A failed transfer must be reported, not swallowed.');
    }
    catch (\RuntimeException) {
      // Expected.
    }

    $ledger = $this->container->get('api_csobrec.sent_ledger');
    $this->assertFalse($ledger->wasSent('a1'));
    $this->assertSame(['hovoru' => 0, 'davek' => 1], $ledger->counts());
    $batch = $ledger->lastBatchOfDay($this->day());
    $this->assertSame('chyba', $batch['stav']);
    $this->assertNotNull($batch['chyba']);
    // The working files stay: that is what the diagnosis is done from.
    $this->assertNotEmpty($this->contents('private://api_csobrec/prac/2026-10-26'));
  }

  public function testALoginThatFailsSendsNothingAndIsWrittenDown(): void {
    $this->twoCalls();
    $this->sftp->openFails = TRUE;
    try {
      $this->morning();
      $this->fail('A refused login must be reported.');
    }
    catch (\RuntimeException) {
      // Expected.
    }
    $this->assertSame([], $this->sftp->uploaded);
    $this->assertSame('chyba', $this->container->get('api_csobrec.sent_ledger')->lastBatchOfDay($this->day())['stav']);
    $this->assertFalse($this->container->get('api_csobrec.sent_ledger')->wasSent('a1'));
  }

  public function testAFailedDayCanBeRunAgainWithoutForcing(): void {
    // Only a batch that actually went out blocks the day; a failed one must
    // not lock the morning out for good.
    $this->twoCalls();
    $this->sftp->uploadFails = TRUE;
    try {
      $this->morning();
    }
    catch (\RuntimeException) {
      // Expected.
    }
    $this->sftp->uploadFails = FALSE;
    $result = $this->morning(batchTime: $this->batchTime()->modify('+1 hour'));
    $this->assertArrayNotHasKey('skipped', $result);
    $this->assertSame(4, $result['uploaded']);
    $this->assertTrue($this->container->get('api_csobrec.sent_ledger')->wasSent('a1'));
  }

  public function testATestRunBuildsTheBatchAndUploadsNothing(): void {
    $this->twoCalls();
    $result = $this->morning(upload: FALSE);

    $this->assertSame(0, $result['uploaded']);
    $this->assertSame(0, $this->sftp->opened);
    $this->assertSame([], $this->sftp->uploaded);
    // Nothing was handed over, so nothing is written down as handed over.
    $this->assertFalse($this->container->get('api_csobrec.sent_ledger')->wasSent('a1'));
    // But the run is visible in the overview, and not as a success.
    $batch = $this->container->get('api_csobrec.sent_ledger')->lastBatchOfDay($this->day());
    $this->assertSame('preskoceno', $batch['stav']);
    // The batch itself is on disk to be looked at.
    $this->assertContains($this->batchName() . '.don', $this->contents('private://api_csobrec/davky/2026-10-26/050000'));
  }

  public function testATestRunDoesNotBlockTheRealOne(): void {
    $this->twoCalls();
    $this->morning(upload: FALSE);
    $result = $this->morning(batchTime: $this->batchTime()->modify('+1 hour'));
    $this->assertArrayNotHasKey('skipped', $result);
    $this->assertSame(4, $result['uploaded']);
  }

  public function testADayWithNothingToSendIsWrittenDownAsSkipped(): void {
    // A quiet day and a broken run must not look the same in the overview.
    $result = $this->morning();
    $this->assertSame(0, $result['uploaded']);
    $this->assertSame([], $this->sftp->uploaded);
    $this->assertSame(0, $this->sftp->opened);
    $batch = $this->container->get('api_csobrec.sent_ledger')->lastBatchOfDay($this->day());
    $this->assertNotNull($batch);
    $this->assertSame('preskoceno', $batch['stav']);
    $this->assertSame(0, (int) $batch['hovoru']);
  }

  public function testAnEmptyDayDoesNotBlockTheNextAttempt(): void {
    $this->morning();
    $this->twoCalls();
    $result = $this->morning(batchTime: $this->batchTime()->modify('+1 hour'));
    $this->assertArrayNotHasKey('skipped', $result);
    $this->assertSame(4, $result['uploaded']);
  }

  public function testTheCountsOfTheRunAreJustNumbers(): void {
    $this->twoCalls();
    $result = $this->morning();
    unset($result['batch']);
    foreach ($result as $key => $value) {
      $this->assertIsInt($value, sprintf('%s is not a count.', $key));
    }
  }

  // --- clearing old batches off the disk -----------------------------------

  public function testOldBatchesAndLeftoverRecordingsAreDeleted(): void {
    $fs = $this->container->get('file_system');
    foreach (['2026-08-01', '2026-09-27', '2026-10-26'] as $den) {
      foreach (['api_csobrec/davky/' . $den . '/050000', 'api_csobrec/prac/' . $den . '/050000'] as $dir) {
        $uri = $this->privateDir($dir);
        file_put_contents($uri . '/neco.mp3', 'x');
      }
    }
    $removed = $this->app()->prune(new \DateTimeImmutable('2026-10-27 06:00:00'));

    // 30 days back from 27 Oct 2026 is 27 Sep: August goes, September stays.
    $this->assertSame(2, $removed, 'One batch day and one working day.');
    $this->assertSame([], $this->contents('private://api_csobrec/davky/2026-08-01'));
    $this->assertSame([], $this->contents('private://api_csobrec/prac/2026-08-01'));
    $this->assertNotEmpty($this->contents('private://api_csobrec/davky/2026-09-27'));
    $this->assertNotEmpty($this->contents('private://api_csobrec/davky/2026-10-26'));
  }

  public function testTheLedgerIsNeverPruned(): void {
    // The disk is cleared, the record of what ČSOB has is forever.
    $this->twoCalls();
    $this->morning();
    $this->app()->prune(new \DateTimeImmutable('2030-01-01 06:00:00'));
    $this->assertTrue($this->container->get('api_csobrec.sent_ledger')->wasSent('a1'));
    $this->assertSame(['hovoru' => 2, 'davek' => 1], $this->container->get('api_csobrec.sent_ledger')->counts());
  }

  public function testPruningIsOffWhenTheSettingIsZero(): void {
    $this->config('api_csobrec.settings')->set('davky_dni', 0)->save();
    $uri = $this->privateDir('api_csobrec/davky/2020-01-01/050000');
    file_put_contents($uri . '/neco.mp3', 'x');
    $this->assertSame(0, $this->app()->prune(new \DateTimeImmutable('2030-01-01 06:00:00')));
    $this->assertNotEmpty($this->contents('private://api_csobrec/davky/2020-01-01'));
  }

  public function testSomethingThatIsNotADayFolderIsLeftAlone(): void {
    $uri = $this->privateDir('api_csobrec/davky/poznamky');
    file_put_contents($uri . '/precti-me.txt', 'x');
    $this->assertSame(0, $this->app()->prune(new \DateTimeImmutable('2030-01-01 06:00:00')));
    $this->assertNotEmpty($this->contents('private://api_csobrec/davky/poznamky'));
  }

  /**
   * @return list<string>
   */
  private function contents(string $uri): array {
    $real = $this->container->get('file_system')->realpath($uri);
    if ($real === FALSE || !is_dir($real)) {
      return [];
    }
    $found = array_values(array_diff(scandir($real) ?: [], ['.', '..']));
    sort($found);
    return $found;
  }

}
