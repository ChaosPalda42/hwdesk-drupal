<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Views;

use Drupal\views\EntityViewsData;

/**
 * Views data for hwdesk_asset: the bulk form, and real filter widgets for
 * the list/reference/date base fields (core maps base fields to plain
 * string/numeric filters).
 */
final class AssetViewsData extends EntityViewsData {

  public function getViewsData(): array {
    $data = parent::getViewsData();
    $data['hwdesk_asset']['hwdesk_asset_bulk_form'] = [
      'title' => $this->t('Hromadné akce'),
      'help' => $this->t('Zaškrtávací pole pro hromadné akce nad zařízeními.'),
      'field' => ['id' => 'bulk_form'],
    ];
    foreach (['type', 'status', 'condition'] as $field) {
      $data['hwdesk_asset'][$field]['filter'] = ['id' => 'list_field', 'field_name' => $field, 'entity_type' => 'hwdesk_asset'] + ($data['hwdesk_asset'][$field]['filter'] ?? []);
    }
    foreach (['holder', 'location', 'invoice'] as $field) {
      $data['hwdesk_asset'][$field]['filter'] = ['id' => 'entity_reference', 'field_name' => $field, 'entity_type' => 'hwdesk_asset'] + ($data['hwdesk_asset'][$field]['filter'] ?? []);
    }
    if (isset($data['hwdesk_asset__tags']['tags_target_id'])) {
      $data['hwdesk_asset__tags']['tags_target_id']['filter'] = ['id' => 'entity_reference', 'field_name' => 'tags', 'entity_type' => 'hwdesk_asset'] + ($data['hwdesk_asset__tags']['tags_target_id']['filter'] ?? []);
    }
    $data['hwdesk_asset']['warranty_until']['filter'] = ['id' => 'datetime', 'field_name' => 'warranty_until', 'entity_type' => 'hwdesk_asset'] + ($data['hwdesk_asset']['warranty_until']['filter'] ?? []);
    $data['hwdesk_asset']['warranty_until']['sort'] = ['id' => 'datetime', 'field_name' => 'warranty_until', 'entity_type' => 'hwdesk_asset'] + ($data['hwdesk_asset']['warranty_until']['sort'] ?? []);
    return $data;
  }

}
