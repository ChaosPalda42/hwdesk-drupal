<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\HandoverService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The handover/return workflow: request → e-mail → confirm/reject/expire → protocol.
 */
#[Group('hwdesk')]
final class HandoverServiceTest extends KernelTestBase {

  use AssertMailTrait;
  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  private UserInterface $jana;
  private UserInterface $petr;
  private UserInterface $admin;

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('hwdesk.settings')
      ->set('base_url', 'https://intranet.example')
      ->set('company_name', 'Firma s.r.o.')
      ->set('protocol_copy_to', 'archiv@firma.cz')
      ->set('handover_token_hours', 72)
      ->save();
    $this->jana = $this->makeUser('jana@firma.cz', 'Jana Nováková');
    $this->petr = $this->makeUser('petr@firma.cz', 'Petr Svoboda');
    $this->admin = $this->makeUser('spravce@firma.cz', 'Správce');
  }

  private function service(): HandoverService {
    return $this->container->get('hwdesk.handover');
  }

  private function reload(Asset $asset): Asset {
    $storage = $this->container->get('entity_type.manager')->getStorage('hwdesk_asset');
    $storage->resetCache([$asset->id()]);
    $loaded = $storage->load($asset->id());
    assert($loaded instanceof Asset);
    return $loaded;
  }

  private function reloadHandover(Handover $handover): Handover {
    $storage = $this->container->get('entity_type.manager')->getStorage('hwdesk_handover');
    $storage->resetCache([$handover->id()]);
    $loaded = $storage->load($handover->id());
    assert($loaded instanceof Handover);
    return $loaded;
  }

  /**
   * @return list<string>
   */
  private function recipients(string $id): array {
    $to = array_map(static fn(array $m): string => (string) $m['to'], $this->getMails(['id' => $id]));
    sort($to);
    return $to;
  }

  public function testRequestHandoverCreatesAPendingRequestAndMailsTheEmployee(): void {
    $asset = $this->makeAsset('NB-0001');
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);

    $this->assertFalse($handover->isNew());
    $this->assertSame(HandoverStatus::Pending, $handover->getStatus());
    $this->assertSame(HandoverKind::Handover, $handover->getKind());
    $this->assertEqualsWithDelta(time() + 72 * 3600, $handover->getExpires(), 5);
    $this->assertSame((int) $this->admin->id(), (int) $handover->getRequestedBy()?->id());
    $this->assertSame((int) $this->jana->id(), (int) $handover->getUser()?->id());
    $reloaded = $this->reload($asset);
    $this->assertSame(AssetStatus::PendingHandover, $reloaded->getStatus());
    $this->assertSame((int) $this->jana->id(), (int) $reloaded->getHolder()?->id());
    $this->assertSame((int) $handover->id(), (int) $this->service()->openFor($reloaded)?->id());

    $mails = $this->getMails(['id' => 'hwdesk_handover_request']);
    $this->assertCount(1, $mails);
    $this->assertSame('jana@firma.cz', $mails[0]['to']);
    $this->assertStringContainsString('NB-0001', $mails[0]['subject']);
    $url = $this->service()->confirmUrl($handover);
    $this->assertStringStartsWith('https://intranet.example/hwdesk/confirm/', $url);
    $this->assertStringContainsString($url, $mails[0]['body']);
    $token = substr($url, strlen('https://intranet.example/hwdesk/confirm/'));
    $claims = $this->container->get('hwdesk.handover_tokens')->verify($token);
    $this->assertSame((int) $handover->id(), $claims['handover'] ?? NULL);
    $this->assertSame((int) $this->jana->id(), $claims['uid'] ?? NULL);
  }

  public function testRequestHandoverRefusesAnAssetThatIsNotInStock(): void {
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $this->petr->id()]);
    $this->expectException(\DomainException::class);
    $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
  }

  public function testRequestReturnRequiresTheHolder(): void {
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $this->jana->id()]);
    try {
      $this->service()->request($asset, $this->petr, HandoverKind::ReturnItem, $this->admin);
      $this->fail('a return for someone else was accepted');
    }
    catch (\DomainException) {
    }
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::ReturnItem, $this->admin);
    $this->assertSame(HandoverKind::ReturnItem, $handover->getKind());
    $this->assertSame(AssetStatus::PendingReturn, $this->reload($asset)->getStatus());
    $this->assertSame(['jana@firma.cz'], $this->recipients('hwdesk_return_request'));
  }

  public function testAnAssetHasAtMostOneOpenRequest(): void {
    $asset = $this->makeAsset('NB-0001');
    $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
    $this->expectException(\DomainException::class);
    $this->service()->request($this->reload($asset), $this->petr, HandoverKind::Handover, $this->admin);
  }

  public function testConfirmHandoverAssignsTheAssetAndIssuesTheProtocol(): void {
    $asset = $this->makeAsset('NB-0001');
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
    $confirmed = $this->service()->confirm($handover, $this->jana);

    $year = date('Y');
    $this->assertSame(HandoverStatus::Confirmed, $confirmed->getStatus());
    $this->assertEqualsWithDelta(time(), $confirmed->getDecided(), 5);
    $this->assertSame("HP-$year-0001", $confirmed->getProtocolNumber());
    $this->assertSame("private://hwdesk/protocols/HP-$year-0001.pdf", $confirmed->getProtocolFile()?->getFileUri());
    $reloaded = $this->reload($asset);
    $this->assertSame(AssetStatus::Assigned, $reloaded->getStatus());
    $this->assertSame((int) $this->jana->id(), (int) $reloaded->getHolder()?->id());
    $this->assertNull($this->service()->openFor($reloaded));

    $this->assertSame(['archiv@firma.cz', 'jana@firma.cz', 'spravce@firma.cz'], $this->recipients('hwdesk_protocol'));
    foreach ($this->getMails(['id' => 'hwdesk_protocol']) as $mail) {
      $this->assertStringContainsString("HP-$year-0001", $mail['subject']);
      $this->assertStringContainsString('NB-0001', $mail['body']);
    }
  }

  public function testConfirmReturnPutsTheAssetBackInStock(): void {
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $this->jana->id()]);
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::ReturnItem, $this->admin);
    $confirmed = $this->service()->confirm($handover, $this->jana);
    $this->assertSame(HandoverStatus::Confirmed, $confirmed->getStatus());
    $this->assertNotSame('', $confirmed->getProtocolNumber());
    $this->assertNotNull($confirmed->getProtocolFile());
    $reloaded = $this->reload($asset);
    $this->assertSame(AssetStatus::InStock, $reloaded->getStatus());
    $this->assertNull($reloaded->getHolder());
  }

  public function testOnlyTheAddressedEmployeeMayConfirm(): void {
    $asset = $this->makeAsset('NB-0001');
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
    foreach ([$this->petr, $this->admin] as $actor) {
      try {
        $this->service()->confirm($handover, $actor);
        $this->fail('confirmed by the wrong account');
      }
      catch (\DomainException) {
      }
    }
    $this->assertSame(HandoverStatus::Pending, $this->reloadHandover($handover)->getStatus());
    $this->assertSame(AssetStatus::PendingHandover, $this->reload($asset)->getStatus());
    $this->assertCount(0, $this->getMails(['id' => 'hwdesk_protocol']));
  }

  public function testConfirmIsRefusedTwiceAndAfterExpiry(): void {
    $asset = $this->makeAsset('NB-0001');
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
    $this->service()->confirm($handover, $this->jana);
    try {
      $this->service()->confirm($this->reloadHandover($handover), $this->jana);
      $this->fail('confirmed twice');
    }
    catch (\DomainException) {
    }

    $other = $this->makeAsset('NB-0002');
    $late = $this->service()->request($other, $this->jana, HandoverKind::Handover, $this->admin);
    $late->set('expires', time() - 10)->save();
    try {
      $this->service()->confirm($this->reloadHandover($late), $this->jana);
      $this->fail('confirmed after expiry');
    }
    catch (\DomainException) {
    }
    $this->assertSame(HandoverStatus::Expired, $this->reloadHandover($late)->getStatus());
    $reloaded = $this->reload($other);
    $this->assertSame(AssetStatus::InStock, $reloaded->getStatus());
    $this->assertNull($reloaded->getHolder());
  }

  public function testRejectRestoresTheAssetAndTellsTheRequester(): void {
    $asset = $this->makeAsset('NB-0001');
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
    $rejected = $this->service()->reject($handover, $this->jana, 'Nechci notebook, mám vlastní.');
    $this->assertSame(HandoverStatus::Rejected, $rejected->getStatus());
    $this->assertSame('Nechci notebook, mám vlastní.', $rejected->getReason());
    $this->assertEqualsWithDelta(time(), $rejected->getDecided(), 5);
    $reloaded = $this->reload($asset);
    $this->assertSame(AssetStatus::InStock, $reloaded->getStatus());
    $this->assertNull($reloaded->getHolder());
    $mails = $this->getMails(['id' => 'hwdesk_rejected']);
    $this->assertCount(1, $mails);
    $this->assertSame('spravce@firma.cz', $mails[0]['to']);
    $this->assertStringContainsString('Nechci notebook', $mails[0]['body']);

    $held = $this->makeAsset('NB-0002', ['status' => AssetStatus::Assigned->value, 'holder' => $this->jana->id()]);
    $return = $this->service()->request($held, $this->jana, HandoverKind::ReturnItem, $this->admin);
    $this->service()->reject($return, $this->jana, 'ještě ho potřebuji');
    $reloadedHeld = $this->reload($held);
    $this->assertSame(AssetStatus::Assigned, $reloadedHeld->getStatus());
    $this->assertSame((int) $this->jana->id(), (int) $reloadedHeld->getHolder()?->id());
  }

  public function testRejectRequiresAReasonAndTheAddressedEmployee(): void {
    $asset = $this->makeAsset('NB-0001');
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
    try {
      $this->service()->reject($handover, $this->petr, 'x');
      $this->fail('rejected by the wrong account');
    }
    catch (\DomainException) {
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->service()->reject($handover, $this->jana, '   ');
  }

  public function testCancelByTheAdministratorRestoresTheAsset(): void {
    $asset = $this->makeAsset('NB-0001');
    $handover = $this->service()->request($asset, $this->jana, HandoverKind::Handover, $this->admin);
    $cancelled = $this->service()->cancel($handover, $this->admin);
    $this->assertSame(HandoverStatus::Cancelled, $cancelled->getStatus());
    $this->assertSame(AssetStatus::InStock, $this->reload($asset)->getStatus());
    $this->expectException(\DomainException::class);
    $this->service()->cancel($this->reloadHandover($handover), $this->admin);
  }

  public function testExpireOverdueSweepsOnlyThePastDue(): void {
    $a = $this->makeAsset('NB-0001');
    $b = $this->makeAsset('NB-0002', ['status' => AssetStatus::Assigned->value, 'holder' => $this->petr->id()]);
    $c = $this->makeAsset('NB-0003');
    $ha = $this->service()->request($a, $this->jana, HandoverKind::Handover, $this->admin);
    $hb = $this->service()->request($b, $this->petr, HandoverKind::ReturnItem, $this->admin);
    $hc = $this->service()->request($c, $this->jana, HandoverKind::Handover, $this->admin);
    $ha->set('expires', time() - 5)->save();
    $hb->set('expires', time() - 5)->save();

    $this->assertSame(2, $this->service()->expireOverdue());
    $this->assertSame(0, $this->service()->expireOverdue());
    $this->assertSame(HandoverStatus::Expired, $this->reloadHandover($ha)->getStatus());
    $this->assertSame(HandoverStatus::Expired, $this->reloadHandover($hb)->getStatus());
    $this->assertSame(HandoverStatus::Pending, $this->reloadHandover($hc)->getStatus());
    $this->assertSame(AssetStatus::InStock, $this->reload($a)->getStatus());
    $this->assertNull($this->reload($a)->getHolder());
    $this->assertSame(AssetStatus::Assigned, $this->reload($b)->getStatus());
    $this->assertSame((int) $this->petr->id(), (int) $this->reload($b)->getHolder()?->id());
    $this->assertSame(AssetStatus::PendingHandover, $this->reload($c)->getStatus());
  }

}
