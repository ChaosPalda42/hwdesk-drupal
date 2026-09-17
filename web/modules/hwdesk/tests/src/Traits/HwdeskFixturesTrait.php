<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Traits;

use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Schema setup and small factories shared by the hwdesk kernel tests.
 */
trait HwdeskFixturesTrait {

  protected function installHwdesk(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    foreach (['hwdesk_asset', 'hwdesk_invoice', 'hwdesk_tag', 'hwdesk_location', 'hwdesk_handover', 'hwdesk_audit'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'hwdesk']);
  }

  protected function makeUser(string $mail, string $name = ''): UserInterface {
    $user = User::create(['name' => $name !== '' ? $name : strstr($mail, '@', TRUE), 'mail' => $mail, 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * @param array<string, mixed> $values
   */
  protected function makeAsset(string $tag, array $values = []): Asset {
    $asset = Asset::create($values + ['tag' => $tag, 'type' => 'notebook', 'manufacturer' => 'Lenovo', 'model' => 'ThinkPad T14', 'status' => AssetStatus::InStock->value]);
    $asset->save();
    return $asset;
  }

  /**
   * @param array<string, mixed> $values
   */
  protected function makeHandover(Asset $asset, UserInterface $user, array $values = []): Handover {
    $handover = Handover::create($values + [
      'asset' => $asset->id(),
      'user' => $user->id(),
      'kind' => HandoverKind::Handover->value,
      'status' => HandoverStatus::Pending->value,
      'expires' => time() + 3600,
    ]);
    $handover->save();
    return $handover;
  }

}
