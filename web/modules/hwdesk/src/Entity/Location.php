<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\LocationListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Where a piece of hardware physically is (office, storage, branch).
 */
#[ContentEntityType(
  id: 'hwdesk_location',
  label: new TranslatableMarkup('Lokalita'),
  label_collection: new TranslatableMarkup('Lokality'),
  label_singular: new TranslatableMarkup('lokalita'),
  label_plural: new TranslatableMarkup('lokality'),
  entity_keys: ['id' => 'id', 'label' => 'name', 'uuid' => 'uuid'],
  handlers: [
    'list_builder' => LocationListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'add' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/config/hwdesk/locations',
    'add-form' => '/admin/config/hwdesk/locations/add',
    'edit-form' => '/admin/config/hwdesk/locations/{hwdesk_location}/edit',
    'delete-form' => '/admin/config/hwdesk/locations/{hwdesk_location}/delete',
  ],
  admin_permission: 'administer hwdesk',
  base_table: 'hwdesk_location',
  label_count: ['singular' => '@count lokalita', 'plural' => '@count lokalit'],
)]
final class Location extends ContentEntityBase {

  public function getName(): string {
    return (string) $this->get('name')->value;
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Název'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0]);
    $fields['note'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Poznámka'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1]);
    return $fields;
  }

}
