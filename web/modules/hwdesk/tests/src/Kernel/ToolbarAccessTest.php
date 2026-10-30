<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Komu se kreslí černá lišta Drupalu.
 *
 * Lišta otevírá panel první položky sama od sebe. Dokud byla první „Manage",
 * bylo to v pořádku — správce ho chce. Zaměstnanci je ale celé administrační
 * menu nepřístupné, zbude mu položka účtu, a ta se mu pak otevírala nad
 * obsahem na každé stránce; na mobilu přes půl obrazovky. Účet i odhlášení má
 * v levém sloupci, takže lišta pro něj nemá obsah — a bere se celá, protože
 * lišta bez panelů shodí javascript jádra.
 */
#[Group('hwdesk')]
final class ToolbarAccessTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
  }

  public function testZamestnanciSeListaNekresli(): void {
    $this->prihlas(admin: FALSE);
    $page_top = $this->hlavicka();
    hwdesk_page_top($page_top);

    $this->assertFalse($page_top['toolbar']['#access'], 'Lišta se zaměstnanci nekreslí.');
    $this->assertSame('toolbar', $page_top['toolbar']['#type'], 'Nic jiného se na ní nemění.');
    $this->assertArrayHasKey('cizi_modul', $page_top, 'Do cizích věcí v hlavičce se nesahá.');
  }

  public function testSpravciListaZustane(): void {
    $this->prihlas(admin: TRUE);
    $page_top = $this->hlavicka();
    hwdesk_page_top($page_top);

    $this->assertArrayNotHasKey('#access', $page_top['toolbar'], 'Správci se lišta nechává, jak ji postavilo jádro.');
  }

  public function testBezListySeNicNepridava(): void {
    $this->prihlas(admin: FALSE);
    $page_top = ['cizi_modul' => ['#markup' => 'x']];
    hwdesk_page_top($page_top);

    $this->assertArrayNotHasKey('toolbar', $page_top, 'Když modul toolbar neběží, nevzniká po něm prázdné místo.');
  }

  /**
   * Hlavička stránky, jak ji staví jádro: lišta a něco cizího vedle ní.
   *
   * @return array<string, array<string, mixed>>
   */
  private function hlavicka(): array {
    return [
      'toolbar' => ['#type' => 'toolbar', '#cache' => ['contexts' => ['user.permissions']]],
      'cizi_modul' => ['#markup' => 'x'],
    ];
  }

  private function prihlas(bool $admin): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $admin && $permission === 'access administration pages');
    \Drupal::service('current_user')->setAccount($account);
  }

}
