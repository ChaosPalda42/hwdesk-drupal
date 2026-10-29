<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Controller\AssetController;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Detail zařízení. Brána drží dvě věci: že stránka dostane přesně ty klíče,
 * které šablona kreslí, a že řádky jsou nezměněné od Presenteru — jinak by
 * detail psal data jinak než seznam.
 */
#[Group('hwdesk')]
final class AssetControllerTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  /**
   * @return array<string, mixed>
   */
  private function detail(Asset $asset): array {
    return AssetController::create($this->container)->detail($asset);
  }

  public function testStrankaMaPresneKliceProSablonu(): void {
    $asset = $this->makeAsset('NB-0001');
    $build = $this->detail($asset);

    $this->assertSame('hwdesk_asset', $build['#theme']);
    foreach (['#asset', '#handovers', '#history', '#files', '#status_form', '#handover_form', '#open_handover', '#links', '#cache'] as $key) {
      $this->assertArrayHasKey($key, $build);
    }
    $this->assertSame([], $build['#handovers']);
    // Vlozeni zarizeni si samo zapise do auditu radek 'insert' (hook_entity_insert),
    // takze historie je prazdna jen do prvniho ulozeni.
    $this->assertCount(1, $build['#history']);
    $this->assertSame('insert', $build['#history'][0]['action']);
    $this->assertNull($build['#open_handover']);
    $this->assertArrayNotHasKey('#type', $build);
    $this->assertContains('hwdesk_handover_list', $build['#cache']['tags']);
    $this->assertContains('hwdesk_audit_list', $build['#cache']['tags']);
    $this->assertContains('hwdesk_asset:' . $asset->id(), $build['#cache']['tags']);
    $this->assertSame(['user.permissions'], $build['#cache']['contexts']);
  }

  public function testOdkazyJsouHotoveAdresy(): void {
    $asset = $this->makeAsset('NB-0001', ['type' => 'notebook']);
    $links = $this->detail($asset)['#links'];

    foreach (['edit', 'label', 'assets', 'type'] as $key) {
      $this->assertArrayHasKey($key, $links);
      $this->assertIsString($links[$key]);
      $this->assertStringStartsWith('/', $links[$key]);
    }
    $this->assertStringContainsString((string) $asset->id(), $links['edit']);
    $this->assertStringContainsString('ids=' . $asset->id(), urldecode($links['label']));
    $this->assertStringContainsString('type=notebook', urldecode($links['type']));
  }

  public function testRadkyJsouNezmeneneOdPresenteru(): void {
    $jana = $this->makeUser('jana@firma.cz', 'Jana Dvořáková');
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $jana->id()]);
    $handover = $this->makeHandover($asset, $jana, ['status' => HandoverStatus::Confirmed->value, 'decided' => 1760000500, 'protocol_number' => '2026/0007']);
    $presenter = $this->container->get('hwdesk.presenter');
    $build = $this->detail($asset);

    // assertEquals, ne assertSame: v radcich jsou objekty TranslatableMarkup
    // (prelozene popisky stavu) a dve volani vyrobi dve rozdilne instance.
    $this->assertEquals($presenter->asset($asset), $build['#asset']);
    $this->assertEquals($presenter->handover($handover), $build['#handovers'][0]);
    $this->assertEquals($presenter->files('attachments', $asset), $build['#files']);
  }

  public function testOtevrenaVyzvaMaZruseniAPoRozhodnutiZmizi(): void {
    $jana = $this->makeUser('jana@firma.cz', 'Jana Dvořáková');
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::PendingHandover->value]);
    $handover = $this->makeHandover($asset, $jana);

    $open = $this->detail($asset)['#open_handover'];
    $this->assertNotNull($open);
    $this->assertSame((int) $handover->id(), $open['id']);
    $this->assertSame('/hwdesk/handover/' . $handover->id() . '/cancel', $open['cancel_url']);

    $handover->set('status', HandoverStatus::Confirmed->value)->save();
    $this->assertNull($this->detail($asset)['#open_handover']);
  }

  public function testProtokolyNejnovejsiPrvni(): void {
    $jana = $this->makeUser('jana@firma.cz', 'Jana Dvořáková');
    $asset = $this->makeAsset('NB-0001');
    $this->makeHandover($asset, $jana, ['created' => 1760000100, 'status' => HandoverStatus::Confirmed->value, 'protocol_number' => '2026/0001']);
    $this->makeHandover($asset, $jana, ['created' => 1760000300, 'kind' => HandoverKind::ReturnItem->value, 'status' => HandoverStatus::Confirmed->value, 'protocol_number' => '2026/0003']);
    $this->makeHandover($asset, $jana, ['created' => 1760000200, 'status' => HandoverStatus::Rejected->value, 'protocol_number' => '']);

    $cisla = array_column($this->detail($asset)['#handovers'], 'protocol_number');
    $this->assertSame(['2026/0003', '', '2026/0001'], $cisla);
  }

  public function testCizihoZarizeniSeProtokolyNetykaji(): void {
    $jana = $this->makeUser('jana@firma.cz', 'Jana Dvořáková');
    $moje = $this->makeAsset('NB-0001');
    $cizi = $this->makeAsset('NB-0002');
    $this->makeHandover($cizi, $jana, ['protocol_number' => '2026/0009']);

    $this->assertSame([], $this->detail($moje)['#handovers']);
  }

  public function testHistorieJeZAuditu(): void {
    $asset = $this->makeAsset('NB-0001');
    $cizi = $this->makeAsset('NB-0002');
    $storage = $this->container->get('entity_type.manager')->getStorage('hwdesk_audit');
    $zapis = function (Asset $target, int $created, string $message) use ($storage): void {
      $storage->create([
        'target_type' => 'hwdesk_asset',
        'target_id' => (int) $target->id(),
        'target_label' => $target->get('tag')->value,
        'action' => 'asset.changed',
        'message' => $message,
        'created' => $created,
      ])->save();
    };
    $zapis($asset, 1760000100, 'starsi');
    $zapis($asset, 1760000300, 'nejnovejsi');
    $zapis($cizi, 1760000400, 'jine zarizeni');

    $history = $this->detail($asset)['#history'];
    // 'insert' je automaticky radek od ulozeni zarizeni, ma cas ulozeni, tedy nejnovejsi.
    $this->assertSame(['insert', 'nejnovejsi', 'starsi'], array_column($history, 'message'));
    $this->assertNotContains('jine zarizeni', array_column($history, 'message'));
  }

  public function testHistorieJeStostrankova(): void {
    $asset = $this->makeAsset('NB-0001');
    $storage = $this->container->get('entity_type.manager')->getStorage('hwdesk_audit');
    for ($i = 0; $i < 102; $i++) {
      $storage->create([
        'target_type' => 'hwdesk_asset',
        'target_id' => (int) $asset->id(),
        'target_label' => 'NB-0001',
        'action' => 'asset.changed',
        'message' => 'zmena ' . $i,
        'created' => 2000000000 + $i,
      ])->save();
    }

    $history = $this->detail($asset)['#history'];
    $this->assertCount(100, $history);
    $this->assertSame('zmena 101', $history[0]['message']);
    $this->assertSame('zmena 2', $history[99]['message'], 'Strop rezne nejstarsi, ne nejnovejsi.');
  }

  public function testObaFormulareSeDajiVykreslit(): void {
    $asset = $this->makeAsset('NB-0001');
    $build = $this->detail($asset);

    $this->assertSame('hwdesk_asset_status', $build['#status_form']['#form_id']);
    $this->assertSame('hwdesk_request_handover', $build['#handover_form']['#form_id']);
  }

}
