<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Kernel;

use Drupal\api_csobrec\Service\DayRun;
use Drupal\api_csobrec\Service\Mp3Converter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_csobrec\Fake\FakeCredentials;
use Drupal\Tests\api_csobrec\Fake\FakeDaktela;
use Drupal\Tests\api_csobrec\Traits\ApiCsobrecFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * One day, from Daktela's activities to a finished batch on disk.
 *
 * Only the edges are faked: the HTTP answers, ffmpeg and the clock. Selection,
 * naming, the index and the ledger are the real services, so what this test
 * checks is the day as it will actually run — including the two rules that
 * came from Michael on 27 Oct 2026: a LOGIKA queue pulls the earlier history
 * of the same number, and a recording ČSOB already has is never fetched again.
 */
#[Group('api_csobrec')]
final class DayRunTest extends KernelTestBase {

  use ApiCsobrecFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'key', 'api_csobrec'];

  private FakeDaktela $daktela;

  private string $work;

  /** @var list<list<string>> */
  private array $converted = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installApiCsobrec();
    // What the module reads the token and the addresses from is
    // KeyCredentialsTest's business; here they are simply handed over.
    $this->container->set('api_csobrec.credentials', new FakeCredentials());
    $this->config('api_csobrec.settings')
      ->set('fronty', ['5003' => 'VSE', '5004' => 'LOGIKA'])
      ->save();

    $this->daktela = new FakeDaktela();
    $this->container->set('http_client', $this->daktela->client());

    // ffmpeg's job, in one line: whatever came in goes out. The command shape
    // itself is pinned by Mp3ConverterTest; here we only need real bytes.
    $this->container->set('api_csobrec.mp3_converter', new Mp3Converter(
      $this->container->get('config.factory'),
      function (array $command): array {
        $this->converted[] = $command;
        $source = (string) $command[(int) array_search('-i', $command, TRUE) + 1];
        copy($source, (string) $command[count($command) - 1]);
        return [0, ''];
      },
    ));

    $this->work = (string) $this->container->get('file_system')->realpath($this->privateDir('api_csobrec/prac/zkouska'));
  }

  private function dayRun(): DayRun {
    return $this->container->get('api_csobrec.day_run');
  }

  private function day(): \DateTimeImmutable {
    return new \DateTimeImmutable('2026-10-26 00:00:00');
  }

  private function batchTime(): \DateTimeImmutable {
    return new \DateTimeImmutable('2026-10-27 05:00:00');
  }

  private function dir(): string {
    return 'private://api_csobrec/davky/zkouska';
  }

  /**
   * @return array<string, mixed>
   */
  private function build(): array {
    return $this->dayRun()->build($this->day(), $this->batchTime(), $this->dir(), $this->work);
  }

  /**
   * The day as the fixture holds it; see the counts in the first test.
   */
  private function wholeDay(): void {
    // Queue 5003, mode VSE: everything goes.
    $this->daktela->addCall('5003', 'a1', '2026-10-26 08:00:00', '420777111111');
    $this->daktela->addCall('5003', 'a2', '2026-10-26 09:00:00', '420777222222', [
      'statuses' => [['title' => '[CSOB] Nový prodej']],
      'record' => ['customFields' => ['csob_cislo_navrhu_ps' => ['6100000001']]],
    ]);
    // No recording: ČSOB gets a line for nothing, so the call drops out.
    $this->daktela->addCall('5003', 'a3', '2026-10-26 10:00:00', '420777333333', audio: NULL);
    // Something the mapper cannot read; a live instance has those. The time is
    // there (Daktela always sends it, and the day filter needs it); the queue is
    // missing, and that is what the mapper refuses.
    $this->daktela->addRawCall('5003', ['name' => 'a4', 'time' => '2026-10-26 12:00:00']);

    // Queue 5004, mode LOGIKA: only a trigger and the history behind it.
    $this->daktela->addCall('5004', 'b1', '2026-10-26 08:30:00', '420777444444');
    $this->daktela->addCall('5004', 'b2', '2026-10-26 11:00:00', '420777555555', [
      'statuses' => [['title' => '[CSOB] Nový prodej TZ']],
      'record' => ['customFields' => [
        'csob_cislo_navrhu_ps' => ['6100000777'],
        '_csobp__vyuzit_x_sell_benefit' => ['ANO'],
        '_csobp__x_sell___cislo_navrhu_ps' => ['6100000999'],
      ]],
    ]);
    // The history of 777555555 on that queue: earlier counts, later does not.
    $this->daktela->addCall('5004', 'b0', '2026-10-20 09:00:00', '420777555555');
    $this->daktela->addCall('5004', 'b9', '2026-11-01 09:00:00', '420777555555');
  }

  public function testTheWholeDayInCounts(): void {
    $this->wholeDay();
    $result = $this->build();

    // a1, a2, a3, b1, b2 from the day plus b0 and b9 from the history.
    $this->assertSame(7, $result['calls']);
    $this->assertSame(1, $result['unmappable'], 'a4 has no queue.');
    // VSE takes a1, a2, a3; LOGIKA takes the trigger b2 and the earlier b0.
    $this->assertSame(5, $result['selected']);
    $this->assertSame(1, $result['xsellCopies']);
    $this->assertSame(1, $result['withoutRecording'], 'a3 has none.');
    $this->assertSame(0, $result['failed']);
    $this->assertSame(0, $result['alreadySent']);
    // a1, a2, b0, b2 and the X-sell copy of b2.
    $this->assertSame(5, $result['inBatch']);
    $this->assertSame(1, $result['historyQueries']);
    $this->assertSame(2, $result['historyCalls']);
  }

  public function testTheCountsCarryNoPhoneNumbersOrNames(): void {
    // The counts travel into the log and onto the operator's terminal.
    $this->wholeDay();
    $result = $this->build();
    unset($result['activities'], $result['batch']);
    foreach ($result as $key => $value) {
      $this->assertIsInt($value, sprintf('%s is not a count.', $key));
    }
  }

  public function testOneQuestionPerQueueAndOnePerNumberWithAHistory(): void {
    $this->wholeDay();
    $this->build();
    // Two queues, one history (only b2 is a trigger on a LOGIKA queue).
    $this->assertSame(3, $this->daktela->asked('/api/v6/activities.json'));
  }

  public function testOnlyTheRecordingsThatEndUpInTheBatchAreFetched(): void {
    $this->wholeDay();
    $this->build();
    // a3 has no recording, b1 and b9 were not selected, a4 is unreadable.
    $this->assertSame(['a1', 'a2', 'b0', 'b2'], $this->daktela->downloaded());
  }

  public function testTheBatchHoldsTheRecordingsTheIndexAndTheMarker(): void {
    $this->wholeDay();
    $result = $this->build();
    $this->assertNotNull($result['batch']);

    $names = $this->contents($this->dir());
    $this->assertContains('DA_20261026080000_a1.mp3', $names);
    $this->assertContains('DA_20261026090000_a2.mp3', $names);
    $this->assertContains('DA_20261020090000_b0.mp3', $names);
    $this->assertContains('DA_20261026110000_b2.mp3', $names);
    // The X-sell copy is the same audio under a second name.
    $this->assertContains('DA_20261026110000_b2_2.mp3', $names);
    $this->assertContains('DA_20261027050000.xml', $names);
    $this->assertContains('DA_20261027050000.don', $names);
    $this->assertCount(7, $names);

    $this->assertSame(
      (string) file_get_contents($this->dir() . '/DA_20261026110000_b2.mp3'),
      (string) file_get_contents($this->dir() . '/DA_20261026110000_b2_2.mp3'),
    );
  }

  public function testTheIndexCarriesTheRightPolicyNumberOnEachCopy(): void {
    $this->wholeDay();
    $this->build();
    $xml = (string) file_get_contents($this->dir() . '/DA_20261027050000.xml');

    // Copy one carries the call's own proposal number, copy two the X-sell one.
    $this->assertStringContainsString("<soubor>DA_20261026110000_b2.mp3</soubor>\r\n\t\t\t<prostredi>D</prostredi>", $xml);
    $this->assertSame(1, substr_count($xml, '<c_poj_sml>6100000777</c_poj_sml>'));
    $this->assertSame(1, substr_count($xml, '<c_poj_sml>6100000999</c_poj_sml>'));
    $this->assertSame(1, substr_count($xml, '<c_poj_sml>6100000001</c_poj_sml>'));
    // b0 has no proposal number at all, so its line has no such field.
    $this->assertSame(5, substr_count($xml, '<dokument>'));
    $this->assertSame(3, substr_count($xml, '<c_poj_sml>'));
    // The campaign number is the queue, and the call centre is fixed. Queue
    // 5004 holds three lines: b0, b2 and the X-sell copy of b2.
    $this->assertSame(2, substr_count($xml, '<id_kampane>5003</id_kampane>'));
    $this->assertSame(3, substr_count($xml, '<id_kampane>5004</id_kampane>'));
    $this->assertSame(5, substr_count($xml, '<id_callcentra>159</id_callcentra>'));
  }

  public function testTheActivitiesAreReportedForTheLedgerWithTheirQueue(): void {
    $this->wholeDay();
    $result = $this->build();
    $rows = $result['activities'];
    usort($rows, static fn (array $a, array $b): int => strcmp($a['hovor'], $b['hovor']));
    $this->assertSame([
      ['hovor' => 'a1', 'fronta' => '5003'],
      ['hovor' => 'a2', 'fronta' => '5003'],
      ['hovor' => 'b0', 'fronta' => '5004'],
      ['hovor' => 'b2', 'fronta' => '5004'],
    ], $rows, 'One row per recording in the batch, the X-sell copy is not a second call.');
  }

  public function testARecordingCsobAlreadyHasIsNotFetchedAgain(): void {
    $this->wholeDay();
    $this->container->get('api_csobrec.sent_ledger')->record('DA_davno', [['hovor' => 'a1', 'fronta' => '5003']]);
    $result = $this->build();

    $this->assertSame(1, $result['alreadySent']);
    $this->assertSame(4, $result['inBatch']);
    $this->assertNotContains('a1', $this->daktela->downloaded());
    $this->assertNotContains('DA_20261026080000_a1.mp3', $this->contents($this->dir()));
  }

  public function testAFailedDownloadIsCountedAndTheRestOfTheDayGoesOut(): void {
    // One broken recording must not cost us the whole morning.
    $this->wholeDay();
    $this->daktela->breakDownload('a2');
    $result = $this->build();

    $this->assertSame(1, $result['failed']);
    $this->assertSame(4, $result['inBatch']);
    $this->assertNotContains('DA_20261026090000_a2.mp3', $this->contents($this->dir()));
    $this->assertNotContains(['hovor' => 'a2', 'fronta' => '5003'], $result['activities']);
  }

  public function testADayWithNothingToSendMakesNoBatchAtAll(): void {
    // No folder, no index, no marker: ČSOB must not receive an empty batch.
    $result = $this->build();
    $this->assertSame(0, $result['calls']);
    $this->assertSame(0, $result['inBatch']);
    $this->assertNull($result['batch']);
    $this->assertSame([], $result['activities']);
    $this->assertSame([], $this->contents($this->dir()));
  }

  public function testADayWhereEverythingIsAlreadySentMakesNoBatch(): void {
    $this->daktela->addCall('5003', 'a1', '2026-10-26 08:00:00', '420777111111');
    $this->container->get('api_csobrec.sent_ledger')->record('DA_davno', [['hovor' => 'a1', 'fronta' => '5003']]);
    $result = $this->build();
    $this->assertSame(1, $result['alreadySent']);
    $this->assertNull($result['batch']);
    $this->assertSame([], $this->contents($this->dir()));
  }

  public function testAQueueThatIsNotInTheSettingsIsNeverAsked(): void {
    $this->wholeDay();
    $this->daktela->addCall('9999', 'x1', '2026-10-26 08:00:00', '420777999999');
    $this->build();
    foreach ($this->daktela->log as $request) {
      $this->assertNotSame('9999', $request['query']['filter[filters][3][value]'] ?? NULL);
    }
    $this->assertNotContains('x1', $this->daktela->downloaded());
  }

  public function testWithoutAnyQueueConfiguredNothingIsAsked(): void {
    $this->wholeDay();
    $this->config('api_csobrec.settings')->set('fronty', [])->save();
    $result = $this->build();
    $this->assertSame(0, $result['calls']);
    $this->assertSame([], $this->daktela->log);
  }

  public function testAnUnknownModeInTheSettingsIsRefusedBeforeAnythingIsAsked(): void {
    // A typo in the mode must not quietly mean 'send nothing' or 'send all'.
    $this->wholeDay();
    $this->config('api_csobrec.settings')->set('fronty', ['5003' => 'VSECHNO'])->save();
    $this->expectException(\InvalidArgumentException::class);
    $this->build();
  }

  public function testTheWorkingFilesAreRealFilesForFfmpegAndStayForTheCaller(): void {
    // ffmpeg cannot write into a stream wrapper, so the run works in plain
    // files; cleaning them up is the caller's job, not this service's.
    $this->wholeDay();
    $this->build();
    $left = array_values(array_diff(scandir($this->work) ?: [], ['.', '..']));
    sort($left);
    $this->assertSame(['a1.mp3', 'a1.opus', 'a2.mp3', 'a2.opus', 'b0.mp3', 'b0.opus', 'b2.mp3', 'b2.opus'], $left);
  }

  public function testTheConversionIsAskedForEveryDownloadedRecording(): void {
    $this->wholeDay();
    $this->build();
    $this->assertCount(4, $this->converted);
    foreach ($this->converted as $command) {
      $this->assertContains('libmp3lame', $command);
    }
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
