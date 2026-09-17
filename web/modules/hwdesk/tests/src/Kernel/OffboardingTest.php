<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\hwdesk\AssetStatus;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Blocking an account that holds hardware mails the administrators.
 */
#[Group('hwdesk')]
final class OffboardingTest extends KernelTestBase {

  use AssertMailTrait;
  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('hwdesk.settings')->set('protocol_copy_to', 'archiv@firma.cz')->save();
  }

  public function testBlockedHolderIsReportedToAdministrators(): void {
    $role = Role::create(['id' => 'hw_admin', 'label' => 'HW admin']);
    $role->grantPermission('administer hwdesk')->save();
    $admin = $this->makeUser('spravce@firma.cz', 'Správce');
    $admin->addRole('hw_admin')->save();
    $jana = $this->makeUser('jana@firma.cz', 'Jana Nováková');
    $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $jana->id(), 'serial_number' => 'S1']);
    $this->makeAsset('TEL-0001', ['type' => 'phone', 'model' => 'Pixel 9', 'status' => AssetStatus::PendingReturn->value, 'holder' => $jana->id()]);
    $this->makeAsset('NB-0002');

    $jana->block()->save();

    $mails = $this->getMails(['id' => 'hwdesk_offboarding']);
    $to = array_map(static fn(array $m): string => (string) $m['to'], $mails);
    sort($to);
    $this->assertSame(['archiv@firma.cz', 'spravce@firma.cz'], $to);
    $this->assertStringContainsString('2 zařízení', $mails[0]['subject']);
    $this->assertStringContainsString('NB-0001', $mails[0]['body']);
    $this->assertStringContainsString('TEL-0001', $mails[0]['body']);
    $this->assertStringNotContainsString('NB-0002', $mails[0]['body']);

    $jana->activate()->save();
    $jana->block()->save();
    $this->assertCount(4, $this->getMails(['id' => 'hwdesk_offboarding']), 'reported on every block, not on other saves');
    $jana->set('mail', 'jana.novakova@firma.cz')->save();
    $this->assertCount(4, $this->getMails(['id' => 'hwdesk_offboarding']));
  }

  public function testNothingHeldNothingSent(): void {
    $petr = $this->makeUser('petr@firma.cz');
    $petr->block()->save();
    $this->assertCount(0, $this->getMails(['id' => 'hwdesk_offboarding']));
  }

}
