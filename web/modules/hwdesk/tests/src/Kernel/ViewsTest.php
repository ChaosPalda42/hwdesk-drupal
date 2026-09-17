<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\Entity\Tag;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shipped views install under strict config schema, execute and filter.
 */
#[Group('hwdesk')]
final class ViewsTest extends KernelTestBase {

  use HwdeskFixturesTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->installConfig(['views']);
    // Reference filters list only entities the current user may view.
    $this->setUpCurrentUser(['name' => 'spravce'], ['administer hwdesk']);
  }

  public function testAssetsViewFiltersByStatusTypeLocationAndTag(): void {
    $jana = $this->makeUser('jana@firma.cz');
    $brno = Location::create(['name' => 'Brno']);
    $brno->save();
    $tag = Tag::create(['name' => 'Firemní']);
    $tag->save();
    $this->makeAsset('NB-0001', ['status' => AssetStatus::Assigned->value, 'holder' => $jana->id(), 'location' => $brno->id(), 'tags' => [$tag->id()]]);
    $this->makeAsset('NB-0002');
    $this->makeAsset('TEL-0001', ['type' => 'phone', 'model' => 'Pixel 9', 'warranty_until' => date('Y-m-d', strtotime('+10 days'))]);

    $view = Views::getView('hwdesk_assets');
    $this->assertNotNull($view);
    $view->setDisplay('page');
    $view->execute();
    $this->assertCount(3, $view->result);

    $cases = [
      [['status' => ['assigned']], 1],
      [['type' => ['phone']], 1],
      [['location' => [(string) $brno->id()]], 1],
      [['tags' => [(string) $tag->id()]], 1],
      [['q' => 'pixel'], 1],
      [['q' => 'NB-'], 2],
      [['warranty_until_op' => 'between', 'warranty_until' => ['min' => date('Y-m-d'), 'max' => date('Y-m-d', strtotime('+90 days'))]], 1],
    ];
    foreach ($cases as [$input, $expected]) {
      $view = Views::getView('hwdesk_assets');
      $view->setDisplay('page');
      $view->setExposedInput($input);
      $view->execute();
      $this->assertCount($expected, $view->result, json_encode($input));
    }
  }

  public function testHandoversAndAuditViewsExecute(): void {
    $jana = $this->makeUser('jana@firma.cz');
    $asset = $this->makeAsset('NB-0001');
    $this->makeHandover($asset, $jana);
    foreach (['hwdesk_handovers', 'hwdesk_audit'] as $id) {
      $view = Views::getView($id);
      $this->assertNotNull($view, $id);
      $view->setDisplay('page');
      $view->execute();
      $this->assertGreaterThan(0, count($view->result), $id);
      $build = $view->render();
      $rendered = (string) $this->container->get('renderer')->renderRoot($build);
      $this->assertStringContainsString('NB-0001', $rendered, $id);
    }
  }

}
