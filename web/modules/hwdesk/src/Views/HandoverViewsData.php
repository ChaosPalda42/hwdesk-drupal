<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Views;

use Drupal\views\EntityViewsData;

/**
 * Select widgets for the list fields of hwdesk_handover.
 */
final class HandoverViewsData extends EntityViewsData {

  public function getViewsData(): array {
    $data = parent::getViewsData();
    foreach (['kind', 'status'] as $field) {
      $data['hwdesk_handover'][$field]['filter'] = ['id' => 'list_field', 'field_name' => $field, 'entity_type' => 'hwdesk_handover'] + ($data['hwdesk_handover'][$field]['filter'] ?? []);
    }
    foreach (['user', 'requested_by', 'asset'] as $field) {
      $data['hwdesk_handover'][$field]['filter'] = ['id' => 'entity_reference', 'field_name' => $field, 'entity_type' => 'hwdesk_handover'] + ($data['hwdesk_handover'][$field]['filter'] ?? []);
    }
    return $data;
  }

}
