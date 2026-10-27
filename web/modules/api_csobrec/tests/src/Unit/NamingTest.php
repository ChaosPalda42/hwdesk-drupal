<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Unit;

use Drupal\api_csobrec\Service\Naming;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * File names inside a batch, copied from samples ČSOB accepts today.
 *
 * The asymmetry is the whole point: the MP3 name pads month and day, the batch
 * name does not. It looks like a bug and it is not — a "fix" would break the
 * hand-over, so it is nailed down here.
 */
#[Group('api_csobrec')]
final class NamingTest extends UnitTestCase {

  private function time(string $text): \DateTimeImmutable {
    return new \DateTimeImmutable($text);
  }

  public function testMp3NamePadsEveryPart(): void {
    $naming = new Naming();
    $this->assertSame(
      'DA_20260105080903_aktivita-1.mp3',
      $naming->mp3($this->time('2026-01-05 08:09:03'), 'aktivita-1'),
    );
    $this->assertSame(
      'DA_20261231235959_abc.mp3',
      $naming->mp3($this->time('2026-12-31 23:59:59'), 'abc'),
    );
  }

  public function testBatchNameLeavesMonthAndDayWithoutLeadingZero(): void {
    $naming = new Naming();
    $this->assertSame('DA_202615080903', $naming->batch($this->time('2026-01-05 08:09:03')));
    $this->assertSame('DA_20261231235959', $naming->batch($this->time('2026-12-31 23:59:59')));
    $this->assertSame('DA_20261020180000', $naming->batch($this->time('2026-10-20 18:00:00')));
  }

  public function testXmlAndDonShareTheBatchName(): void {
    $naming = new Naming();
    $time = $this->time('2026-10-26 05:00:01');
    $batch = $naming->batch($time);
    $this->assertSame($batch . '.xml', $naming->xml($time));
    $this->assertSame($batch . '.don', $naming->don($time));
  }

  public function testTimeIsNotShiftedByTheTimezoneOfTheSite(): void {
    // The hand-over names files by the call's own clock. A site running in
    // UTC must not rename a 23:30 call to 22:30 of the day before.
    $naming = new Naming();
    $time = new \DateTimeImmutable('2026-10-26 23:30:00', new \DateTimeZone('Europe/Prague'));
    $this->assertSame('DA_20261026233000_a.mp3', $naming->mp3($time, 'a'));
    $this->assertSame('DA_20261026233000', $naming->batch($time));
  }

}
