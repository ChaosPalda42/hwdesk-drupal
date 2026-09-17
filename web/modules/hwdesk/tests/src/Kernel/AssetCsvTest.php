<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Invoice;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\Entity\Tag;
use Drupal\hwdesk\Service\AssetCsv;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * CSV export (Excel-friendly: BOM, semicolons) and import (upsert by tag).
 */
#[Group('hwdesk')]
final class AssetCsvTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  private const HEADER = 'tag;type;manufacturer;model;serial_number;status;condition;holder;location;tags;invoice;price;warranty_until;cost_center;notes';

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  private function csv(): AssetCsv {
    return $this->container->get('hwdesk.asset_csv');
  }

  public function testExportWritesBomHeaderAndOneRowPerAsset(): void {
    $jana = $this->makeUser('jana@firma.cz');
    $location = Location::create(['name' => 'Brno']);
    $location->save();
    $t1 = Tag::create(['name' => 'Firemní']);
    $t1->save();
    $t2 = Tag::create(['name' => 'Home office']);
    $t2->save();
    $invoice = Invoice::create(['number' => 'FV-1']);
    $invoice->save();
    $this->makeAsset('NB-0001', [
      'serial_number' => 'S1', 'status' => AssetStatus::Assigned->value, 'condition' => 'good', 'holder' => $jana->id(),
      'location' => $location->id(), 'tags' => [$t1->id(), $t2->id()], 'invoice' => $invoice->id(), 'price' => '100.50',
      'warranty_until' => '2027-05-01', 'cost_center' => 'IT', 'notes' => "má; středník\na nový řádek",
    ]);
    $this->makeAsset('TEL-0001', ['type' => 'phone', 'manufacturer' => '', 'model' => 'Pixel 9']);

    $out = $this->csv()->export(Asset::loadMultiple());
    $this->assertStringStartsWith("\xEF\xBB\xBF", $out);
    $lines = explode("\r\n", rtrim(substr($out, 3), "\r\n"));
    $this->assertSame(self::HEADER, $lines[0]);
    $this->assertCount(3, $lines);
    $this->assertSame('NB-0001;notebook;Lenovo;ThinkPad T14;S1;assigned;good;jana@firma.cz;Brno;Firemní|Home office;FV-1;100.50;2027-05-01;IT;"má; středník' . "\n" . 'a nový řádek"', $lines[1]);
    $this->assertSame('TEL-0001;phone;;Pixel 9;;in_stock;new;;;;;;;;', $lines[2]);
  }

  public function testImportCreatesAndUpdatesByTag(): void {
    $jana = $this->makeUser('jana@firma.cz');
    $invoice = Invoice::create(['number' => 'FV-1']);
    $invoice->save();
    $this->makeAsset('NB-0001', ['model' => 'old model']);

    $report = $this->csv()->import(
      "\xEF\xBB\xBF" . self::HEADER . "\r\n"
      . "NB-0001;notebook;Lenovo;ThinkPad T16;S9;assigned;good;JANA@firma.cz;Brno;Firemní|Nové;FV-1;200;2027-05-01;IT;pozn\r\n"
      . ";phone;Google;Pixel 9;;in_stock;new;;;;;;;;\r\n"
      . "MON-0007;monitor;Dell;U2723;;in_stock;worn;;Brno;Firemní;;;;;\r\n"
    );
    $this->assertSame(['created' => 2, 'updated' => 1, 'errors' => []], $report);

    $assets = [];
    foreach (Asset::loadMultiple() as $asset) {
      $assets[$asset->getTag()] = $asset;
    }
    ksort($assets);
    $this->assertSame(['MON-0007', 'NB-0001', 'TEL-0001'], array_keys($assets));
    $nb = $assets['NB-0001'];
    $this->assertSame('ThinkPad T16', $nb->getModel());
    $this->assertSame('S9', $nb->getSerialNumber());
    $this->assertSame(AssetStatus::Assigned, $nb->getStatus());
    $this->assertSame((int) $jana->id(), (int) $nb->getHolder()?->id(), 'holder matched by e-mail, case-insensitively');
    $this->assertSame('Brno', $nb->getLocation()?->getName(), 'a missing location is created by name');
    $this->assertSame(['Firemní', 'Nové'], array_map(static fn(Tag $t): string => $t->getName(), $nb->getTags()));
    $this->assertSame('FV-1', $nb->getInvoice()?->getNumber());
    $this->assertSame('200.00', $nb->get('price')->value);
    $this->assertSame('2027-05-01', $nb->get('warranty_until')->value);
    $this->assertSame('pozn', $nb->get('notes')->value);
    $this->assertSame('TEL-0001', $assets['TEL-0001']->getTag(), 'an empty tag is generated from the type');
    $this->assertSame('worn', $assets['MON-0007']->getCondition()->value);
    $this->assertCount(1, Location::loadMultiple(), 'Brno was created once');
    $this->assertCount(2, Tag::loadMultiple());
    $this->assertSame('MON-0008', $this->container->get('hwdesk.tag_generator')->peek('monitor'), 'a manual tag reserves the sequence');
  }

  public function testImportReportsBadRowsAndSkipsThem(): void {
    $report = $this->csv()->import(
      self::HEADER . "\n"
      . "NB-0001;notebook;;T14;;flying;new;;;;;;;;\n"
      . "NB-0002;notebook;;T14;;in_stock;new;nobody@firma.cz;;;;;;;\n"
      . "NB-0003;notebook;;T14;;in_stock;new;;;;FV-404;;;;\n"
      . "NB-0004;toaster;;T14;;in_stock;new;;;;;;;;\n"
      . "NB-0005;notebook;;;;in_stock;new;;;;;;;;\n"
      . "NB-0006;notebook;;T14;;in_stock;new;;;;;;;;\n"
    );
    $this->assertSame(1, $report['created']);
    $this->assertSame(0, $report['updated']);
    $this->assertCount(5, $report['errors']);
    foreach ([2 => 'flying', 3 => 'nobody@firma.cz', 4 => 'FV-404', 5 => 'toaster', 6 => 'model'] as $line => $needle) {
      $this->assertTrue((bool) array_filter($report['errors'], static fn(string $e): bool => str_starts_with($e, "řádek $line:") && str_contains($e, $needle)), implode(' | ', $report['errors']));
    }
    $this->assertCount(1, Asset::loadMultiple());
  }

  public function testImportRejectsAWrongHeader(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->csv()->import("tag;model\nNB-1;X\n");
  }

}
