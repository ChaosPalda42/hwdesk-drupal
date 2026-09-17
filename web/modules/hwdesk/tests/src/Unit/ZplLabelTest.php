<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Unit;

use Drupal\hwdesk\Service\ZplLabel;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A 50x25 mm Zebra label: QR with the asset URL, tag and model as text.
 */
#[Group('hwdesk')]
final class ZplLabelTest extends UnitTestCase {

  private function zpl(string $tag = 'NB-0001', string $model = 'ThinkPad T14', string $url = 'https://intranet.example/a/NB-0001'): string {
    return (new ZplLabel())->build($tag, $model, $url);
  }

  public function testLabelIsOneZplFormatOfTheRightSize(): void {
    $zpl = $this->zpl();
    $this->assertStringStartsWith('^XA', $zpl);
    $this->assertStringEndsWith("^XZ\n", $zpl);
    $this->assertSame(1, substr_count($zpl, '^XA'));
    $this->assertSame(1, substr_count($zpl, '^XZ'));
    $this->assertStringContainsString('^PW400', $zpl);
    $this->assertStringContainsString('^LL200', $zpl);
    $this->assertStringContainsString('^CI28', $zpl);
  }

  public function testQrCarriesTheUrlAndTextCarriesTagAndModel(): void {
    $zpl = $this->zpl();
    $this->assertStringContainsString('^BQN,2,', $zpl);
    $this->assertStringContainsString('^FDQA,https://intranet.example/a/NB-0001^FS', $zpl);
    $this->assertStringContainsString('^FDNB-0001^FS', $zpl);
    $this->assertStringContainsString('^FDThinkPad T14^FS', $zpl);
  }

  public function testControlCharactersAreStrippedAndTextIsTruncated(): void {
    $zpl = $this->zpl('NB-0002', "Very^long~model name with control chars\x01 and more than thirty characters");
    $this->assertStringNotContainsString("\x01", $zpl);
    $this->assertStringContainsString('^FDVerylongmodel name with contro^FS', $zpl);
    $this->assertStringNotContainsString('^FDVery^long', $zpl);
  }

  public function testCopiesRepeatTheFormat(): void {
    $zpl = (new ZplLabel())->build('NB-0003', 'X', 'https://x/a/NB-0003', 3);
    $this->assertStringContainsString('^PQ3', $zpl);
    $this->assertSame(1, substr_count($zpl, '^XA'));
  }

  public function testCopiesBelowOneAreRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new ZplLabel())->build('NB-0003', 'X', 'https://x/a/NB-0003', 0);
  }

}
