<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\hwdesk\Access\HandoverAccessControlHandler;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\user\UserInterface;
use Drupal\views\EntityViewsData;

/**
 * A handover or return request: asset, employee, confirmation, protocol.
 *
 * Created by the handover service only; there is no add/edit form.
 */
#[ContentEntityType(
  id: 'hwdesk_handover',
  label: new TranslatableMarkup('Předání'),
  label_collection: new TranslatableMarkup('Předání a vrácení'),
  label_singular: new TranslatableMarkup('předání'),
  label_plural: new TranslatableMarkup('předání'),
  entity_keys: ['id' => 'id', 'label' => 'protocol_number', 'uuid' => 'uuid'],
  handlers: [
    'access' => HandoverAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
    'list_builder' => EntityListBuilder::class,
  ],
  admin_permission: 'administer hwdesk',
  base_table: 'hwdesk_handover',
  label_count: ['singular' => '@count předání', 'plural' => '@count předání'],
)]
final class Handover extends ContentEntityBase {

  use EntityChangedTrait;

  public function getAsset(): ?Asset {
    $asset = $this->get('asset')->entity;
    return $asset instanceof Asset ? $asset : NULL;
  }

  /**
   * The employee who must confirm.
   */
  public function getUser(): ?UserInterface {
    $user = $this->get('user')->entity;
    return $user instanceof UserInterface ? $user : NULL;
  }

  public function getRequestedBy(): ?UserInterface {
    $user = $this->get('requested_by')->entity;
    return $user instanceof UserInterface ? $user : NULL;
  }

  public function getKind(): HandoverKind {
    return HandoverKind::from((string) $this->get('kind')->value);
  }

  public function getStatus(): HandoverStatus {
    return HandoverStatus::from((string) $this->get('status')->value);
  }

  public function setStatus(HandoverStatus $status): static {
    $this->set('status', $status->value);
    return $this;
  }

  public function getExpires(): int {
    return (int) $this->get('expires')->value;
  }

  public function getDecided(): ?int {
    $value = $this->get('decided')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  public function getReason(): string {
    return (string) $this->get('reason')->value;
  }

  public function getProtocolNumber(): string {
    return (string) $this->get('protocol_number')->value;
  }

  public function getProtocolFile(): ?FileInterface {
    $file = $this->get('protocol_file')->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['asset'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Zařízení'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'hwdesk_asset')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 0]);

    $fields['user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Zaměstnanec'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 1]);

    $fields['requested_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Zadal'))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 2]);

    $fields['kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Druh'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values_function', 'hwdesk_handover_kind_options')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 3]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Stav'))
      ->setRequired(TRUE)
      ->setDefaultValue(HandoverStatus::Pending->value)
      ->setSetting('allowed_values_function', 'hwdesk_handover_status_options')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 4]);

    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Platnost do'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'timestamp', 'weight' => 5]);

    $fields['decided'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Rozhodnuto'))
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'timestamp', 'weight' => 6]);

    $fields['reason'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Důvod odmítnutí'))
      ->setSetting('max_length', 500)
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 7]);

    $fields['protocol_number'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Číslo protokolu'))
      ->setSetting('max_length', 32)
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'string', 'weight' => 8]);

    $fields['protocol_file'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Protokol (PDF)'))
      ->setSetting('uri_scheme', 'private')
      ->setSetting('file_directory', 'hwdesk/protocols')
      ->setSetting('file_extensions', 'pdf')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'file_default', 'weight' => 9]);

    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Vytvořeno'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Změněno'));
    return $fields;
  }

}
