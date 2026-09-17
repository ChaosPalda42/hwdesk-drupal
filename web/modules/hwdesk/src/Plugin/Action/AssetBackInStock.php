<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\AssetStatus;

#[Action(id: 'hwdesk_asset_in_stock', label: new TranslatableMarkup('Vrátit vybraná zařízení na sklad'), type: 'hwdesk_asset')]
final class AssetBackInStock extends SetAssetStatusBase {

  protected function target(): AssetStatus {
    return AssetStatus::InStock;
  }

}
