<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\AssetStatus;

#[Action(id: 'hwdesk_asset_lost', label: new TranslatableMarkup('Označit vybraná zařízení jako ztracená'), type: 'hwdesk_asset')]
final class MarkAssetLost extends SetAssetStatusBase {

  protected function target(): AssetStatus {
    return AssetStatus::Lost;
  }

}
