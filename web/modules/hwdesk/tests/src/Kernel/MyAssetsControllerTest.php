<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\file\Entity\File;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Controller\MyAssetsController;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stránka zaměstnance: co mám u sebe, co mám potvrdit, moje protokoly.
 *
 * Nejdůležitější věta celého souboru je ta v
 * testWhatIsWaitingForMeLeadsToThePageThatKeepsTheMailedLinkAlive: dokud
 * stránka sahala na HandoverService::confirmUrl(), vyrobila při každém
 * zobrazení nový odkaz a ten z e-mailu tím zneplatnila.
 */
#[Group('hwdesk')]
final class MyAssetsControllerTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  private UserInterface $jana;

  private UserInterface $petr;

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->jana = $this->makeUser('jana@firma.cz', 'Jana Dvořáková');
    $this->petr = $this->makeUser('petr@firma.cz', 'Petr Novák');
  }

  /**
   * @return array<string, mixed>
   */
  private function page(UserInterface $who): array {
    $this->container->get('current_user')->setAccount($who);
    return MyAssetsController::create($this->container)->page();
  }

  public function testThePageIsBuiltFromTheModulesOwnTemplate(): void {
    $build = $this->page($this->jana);

    $this->assertSame('hwdesk_my', $build['#theme']);
    $this->assertSame('Jana Dvořáková', $build['#account']);
    $this->assertSame([], $build['#assets']);
    $this->assertSame([], $build['#pending']);
    $this->assertSame([], $build['#handovers']);
    $this->assertSame(['user'], $build['#cache']['contexts']);
    $this->assertSame(['hwdesk_asset_list', 'hwdesk_handover_list'], $build['#cache']['tags']);
    $this->assertArrayNotHasKey('#type', $build, 'Stránka se neskládá z jádrových tabulek.');
  }

  public function testOnlyMyOwnDevicesAreListed(): void {
    $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $this->jana->id()]);
    $this->makeAsset('NB-0002', ['status' => AssetStatus::Assigned->value, 'holder' => $this->petr->id()]);

    $this->assertSame(['NB-0001'], array_column($this->page($this->jana)['#assets'], 'tag'));
    $this->assertSame(['NB-0002'], array_column($this->page($this->petr)['#assets'], 'tag'));
  }

  public function testDevicesWaitingForAHandoverOrAReturnStayOnTheListRetiredOnesDoNot(): void {
    $this->makeAsset('NB-0030', ['status' => AssetStatus::PendingReturn->value, 'holder' => $this->jana->id()]);
    $this->makeAsset('NB-0010', ['status' => AssetStatus::Assigned->value, 'holder' => $this->jana->id()]);
    $this->makeAsset('NB-0020', ['status' => AssetStatus::PendingHandover->value, 'holder' => $this->jana->id()]);
    $this->makeAsset('NB-0040', ['status' => AssetStatus::Retired->value, 'holder' => $this->jana->id()]);
    $this->makeAsset('NB-0050', ['status' => AssetStatus::Lost->value, 'holder' => $this->jana->id()]);
    $this->makeAsset('NB-0060', ['status' => AssetStatus::InStock->value]);

    $this->assertSame(['NB-0010', 'NB-0020', 'NB-0030'], array_column($this->page($this->jana)['#assets'], 'tag'));
  }

  public function testTheRowsAreTheSameOnesTheRestOfTheModuleShows(): void {
    $asset = $this->makeAsset('NB-0001', [
      'status' => AssetStatus::Assigned->value,
      'holder' => $this->jana->id(),
      'serial_number' => 'PF3ABCDE',
      'name' => 'Notebook do kanceláře',
    ]);
    $handover = $this->makeHandover($asset, $this->jana, [
      'status' => HandoverStatus::Confirmed->value,
      'decided' => 1761000000,
      'protocol_number' => '2026/0001',
    ]);
    $presenter = $this->container->get('hwdesk.presenter');

    $build = $this->page($this->jana);

    $this->assertEquals($presenter->asset($asset), $build['#assets'][0], 'Zařízení skládá Presenter, ne stránka.');
    $this->assertEquals($presenter->handover($handover), $build['#handovers'][0], 'Protokol skládá Presenter, ne stránka.');
  }

  public function testWhatIsWaitingForMeLeadsToThePageThatKeepsTheMailedLinkAlive(): void {
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::PendingHandover->value, 'prepared_for' => $this->jana->id()]);
    $handover = $this->makeHandover($asset, $this->jana);
    $tokens = $this->container->get('hwdesk.handover_tokens');
    $token = $tokens->issue((int) $handover->id(), (int) $this->jana->id(), (int) $handover->get('expires')->value);
    $presenter = $this->container->get('hwdesk.presenter');

    $build = $this->page($this->jana);

    $this->assertCount(1, $build['#pending']);
    $row = $build['#pending'][0];
    $this->assertSame('/hwdesk/prevzeti/' . $handover->id(), $row['confirm_url']);
    $this->assertEquals($presenter->handover($handover), array_diff_key($row, ['confirm_url' => NULL]), 'Kromě adresy je řádek z Presenteru.');

    // Stránka se za den otevře několikrát a odkaz z e-mailu musí pořád platit.
    $this->page($this->jana);
    $this->page($this->jana);
    $this->assertNotNull($tokens->verify($token), 'Vlastní stránka nesmí spotřebovat odkaz z e-mailu.');
    $this->assertStringNotContainsString($token, print_r($build, TRUE), 'Na stránce se žádný jednorázový odkaz nevyrábí.');
  }

  public function testMyProtocolsAreListedNewestFirstAndCarryTheirFile(): void {
    $file = File::create(['uri' => 'private://hwdesk/protokol-2026-0001.pdf', 'filename' => 'protokol-2026-0001.pdf']);
    $file->save();
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $this->jana->id()]);
    $this->makeHandover($asset, $this->jana, [
      'status' => HandoverStatus::Confirmed->value,
      'decided' => 1760000000,
      'protocol_number' => '2026/0001',
      'protocol_file' => $file->id(),
    ]);
    $this->makeHandover($asset, $this->jana, [
      'kind' => HandoverKind::ReturnItem->value,
      'status' => HandoverStatus::Rejected->value,
      'decided' => 1761000000,
      'reason' => 'Zařízení jsem nedostal.',
    ]);
    // Rozhodnuto ještě není, patří tedy nahoru k potvrzení, ne do historie.
    $this->makeHandover($asset, $this->jana);

    $build = $this->page($this->jana);

    $this->assertSame(['', '2026/0001'], array_column($build['#handovers'], 'protocol_number'));
    $this->assertNull($build['#handovers'][0]['protocol_url']);
    $this->assertStringContainsString('protokol-2026-0001.pdf', (string) $build['#handovers'][1]['protocol_url']);
    $this->assertCount(1, $build['#pending']);
  }

  public function testSomebodyElsesConfirmationsAndProtocolsStayHidden(): void {
    $asset = $this->makeAsset('NB-0099', ['status' => AssetStatus::PendingHandover->value, 'prepared_for' => $this->petr->id()]);
    $this->makeHandover($asset, $this->petr);
    $this->makeHandover($asset, $this->petr, [
      'status' => HandoverStatus::Confirmed->value,
      'decided' => 1761000000,
      'protocol_number' => '2026/0099',
    ]);

    $mine = $this->page($this->jana);
    $this->assertSame([], $mine['#pending']);
    $this->assertSame([], $mine['#handovers']);

    $his = $this->page($this->petr);
    $this->assertCount(1, $his['#pending']);
    $this->assertSame(['2026/0099'], array_column($his['#handovers'], 'protocol_number'));
  }

  public function testTheHistoryIsCappedSoThePageStaysQuick(): void {
    $asset = $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $this->jana->id()]);
    for ($i = 1; $i <= 60; $i++) {
      $this->makeHandover($asset, $this->jana, [
        'status' => HandoverStatus::Confirmed->value,
        'decided' => 1760000000 + $i,
        'protocol_number' => sprintf('2026/%04d', $i),
      ]);
    }

    $build = $this->page($this->jana);

    $this->assertCount(50, $build['#handovers']);
    $this->assertSame('2026/0060', $build['#handovers'][0]['protocol_number'], 'Nejnovější je první.');
  }

}
