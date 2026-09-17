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
use Drupal\hwdesk\TagListBuilder;
use Drupal\views\EntityViewsData;

/**
 * A free, coloured label an asset may carry (N:N).
 */
#[ContentEntityType(
  id: 'hwdesk_tag',
  label: new TranslatableMarkup('Štítek'),
  label_collection: new TranslatableMarkup('Štítky'),
  label_singular: new TranslatableMarkup('štítek'),
  label_plural: new TranslatableMarkup('štítky'),
  entity_keys: ['id' => 'id', 'label' => 'name', 'uuid' => 'uuid'],
  handlers: [
    'list_builder' => TagListBuilder::class,
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
    'collection' => '/admin/config/hwdesk/tags',
    'add-form' => '/admin/config/hwdesk/tags/add',
    'edit-form' => '/admin/config/hwdesk/tags/{hwdesk_tag}/edit',
    'delete-form' => '/admin/config/hwdesk/tags/{hwdesk_tag}/delete',
  ],
  admin_permission: 'administer hwdesk',
  base_table: 'hwdesk_tag',
  label_count: ['singular' => '@count štítek', 'plural' => '@count štítků'],
)]
final class Tag extends ContentEntityBase {

  public function getName(): string {
    return (string) $this->get('name')->value;
  }

  public function getColor(): string {
    return (string) ($this->get('color')->value ?: '#888888');
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Název'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0]);
    $fields['color'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Barva'))
      ->setDescription(new TranslatableMarkup('Hex, např. #2a9d8f.'))
      ->setSetting('max_length', 7)
      ->setDefaultValue('#888888')
      ->addPropertyConstraints('value', ['Regex' => ['pattern' => '/^#[0-9a-fA-F]{6}$/']])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1]);
    return $fields;
  }

}
