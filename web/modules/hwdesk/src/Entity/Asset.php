<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\Access\AssetAccessControlHandler;
use Drupal\hwdesk\AssetCondition;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Form\AssetForm;
use Drupal\hwdesk\Views\AssetViewsData;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface;

/**
 * One piece of hardware. Revisions are the history of the piece.
 */
#[ContentEntityType(
  id: 'hwdesk_asset',
  label: new TranslatableMarkup('Zařízení'),
  label_collection: new TranslatableMarkup('Zařízení'),
  label_singular: new TranslatableMarkup('zařízení'),
  label_plural: new TranslatableMarkup('zařízení'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'label' => 'tag',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => AssetAccessControlHandler::class,
    'views_data' => AssetViewsData::class,
    'form' => [
      'default' => AssetForm::class,
      'add' => AssetForm::class,
      'edit' => AssetForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  links: [
    'canonical' => '/hwdesk/asset/{hwdesk_asset}',
    'add-form' => '/hwdesk/asset/add',
    'edit-form' => '/hwdesk/asset/{hwdesk_asset}/edit',
    'delete-form' => '/hwdesk/asset/{hwdesk_asset}/delete',
    'version-history' => '/hwdesk/asset/{hwdesk_asset}/revisions',
    'revision' => '/hwdesk/asset/{hwdesk_asset}/revision/{hwdesk_asset_revision}/view',
    'revision-delete-form' => '/hwdesk/asset/{hwdesk_asset}/revision/{hwdesk_asset_revision}/delete',
    'revision-revert-form' => '/hwdesk/asset/{hwdesk_asset}/revision/{hwdesk_asset_revision}/revert',
  ],
  admin_permission: 'administer hwdesk',
  base_table: 'hwdesk_asset',
  revision_table: 'hwdesk_asset_revision',
  show_revision_ui: TRUE,
  label_count: [
    'singular' => '@count zařízení',
    'plural' => '@count zařízení',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log',
  ],
)]
final class Asset extends RevisionableContentEntityBase implements RevisionLogInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use RevisionLogEntityTrait;

  public function getTag(): string {
    return (string) $this->get('tag')->value;
  }

  public function getStatus(): AssetStatus {
    return AssetStatus::from((string) $this->get('status')->value);
  }

  public function setStatus(AssetStatus $status): static {
    $this->set('status', $status->value);
    return $this;
  }

  public function getCondition(): AssetCondition {
    return AssetCondition::from((string) $this->get('condition')->value);
  }

  public function getType(): string {
    return (string) $this->get('type')->value;
  }

  public function getModel(): string {
    return (string) $this->get('model')->value;
  }

  public function getManufacturer(): string {
    return (string) $this->get('manufacturer')->value;
  }

  public function getSerialNumber(): string {
    return (string) $this->get('serial_number')->value;
  }

  /**
   * The employee who has the piece (or is about to get it); NULL in stock.
   */
  public function getHolder(): ?UserInterface {
    $holder = $this->get('holder')->entity;
    return $holder instanceof UserInterface ? $holder : NULL;
  }

  public function setHolder(?UserInterface $user): static {
    $this->set('holder', $user?->id());
    return $this;
  }

  public function getLocation(): ?Location {
    $location = $this->get('location')->entity;
    return $location instanceof Location ? $location : NULL;
  }

  public function getInvoice(): ?Invoice {
    $invoice = $this->get('invoice')->entity;
    return $invoice instanceof Invoice ? $invoice : NULL;
  }

  /**
   * @return list<\Drupal\hwdesk\Entity\Tag>
   */
  public function getTags(): array {
    $tags = [];
    foreach ($this->get('tags')->referencedEntities() as $tag) {
      if ($tag instanceof Tag) {
        $tags[] = $tag;
      }
    }
    return $tags;
  }

  /**
   * Model with manufacturer, the way the piece is called on labels and protocols.
   */
  public function getDisplayName(): string {
    return trim($this->getManufacturer() . ' ' . $this->getModel());
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->set('tag', strtoupper(trim($this->getTag())));
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += self::ownerBaseFieldDefinitions($entity_type);
    $fields += self::revisionLogBaseFieldDefinitions($entity_type);

    $fields['tag'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Inventární číslo'))
      ->setDescription(new TranslatableMarkup('Prázdné = přidělí se automaticky podle typu.'))
      ->setSetting('max_length', 32)
      ->setRevisionable(TRUE)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 0]);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Typ'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values_function', 'hwdesk_asset_type_options')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 1])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 1]);

    $fields['manufacturer'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Výrobce'))
      ->setSetting('max_length', 128)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 2])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 2]);

    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Model'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 3]);

    $fields['serial_number'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Sériové číslo'))
      ->setSetting('max_length', 128)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 4])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 4]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Stav'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue(AssetStatus::InStock->value)
      ->setSetting('allowed_values_function', 'hwdesk_asset_status_options')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 5])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 5]);

    $fields['condition'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Stav kusu'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue(AssetCondition::NewItem->value)
      ->setSetting('allowed_values_function', 'hwdesk_asset_condition_options')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 6])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 6]);

    $fields['holder'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Držitel'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 7]);

    $fields['location'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Lokalita'))
      ->setSetting('target_type', 'hwdesk_location')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 8])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 8]);

    $fields['tags'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Štítky'))
      ->setSetting('target_type', 'hwdesk_tag')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => 9])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 9]);

    $fields['invoice'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Faktura'))
      ->setSetting('target_type', 'hwdesk_invoice')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 10])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 10]);

    $fields['price'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Pořizovací cena'))
      ->setSetting('precision', 12)
      ->setSetting('scale', 2)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 11])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'number_decimal', 'weight' => 11]);

    $fields['warranty_until'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Záruka do'))
      ->setSetting('datetime_type', 'date')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 12])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'datetime_default', 'weight' => 12, 'settings' => ['format_type' => 'html_date']]);

    $fields['cost_center'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Nákladové středisko'))
      ->setSetting('max_length', 64)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 13])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 13]);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Poznámka'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 14])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 14]);

    $fields['attachments'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Přílohy'))
      ->setSetting('uri_scheme', 'private')
      ->setSetting('file_directory', 'hwdesk/attachments')
      ->setSetting('file_extensions', 'pdf jpg jpeg png')
      ->setSetting('description_field', TRUE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'file_generic', 'weight' => 15])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'file_default', 'weight' => 15]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Vytvořeno'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Změněno'));

    return $fields;
  }

}
