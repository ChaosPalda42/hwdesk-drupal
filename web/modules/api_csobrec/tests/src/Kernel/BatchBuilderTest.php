<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Kernel;

use Drupal\api_csobrec\Service\BatchBuilder;
use Drupal\api_csobrec\Service\IndexXml;
use Drupal\api_csobrec\Service\Naming;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_csobrec\Traits\ApiCsobrecFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Putting one batch together on disk.
 *
 * The .don file is ČSOB's marker for 'this batch is complete'. It is written
 * last and only when everything before it worked — that is the whole point of
 * this service, and the reason failures here must leave no marker behind.
 */
#[Group('api_csobrec')]
final class BatchBuilderTest extends KernelTestBase {

  use ApiCsobrecFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'key', 'api_csobrec'];

  private string $work;

  protected function setUp(): void {
    parent::setUp();
    $this->installApiCsobrec();
    $this->work = $this->container->get('file_system')->realpath($this->privateDir('api_csobrec/prac'));
  }

  private function builder(): BatchBuilder {
    return $this->container->get('api_csobrec.batch_builder');
  }

  private function time(): \DateTimeImmutable {
    return new \DateTimeImmutable('2026-10-26 08:09:03');
  }

  /**
   * A converted recording waiting in the working directory.
   */
  private function source(string $name, string $bytes): string {
    $path = $this->work . '/' . $name;
    file_put_contents($path, $bytes);
    return $path;
  }

  /**
   * @param array<string, string> $extra
   * @return array<string, string>
   */
  private function line(string $file, array $extra = []): array {
    return [
      'soubor' => $file,
      'prostredi' => 'D',
      'operator' => 'novakova',
      'id_kampane' => '5003',
      'telefon' => '603123456',
      'id_callcentra' => '159',
    ] + $extra;
  }

  /**
   * @return list<array{source: string, line: array<string, string>}>
   */
  private function twoItems(): array {
    return [
      ['source' => $this->source('a1.mp3', 'prvni-nahravka'), 'line' => $this->line('DA_20261026070101_a1.mp3')],
      ['source' => $this->source('a2.mp3', 'druha'), 'line' => $this->line('DA_20261026075500_a2.mp3')],
    ];
  }

  private function dir(): string {
    return 'private://api_csobrec/davky/DA_2026102680903';
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

  public function testTheBatchHoldsTheRecordingsTheIndexAndTheMarker(): void {
    $result = $this->builder()->build($this->dir(), $this->time(), $this->twoItems());

    $naming = new Naming();
    $this->assertSame([
      'DA_20261026070101_a1.mp3',
      'DA_20261026075500_a2.mp3',
      $naming->don($this->time()),
      $naming->xml($this->time()),
    ], $this->contents($this->dir()));

    $this->assertSame($this->dir(), $result['directory']);
    $this->assertSame(2, $result['recordings']);
    $this->assertSame($this->dir() . '/' . $naming->xml($this->time()), $result['xml']);
    $this->assertSame($this->dir() . '/' . $naming->don($this->time()), $result['don']);
  }

  public function testTheRecordingsKeepTheirBytesUnderTheFinalName(): void {
    $this->builder()->build($this->dir(), $this->time(), $this->twoItems());
    $this->assertSame('prvni-nahravka', (string) file_get_contents($this->dir() . '/DA_20261026070101_a1.mp3'));
    $this->assertSame('druha', (string) file_get_contents($this->dir() . '/DA_20261026075500_a2.mp3'));
  }

  public function testTheIndexIsExactlyWhatTheIndexServiceProduces(): void {
    $items = $this->twoItems();
    $this->builder()->build($this->dir(), $this->time(), $items);
    $expected = (new IndexXml())->build(array_column($items, 'line'));
    $this->assertSame($expected, (string) file_get_contents($this->dir() . '/' . (new Naming())->xml($this->time())));
  }

  public function testTheMarkerIsEmpty(): void {
    $this->builder()->build($this->dir(), $this->time(), $this->twoItems());
    $don = $this->dir() . '/' . (new Naming())->don($this->time());
    $this->assertFileExists($this->container->get('file_system')->realpath($don));
    $this->assertSame('', (string) file_get_contents($don));
  }

  public function testTheReportedSizeIsEverythingThatIsInTheFolder(): void {
    $result = $this->builder()->build($this->dir(), $this->time(), $this->twoItems());
    $real = $this->container->get('file_system')->realpath($this->dir());
    $sum = 0;
    foreach ($this->contents($this->dir()) as $name) {
      $sum += (int) filesize($real . '/' . $name);
    }
    $this->assertSame($sum, $result['bytes']);
    $this->assertGreaterThan(0, $result['bytes']);
  }

  public function testTheDirectoryIsCreatedWhenItIsNotThereYet(): void {
    $this->assertSame([], $this->contents($this->dir()));
    $this->builder()->build($this->dir(), $this->time(), $this->twoItems());
    $this->assertCount(4, $this->contents($this->dir()));
  }

  public function testBuildingTheSameBatchTwiceLeavesTheSameBytes(): void {
    $first = $this->builder()->build($this->dir(), $this->time(), $this->twoItems());
    $xml = (string) file_get_contents($first['xml']);
    $second = $this->builder()->build($this->dir(), $this->time(), $this->twoItems());
    $this->assertSame($xml, (string) file_get_contents($second['xml']));
    $this->assertSame($first['bytes'], $second['bytes']);
    $this->assertCount(4, $this->contents($this->dir()));
  }

  public function testAnEmptyBatchIsRefusedAndNothingIsWritten(): void {
    try {
      $this->builder()->build($this->dir(), $this->time(), []);
      $this->fail('An empty batch must be refused.');
    }
    catch (\InvalidArgumentException) {
      // Expected.
    }
    $this->assertSame([], $this->contents($this->dir()));
  }

  public function testAMissingRecordingStopsTheBatchBeforeAnythingIsCopied(): void {
    // All sources are checked first: half a batch on disk with no marker is
    // still a folder somebody has to clean up by hand.
    $items = $this->twoItems();
    $items[] = ['source' => $this->work . '/neni.mp3', 'line' => $this->line('DA_20261026080000_a3.mp3')];
    try {
      $this->builder()->build($this->dir(), $this->time(), $items);
      $this->fail('A missing recording must stop the batch.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('neni.mp3', $e->getMessage());
    }
    $this->assertSame([], $this->contents($this->dir()));
  }

  public function testABadIndexLineLeavesNoMarkerBehind(): void {
    $items = $this->twoItems();
    $items[1]['line']['vymyslene_pole'] = 'x';
    try {
      $this->builder()->build($this->dir(), $this->time(), $items);
      $this->fail('An unknown index field must stop the batch.');
    }
    catch (\InvalidArgumentException) {
      // Expected: comes from the index service.
    }
    // The recordings may already be copied; what must not exist is the marker
    // that tells ČSOB the folder is ready.
    $this->assertNotContains((new Naming())->don($this->time()), $this->contents($this->dir()));
    $this->assertNotContains((new Naming())->xml($this->time()), $this->contents($this->dir()));
  }

  public function testAnItemWithoutAFileNameIsRefused(): void {
    $items = $this->twoItems();
    unset($items[0]['line']['soubor']);
    $this->expectException(\InvalidArgumentException::class);
    $this->builder()->build($this->dir(), $this->time(), $items);
  }

  public function testAFileNameThatWouldLeaveTheFolderIsRefused(): void {
    // The name comes from Naming today, but this writes into private:// with
    // the site's own rights; a name with a path in it must never be joined on.
    foreach (['../uteceno.mp3', 'podslozka/a.mp3', '/etc/passwd', ''] as $bad) {
      $items = [['source' => $this->source('a1.mp3', 'x'), 'line' => $this->line($bad)]];
      try {
        $this->builder()->build($this->dir(), $this->time(), $items);
        $this->fail(sprintf('%s is not a usable file name.', $bad));
      }
      catch (\InvalidArgumentException) {
        // Expected.
      }
      $this->assertSame([], $this->contents($this->dir()));
    }
  }

  public function testTwoItemsWithTheSameFileNameAreRefused(): void {
    // Copying both would silently lose one recording while the index claims
    // two lines.
    $items = $this->twoItems();
    $items[1]['line']['soubor'] = $items[0]['line']['soubor'];
    $this->expectException(\InvalidArgumentException::class);
    $this->builder()->build($this->dir(), $this->time(), $items);
  }

}
