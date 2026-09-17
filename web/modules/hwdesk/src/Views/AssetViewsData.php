<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Views;

use Drupal\views\EntityViewsData;

/**
 * Adds the bulk form so the asset list can run actions on selected rows.
 */
final class AssetViewsData extends EntityViewsData {

  public function getViewsData(): array {
    $data = parent::getViewsData();
    $data['hwdesk_asset']['hwdesk_asset_bulk_form'] = [
      'title' => $this->t('Hromadné akce'),
      'help' => $this->t('Zaškrtávací pole pro hromadné akce nad zařízeními.'),
      'field' => ['id' => 'bulk_form'],
    ];
    return $data;
  }

}
