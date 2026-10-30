<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Komu se staví nabídka vlevo.
 *
 * Potvrzení z e-mailu (/hvd/{token}) otevírá člověk, který do aplikace jinak
 * nepatří — a sloupec vlevo mu nabízel „HW Desk" i „Moje zařízení", přičemž
 * obojí mu vrátilo „nemáte oprávnění". Nabídka patří tomu, kdo ji má kam
 * použít; ostatním zůstane sloupec s hlavičkou a bez odkazů.
 */
#[Group('hwdesk')]
final class NavigationVisibilityTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  public function testSpravceAZamestnanecNabidkuDostanou(): void {
    $this->assertTrue(hwdesk_navigation_visible($this->ucet(admin: TRUE, own: FALSE)), 'Správce.');
    $this->assertTrue(hwdesk_navigation_visible($this->ucet(admin: FALSE, own: TRUE)), 'Zaměstnanec.');
  }

  public function testKdoDoAplikaceNepatriNabidkuNedostane(): void {
    $this->assertFalse(hwdesk_navigation_visible($this->ucet(admin: FALSE, own: FALSE)));
  }

  private function ucet(bool $admin, bool $own): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => match ($permission) {
      'administer hwdesk' => $admin,
      'view own hwdesk assets' => $own,
      default => FALSE,
    });
    return $account;
  }

}
