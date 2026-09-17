<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\AssetStatus;

#[Action(id: 'hwdesk_asset_retire', label: new TranslatableMarkup('Vyřadit vybraná zařízení'), type: 'hwdesk_asset')]
final class RetireAsset extends SetAssetStatusBase {

  protected function target(): AssetStatus {
    return AssetStatus::Retired;
  }

}
