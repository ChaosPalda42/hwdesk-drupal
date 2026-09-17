<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Invoice;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\Entity\Tag;
use Drupal\hwdesk\Service\IntakeService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Receiving goods: one form, N assets with generated tags, then serial numbers.
 */
#[Group('hwdesk')]
final class IntakeServiceTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  private function intake(): IntakeService {
    return $this->container->get('hwdesk.intake');
  }

  public function testCreateBatchMakesNAssetsWithGeneratedTags(): void {
    $location = Location::create(['name' => 'Sklad']);
    $location->save();
    $tag = Tag::create(['name' => 'Nové', 'color' => '#00aa00']);
    $tag->save();
    $invoice = Invoice::create(['number' => 'FV-2026-001', 'supplier' => 'Alza']);
    $invoice->save();

    $assets = $this->intake()->createBatch([
      'type' => 'notebook',
      'manufacturer' => 'Lenovo',
      'model' => 'ThinkPad T14',
      'condition' => 'new',
      'location' => (int) $location->id(),
      'tags' => [(int) $tag->id()],
      'invoice' => (int) $invoice->id(),
      'price' => '31990.00',
      'warranty_until' => '2028-01-31',
      'cost_center' => 'IT',
    ], 3);

    $this->assertCount(3, $assets);
    $this->assertSame(['NB-0001', 'NB-0002', 'NB-0003'], array_map(static fn(Asset $a): string => $a->getTag(), $assets));
    foreach ($assets as $asset) {
      $this->assertFalse($asset->isNew());
      $this->assertSame(AssetStatus::InStock, $asset->getStatus());
      $this->assertSame('Lenovo ThinkPad T14', $asset->getDisplayName());
      $this->assertSame('new', $asset->getCondition()->value);
      $this->assertSame('Sklad', $asset->getLocation()?->getName());
      $this->assertSame('FV-2026-001', $asset->getInvoice()?->getNumber());
      $this->assertSame(['Nové'], array_map(static fn(Tag $t): string => $t->getName(), $asset->getTags()));
      $this->assertSame('31990.00', $asset->get('price')->value);
      $this->assertSame('2028-01-31', $asset->get('warranty_until')->value);
      $this->assertSame('IT', $asset->get('cost_center')->value);
      $this->assertNull($asset->getHolder());
      $this->assertSame('', $asset->getSerialNumber());
    }
    $this->assertCount(3, Asset::loadMultiple());
  }

  public function testCreateBatchLeavesOptionalReferencesEmpty(): void {
    $assets = $this->intake()->createBatch(['type' => 'phone', 'model' => 'Pixel 9'], 1);
    $this->assertSame('TEL-0001', $assets[0]->getTag());
    $this->assertNull($assets[0]->getLocation());
    $this->assertNull($assets[0]->getInvoice());
    $this->assertSame([], $assets[0]->getTags());
    $this->assertSame('', $assets[0]->getManufacturer());
  }

  public function testCreateBatchRejectsBadCounts(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->intake()->createBatch(['type' => 'phone', 'model' => 'X'], 0);
  }

  public function testCreateBatchRejectsMoreThanFiveHundred(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->intake()->createBatch(['type' => 'phone', 'model' => 'X'], 501);
  }

  public function testCreateBatchRequiresTypeAndModel(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->intake()->createBatch(['type' => 'phone'], 1);
  }

  public function testSetSerialNumbersWritesAndSkipsBlanks(): void {
    [$a, $b, $c] = $this->intake()->createBatch(['type' => 'monitor', 'model' => 'U2723'], 3);
    $this->intake()->setSerialNumbers([(int) $a->id() => 'SN-A', (int) $b->id() => '  SN-B ', (int) $c->id() => '']);
    $this->assertSame('SN-A', Asset::load($a->id())->getSerialNumber());
    $this->assertSame('SN-B', Asset::load($b->id())->getSerialNumber());
    $this->assertSame('', Asset::load($c->id())->getSerialNumber());
  }

  public function testSetSerialNumbersIsAllOrNothingOnDuplicates(): void {
    $this->makeAsset('NB-0099', ['serial_number' => 'TAKEN']);
    [$a, $b] = $this->intake()->createBatch(['type' => 'monitor', 'model' => 'U2723'], 2);
    try {
      $this->intake()->setSerialNumbers([(int) $a->id() => 'FRESH', (int) $b->id() => 'TAKEN']);
      $this->fail('duplicate serial number accepted');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('TAKEN', $e->getMessage());
    }
    $this->assertSame('', Asset::load($a->id())->getSerialNumber(), 'nothing was written');
  }

  public function testSetSerialNumbersRejectsDuplicatesWithinTheBatch(): void {
    [$a, $b] = $this->intake()->createBatch(['type' => 'monitor', 'model' => 'U2723'], 2);
    $this->expectException(\InvalidArgumentException::class);
    $this->intake()->setSerialNumbers([(int) $a->id() => 'SAME', (int) $b->id() => 'same']);
  }

  public function testSetSerialNumbersRejectsUnknownAssets(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->intake()->setSerialNumbers([999 => 'X']);
  }

}
