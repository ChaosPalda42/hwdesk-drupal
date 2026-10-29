<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Controller\DashboardController;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\HandoverStatus;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Přehled: dlaždice s čísly a tři krátké seznamy, jako v původní předloze.
 */
#[Group('hwdesk')]
final class DashboardControllerTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  /**
   * @return array<string, mixed>
   */
  private function page(): array {
    return DashboardController::create($this->container)->page();
  }

  public function testAnEmptyEvidenceStillDrawsAllTheTiles(): void {
    $build = $this->page();

    $this->assertSame('hwdesk_dashboard', $build['#theme']);
    $this->assertSame(['assets' => 0, 'value' => 0.0], $build['#totals']);
    $this->assertSame(
      ['in_stock', 'pending_handover', 'assigned', 'pending_return', 'retired', 'lost', 'pending', 'warranty', 'holders'],
      array_column($build['#tiles'], 'key')
    );
    $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], array_column($build['#tiles'], 'count'));
    $this->assertSame([], $build['#pending']);
    $this->assertSame([], $build['#warranty']);
    $this->assertSame([], $build['#activity']);
    $this->assertSame([], $build['#by_type']);
    $this->assertSame([], $build['#by_location']);
    $this->assertSame(['hwdesk_asset_list', 'hwdesk_handover_list', 'hwdesk_audit_list'], $build['#cache']['tags']);
    $this->assertSame(['user.permissions'], $build['#cache']['contexts']);
  }

  public function testEveryTileLeadsToTheListItCounts(): void {
    $build = $this->page();
    $podle = array_column($build['#tiles'], 'url', 'key');

    foreach ($podle as $key => $url) {
      $this->assertIsString($url, sprintf('Dlaždice %s má adresu jako řetězec, ne objekt.', $key));
      $this->assertStringStartsWith('/', $url);
    }
    $this->assertStringContainsString('status=assigned', $podle['assigned']);
    $this->assertStringContainsString('status=pending', $podle['pending']);
    $this->assertStringContainsString('warranty_until', $podle['warranty']);
  }

  public function testTheThreeListsShowWhatIsWaitingAndWhatJustHappened(): void {
    $jana = $this->makeUser('jana@firma.cz', 'Jana Dvořáková');
    $brno = Location::create(['name' => 'Brno']);
    $brno->save();
    $notebook = $this->makeAsset('NB-0001', [
      'status' => AssetStatus::PendingHandover->value,
      'prepared_for' => $jana->id(),
      'location' => $brno->id(),
      'warranty_until' => date('Y-m-d', strtotime('+20 days')),
      'price' => '30000.00',
    ]);
    $telefon = $this->makeAsset('TEL-0001', [
      'type' => 'phone',
      'warranty_until' => date('Y-m-d', strtotime('+60 days')),
      'price' => '12000.00',
    ]);
    $vyzva = $this->makeHandover($notebook, $jana, ['created' => 1760000200]);
    $zapis = $this->container->get('entity_type.manager')->getStorage('hwdesk_audit')->create([
      'target_type' => 'hwdesk_asset',
      'target_id' => (int) $notebook->id(),
      'target_label' => 'NB-0001',
      'action' => 'handover.requested',
      'message' => 'Výzva k převzetí odeslána.',
      // Do budoucnosti: ukladani entit si samo pise do auditu radky s casem
      // ulozeni, a tenhle zapis ma byt v seznamu prvni.
      'created' => 2000000000,
    ]);
    $zapis->save();
    $presenter = $this->container->get('hwdesk.presenter');

    $build = $this->page();

    // assertEquals, ne assertSame: radky nesou objekty TranslatableMarkup.
    $this->assertEquals([$presenter->handover($vyzva)], $build['#pending'], 'Čeká na potvrzení: řádek z Presenteru.');
    $this->assertEquals(
      [$presenter->asset($notebook), $presenter->asset($telefon)],
      $build['#warranty'],
      'Končící záruka: co končí dřív, je první.'
    );
    $this->assertEquals($presenter->audit($zapis), $build['#activity'][0], 'Nejnovější děj je první a skládá ho Presenter.');
    $this->assertLessThanOrEqual(10, count($build['#activity']), 'Na nástěnku se vejde deset řádků.');
    $this->assertSame(['assets' => 2, 'value' => 42000.0], $build['#totals']);
  }

  public function testTheBreakdownNamesTypesAndPlacesTheWayTheSettingDoes(): void {
    $brno = Location::create(['name' => 'Brno']);
    $brno->save();
    $this->makeAsset('NB-0001', ['location' => $brno->id()]);
    $this->makeAsset('NB-0002', ['location' => $brno->id()]);
    $this->makeAsset('TEL-0001', ['type' => 'phone']);

    $build = $this->page();

    $typy = array_map(static fn(array $r): array => ['label' => (string) $r['label'], 'count' => $r['count']], $build['#by_type']);
    $this->assertSame([['label' => 'Notebook', 'count' => 2], ['label' => 'Telefon', 'count' => 1]], $typy);
    $this->assertStringContainsString('type=phone', $build['#by_type'][1]['url']);

    $mista = array_map(static fn(array $r): array => ['label' => (string) $r['label'], 'count' => $r['count']], $build['#by_location']);
    $this->assertSame([['label' => 'Brno', 'count' => 2], ['label' => 'Bez lokality', 'count' => 1]], $mista);
    $this->assertStringContainsString('location=' . $brno->id(), $build['#by_location'][0]['url']);
  }

  public function testAHandoverThatWasConfirmedIsNoLongerWaiting(): void {
    $jana = $this->makeUser('jana@firma.cz');
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $jana->id()]);
    $this->makeHandover($asset, $jana, ['status' => HandoverStatus::Confirmed->value, 'decided' => 1760000300]);

    $build = $this->page();

    $this->assertSame([], $build['#pending']);
    $this->assertSame(0, array_column($build['#tiles'], 'count', 'key')['pending']);
  }

}
