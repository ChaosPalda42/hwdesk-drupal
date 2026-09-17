<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\AuditEntry;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\Entity\Tag;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The entity types install, every table they create starts with hwdesk_,
 * and an asset round-trips with its references and audit rows.
 */
#[Group('hwdesk')]
final class EntitySchemaTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    foreach (['hwdesk_asset', 'hwdesk_invoice', 'hwdesk_tag', 'hwdesk_location', 'hwdesk_handover', 'hwdesk_audit'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['hwdesk']);
  }

  public function testEveryTableStartsWithThePrefix(): void {
    $schema = $this->container->get('database')->schema();
    $expected = ['hwdesk_asset', 'hwdesk_asset_revision', 'hwdesk_asset__tags', 'hwdesk_asset__attachments', 'hwdesk_invoice', 'hwdesk_tag', 'hwdesk_location', 'hwdesk_handover', 'hwdesk_audit'];
    foreach ($expected as $table) {
      $this->assertTrue($schema->tableExists($table), "table $table exists");
    }
    $tables = $schema->findTables('%');
    $ours = array_filter($tables, static fn(string $t): bool => str_contains($t, 'asset') || str_contains($t, 'invoice') || str_contains($t, 'handover') || str_contains($t, 'audit'));
    foreach ($ours as $table) {
      $this->assertStringStartsWith('hwdesk_', $table);
    }
  }

  public function testAssetRoundTripsWithReferencesAndAudit(): void {
    $tag = Tag::create(['name' => 'Firemní', 'color' => '#2a9d8f']);
    $tag->save();
    $location = Location::create(['name' => 'Sklad Praha']);
    $location->save();

    $asset = Asset::create([
      'tag' => 'nb-0001',
      'type' => 'notebook',
      'manufacturer' => 'Lenovo',
      'model' => 'ThinkPad T14',
      'serial_number' => 'PF1ABC',
      'location' => $location->id(),
      'tags' => [$tag->id()],
      'warranty_until' => '2028-01-31',
      'price' => '31990.00',
    ]);
    $violations = $asset->validate();
    $this->assertCount(0, $violations, (string) $violations);
    $asset->save();

    $loaded = Asset::load($asset->id());
    $this->assertInstanceOf(Asset::class, $loaded);
    $this->assertSame('NB-0001', $loaded->getTag(), 'tags are stored upper-case');
    $this->assertSame(AssetStatus::InStock, $loaded->getStatus());
    $this->assertSame('Lenovo ThinkPad T14', $loaded->getDisplayName());
    $this->assertSame('Sklad Praha', $loaded->getLocation()?->getName());
    $this->assertSame(['Firemní'], array_map(static fn(Tag $t): string => $t->getName(), $loaded->getTags()));
    $this->assertNull($loaded->getHolder());

    $duplicate = Asset::create(['tag' => 'NB-0001', 'type' => 'notebook', 'model' => 'X']);
    $this->assertGreaterThan(0, count($duplicate->validate()), 'the inventory tag is unique');

    $loaded->setStatus(AssetStatus::Retired)->set('notes', 'rozbité víko')->save();
    /** @var \Drupal\hwdesk\Entity\AuditEntry[] $audit */
    $audit = $this->container->get('entity_type.manager')->getStorage('hwdesk_audit')->loadMultiple();
    $messages = array_map(static fn(AuditEntry $row): string => (string) $row->get('message')->value, array_values($audit));
    $this->assertContains('insert', $messages);
    $this->assertTrue((bool) array_filter($messages, static fn(string $m): bool => str_contains($m, 'status: in_stock → retired') && str_contains($m, 'notes')), implode(' | ', $messages));
    $this->assertCount(2, array_filter($audit, static fn(AuditEntry $row): bool => $row->get('target_type')->value === 'hwdesk_asset'));
  }

}
