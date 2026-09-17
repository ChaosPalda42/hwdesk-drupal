<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\InvoiceListBuilder;
use Drupal\views\EntityViewsData;

/**
 * A purchase invoice; one invoice covers many assets.
 */
#[ContentEntityType(
  id: 'hwdesk_invoice',
  label: new TranslatableMarkup('Faktura'),
  label_collection: new TranslatableMarkup('Faktury'),
  label_singular: new TranslatableMarkup('faktura'),
  label_plural: new TranslatableMarkup('faktury'),
  entity_keys: ['id' => 'id', 'label' => 'number', 'uuid' => 'uuid'],
  handlers: [
    'list_builder' => InvoiceListBuilder::class,
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
    'canonical' => '/hwdesk/invoice/{hwdesk_invoice}',
    'collection' => '/hwdesk/invoices',
    'add-form' => '/hwdesk/invoice/add',
    'edit-form' => '/hwdesk/invoice/{hwdesk_invoice}/edit',
    'delete-form' => '/hwdesk/invoice/{hwdesk_invoice}/delete',
  ],
  admin_permission: 'administer hwdesk',
  base_table: 'hwdesk_invoice',
  label_count: ['singular' => '@count faktura', 'plural' => '@count faktur'],
)]
final class Invoice extends ContentEntityBase {

  use EntityChangedTrait;

  public function getNumber(): string {
    return (string) $this->get('number')->value;
  }

  public function getSupplier(): string {
    return (string) $this->get('supplier')->value;
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['number'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Číslo faktury'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 0]);
    $fields['supplier'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Dodavatel'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 1]);
    $fields['issued_on'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Vystaveno'))
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 2])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'datetime_default', 'weight' => 2, 'settings' => ['format_type' => 'html_date']]);
    $fields['total'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Celkem'))
      ->setSetting('precision', 12)
      ->setSetting('scale', 2)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'number_decimal', 'weight' => 3]);
    $fields['currency'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Měna'))
      ->setSetting('max_length', 3)
      ->setDefaultValue('CZK')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 4])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 4]);
    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Poznámka'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 5])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 5]);
    $fields['attachments'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Přílohy'))
      ->setSetting('uri_scheme', 'private')
      ->setSetting('file_directory', 'hwdesk/invoices')
      ->setSetting('file_extensions', 'pdf jpg jpeg png')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'file_generic', 'weight' => 6])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'file_default', 'weight' => 6]);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Vytvořeno'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Změněno'));
    return $fields;
  }

}
