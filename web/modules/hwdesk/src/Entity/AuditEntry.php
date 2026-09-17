<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * Who did what, when — one row per change to any hwdesk entity.
 */
#[ContentEntityType(
  id: 'hwdesk_audit',
  label: new TranslatableMarkup('Auditní záznam'),
  label_collection: new TranslatableMarkup('Audit'),
  label_singular: new TranslatableMarkup('auditní záznam'),
  label_plural: new TranslatableMarkup('auditní záznamy'),
  entity_keys: ['id' => 'id', 'label' => 'message', 'uuid' => 'uuid'],
  handlers: ['views_data' => EntityViewsData::class],
  admin_permission: 'administer hwdesk',
  base_table: 'hwdesk_audit',
  internal: TRUE,
  label_count: ['singular' => '@count záznam', 'plural' => '@count záznamů'],
)]
final class AuditEntry extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Kdo'))
      ->setSetting('target_type', 'user');
    $fields['target_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Typ entity'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);
    $fields['target_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('ID entity'))
      ->setRequired(TRUE);
    $fields['target_label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Entita'))
      ->setSetting('max_length', 255);
    $fields['action'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Akce'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);
    $fields['message'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Co'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 1000);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Kdy'));
    return $fields;
  }

}
