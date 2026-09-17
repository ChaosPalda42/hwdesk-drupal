<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\Service\LabelSheet;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Data for the printable label sheet: tag, name, QR (inline SVG) per asset.
 */
#[Group('hwdesk')]
final class LabelSheetTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  private function sheet(): LabelSheet {
    return $this->container->get('hwdesk.label_sheet');
  }

  public function testOneLabelPerAssetWithQrSvg(): void {
    $a = $this->makeAsset('NB-0001');
    $b = $this->makeAsset('TEL-0001', ['type' => 'phone', 'manufacturer' => 'Google', 'model' => 'Pixel 9']);
    $labels = $this->sheet()->labels([$a, $b], 'https://intranet.example');
    $this->assertCount(2, $labels);
    $this->assertSame('NB-0001', $labels[0]['tag']);
    $this->assertSame('Lenovo ThinkPad T14', $labels[0]['name']);
    $this->assertSame('https://intranet.example/a/NB-0001', $labels[0]['url']);
    $this->assertStringStartsWith('<svg', $labels[0]['qr']);
    $this->assertStringContainsString('</svg>', $labels[0]['qr']);
    $this->assertSame('https://intranet.example/a/TEL-0001', $labels[1]['url']);
    $this->assertNotSame($labels[0]['qr'], $labels[1]['qr']);
  }

  public function testBaseUrlTrailingSlashAndTagAreNormalised(): void {
    $a = $this->makeAsset('NB-0002');
    $labels = $this->sheet()->labels([$a], 'https://intranet.example/');
    $this->assertSame('https://intranet.example/a/NB-0002', $labels[0]['url']);
  }

  public function testEmptyInputGivesNoLabels(): void {
    $this->assertSame([], $this->sheet()->labels([], 'https://x'));
  }

}
