<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\DashboardStats;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Numbers for the overview page; every number is later a link to a filtered list.
 */
#[Group('hwdesk')]
final class DashboardStatsTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  private function stats(): DashboardStats {
    return $this->container->get('hwdesk.dashboard_stats');
  }

  public function testCountsAreZeroFilledOnAnEmptyDatabase(): void {
    $this->assertSame(['in_stock' => 0, 'pending_handover' => 0, 'assigned' => 0, 'pending_return' => 0, 'retired' => 0, 'lost' => 0], $this->stats()->byStatus());
    $this->assertSame([], $this->stats()->byType());
    $this->assertSame([], $this->stats()->byLocation());
    $this->assertSame(0, $this->stats()->pendingHandovers());
    $this->assertSame(0, $this->stats()->warrantyExpiring());
    $this->assertSame(0, $this->stats()->holders());
  }

  public function testCountsFollowTheData(): void {
    $jana = $this->makeUser('jana@firma.cz');
    $petr = $this->makeUser('petr@firma.cz');
    $brno = Location::create(['name' => 'Brno']);
    $brno->save();
    $praha = Location::create(['name' => 'Praha']);
    $praha->save();
    $soon = date('Y-m-d', strtotime('+30 days'));
    $late = date('Y-m-d', strtotime('+200 days'));
    $past = date('Y-m-d', strtotime('-1 day'));

    $a = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $jana->id(), 'location' => $brno->id(), 'warranty_until' => $soon]);
    $this->makeAsset('NB-0002', ['status' => AssetStatus::Assigned->value, 'holder' => $jana->id(), 'location' => $brno->id(), 'warranty_until' => $late]);
    $this->makeAsset('NB-0003', ['status' => AssetStatus::PendingHandover->value, 'holder' => $petr->id(), 'location' => $praha->id(), 'warranty_until' => $soon]);
    $this->makeAsset('TEL-0001', ['type' => 'phone', 'warranty_until' => $past]);
    $this->makeAsset('TEL-0002', ['type' => 'phone', 'status' => AssetStatus::Retired->value, 'warranty_until' => $soon]);
    $this->makeAsset('MON-0001', ['type' => 'monitor', 'status' => AssetStatus::Lost->value]);
    $this->makeHandover($a, $jana);
    $this->makeHandover($a, $jana, ['status' => HandoverStatus::Confirmed->value]);
    $this->makeHandover($a, $jana, ['status' => HandoverStatus::Rejected->value]);

    $this->assertSame(['in_stock' => 1, 'pending_handover' => 1, 'assigned' => 2, 'pending_return' => 0, 'retired' => 1, 'lost' => 1], $this->stats()->byStatus());
    $this->assertSame(['notebook' => 3, 'phone' => 2, 'monitor' => 1], $this->stats()->byType());
    $this->assertSame([(int) $brno->id() => 2, (int) $praha->id() => 1, 0 => 3], $this->stats()->byLocation());
    $this->assertSame(1, $this->stats()->pendingHandovers());
    $this->assertSame(2, $this->stats()->warrantyExpiring(), 'within 90 days, retired/lost excluded, past excluded');
    $this->assertSame(3, $this->stats()->warrantyExpiring(365));
    $this->assertSame(2, $this->stats()->holders());
  }

}
