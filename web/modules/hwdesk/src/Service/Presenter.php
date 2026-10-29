<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\hwdesk\AssetCondition;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\AuditEntry;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\Entity\Invoice;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;

/**
 * Z entit dělá řádky pro šablony.
 *
 * Šablony tak nemusí znát pole ani enumy a stránky vypadají všude stejně:
 * jeden sloupec se zařízením se skládá pořád z téhož.
 */
final class Presenter {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function asset(Asset $asset): array {
    $status = AssetStatus::tryFrom((string) $asset->get('status')->value);
    $condition = AssetCondition::tryFrom((string) $asset->get('condition')->value);
    $holder = $asset->get('holder')->entity;
    $prepared = $asset->get('prepared_for')->entity;
    $location = $asset->get('location')->entity;
    $invoice = $asset->get('invoice')->entity;

    return [
      'id' => (int) $asset->id(),
      'url' => $asset->toUrl()->toString(),
      'edit_url' => $asset->toUrl('edit-form')->toString(),
      'tag' => (string) $asset->get('tag')->value,
      'name' => (string) $asset->get('name')->value,
      'type' => (string) $asset->get('type')->value,
      'type_label' => $this->typeLabel((string) $asset->get('type')->value),
      'manufacturer' => (string) $asset->get('manufacturer')->value,
      'oem' => (string) $asset->get('oem')->value,
      'model' => (string) $asset->get('model')->value,
      'serial_number' => (string) $asset->get('serial_number')->value,
      'status' => $status?->value ?? '',
      'status_label' => $status?->label() ?? '',
      'condition' => $condition?->value ?? '',
      'condition_label' => $condition?->label() ?? '',
      'holder' => $holder ? ['name' => $holder->getDisplayName(), 'url' => $this->employeeUrl((int) $holder->id())] : NULL,
      'prepared_for' => $prepared ? ['name' => $prepared->getDisplayName(), 'url' => $this->employeeUrl((int) $prepared->id())] : NULL,
      'location' => $location ? ['name' => $location->label(), 'id' => (int) $location->id()] : NULL,
      'invoice' => $invoice ? ['number' => $invoice->label(), 'url' => $invoice->toUrl()->toString()] : NULL,
      'tags' => $this->tags($asset),
      'price' => $asset->get('price')->value !== NULL ? (float) $asset->get('price')->value : NULL,
      'warranty_until' => (string) $asset->get('warranty_until')->value,
      'warranty_label' => $this->day((string) $asset->get('warranty_until')->value),
      'cost_center' => (string) $asset->get('cost_center')->value,
      'notes' => (string) $asset->get('notes')->value,
      'created' => $this->moment((int) $asset->get('created')->value),
      'changed' => $this->moment((int) $asset->get('changed')->value),
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function tags(Asset $asset): array {
    $tags = [];
    foreach ($asset->get('tags')->referencedEntities() as $tag) {
      $tags[] = [
        'id' => (int) $tag->id(),
        'name' => $tag->label(),
        'color' => (string) ($tag->get('color')->value ?: 'gray'),
      ];
    }
    return $tags;
  }

  /**
   * @return array<string, mixed>
   */
  public function handover(Handover $handover): array {
    $status = HandoverStatus::tryFrom((string) $handover->get('status')->value);
    $kind = HandoverKind::tryFrom((string) $handover->get('kind')->value);
    $asset = $handover->get('asset')->entity;
    $user = $handover->get('user')->entity;
    $requested_by = $handover->get('requested_by')->entity;
    $file = $handover->get('protocol_file')->entity;

    return [
      'id' => (int) $handover->id(),
      'kind' => $kind?->value ?? '',
      'kind_label' => $kind?->label() ?? '',
      'status' => $status?->value ?? '',
      'status_label' => $status?->label() ?? '',
      'is_open' => $status?->isOpen() ?? FALSE,
      'asset' => $asset instanceof Asset ? $this->asset($asset) : NULL,
      'user' => $user ? ['name' => $user->getDisplayName(), 'url' => $this->employeeUrl((int) $user->id())] : NULL,
      'requested_by' => $requested_by?->getDisplayName(),
      'created' => $this->moment((int) $handover->get('created')->value),
      'expires' => $this->moment((int) $handover->get('expires')->value),
      'decided' => $handover->get('decided')->value ? $this->moment((int) $handover->get('decided')->value) : '',
      'consented' => $handover->get('consented')->value ? $this->moment((int) $handover->get('consented')->value) : '',
      'reason' => (string) $handover->get('reason')->value,
      'protocol_number' => (string) $handover->get('protocol_number')->value,
      'protocol_url' => $file ? $file->createFileUrl(FALSE) : NULL,
      'cancel_url' => Url::fromRoute('hwdesk.handover.cancel', ['hwdesk_handover' => $handover->id()])->toString(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function invoice(Invoice $invoice): array {
    return [
      'id' => (int) $invoice->id(),
      'url' => $invoice->toUrl()->toString(),
      'edit_url' => $invoice->toUrl('edit-form')->toString(),
      'number' => (string) $invoice->get('number')->value,
      'supplier' => (string) $invoice->get('supplier')->value,
      'issued_on' => (string) $invoice->get('issued_on')->value,
      'issued_label' => $this->day((string) $invoice->get('issued_on')->value),
      'total' => $invoice->get('total')->value !== NULL ? (float) $invoice->get('total')->value : NULL,
      'currency' => (string) ($invoice->get('currency')->value ?: 'CZK'),
      'notes' => (string) $invoice->get('notes')->value,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function audit(AuditEntry $entry): array {
    $user = $entry->get('uid')->entity;
    return [
      'id' => (int) $entry->id(),
      'when' => $this->moment((int) $entry->get('created')->value),
      'who' => $user?->getDisplayName() ?? '—',
      'action' => (string) $entry->get('action')->value,
      'target_type' => (string) $entry->get('target_type')->value,
      'target_id' => (int) $entry->get('target_id')->value,
      'target_label' => (string) $entry->get('target_label')->value,
      'target_url' => $this->targetUrl((string) $entry->get('target_type')->value, (int) $entry->get('target_id')->value),
      'message' => (string) $entry->get('message')->value,
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function files(string $field, Asset|Invoice $entity): array {
    $files = [];
    foreach ($entity->get($field)->referencedEntities() as $file) {
      $files[] = [
        'name' => $file->getFilename(),
        'url' => $file->createFileUrl(FALSE),
        'size' => (string) ByteSizeMarkup::create((int) $file->getSize()),
        'when' => $this->moment((int) $file->getCreatedTime()),
      ];
    }
    return $files;
  }

  public function typeLabel(string $type): string {
    $types = $this->configFactory->get('hwdesk.settings')->get('asset_types') ?? [];
    return (string) ($types[$type] ?? $type);
  }

  public function moment(?int $timestamp): string {
    return $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'j. n. Y H:i') : '';
  }

  public function day(?string $date): string {
    if ($date === NULL || $date === '') {
      return '';
    }
    $time = strtotime($date);
    return $time === FALSE ? '' : $this->dateFormatter->format($time, 'custom', 'j. n. Y');
  }

  private function employeeUrl(int $uid): string {
    return Url::fromRoute('hwdesk.employee', ['user' => $uid])->toString();
  }

  private function targetUrl(string $type, int $id): ?string {
    return match ($type) {
      'hwdesk_asset' => Url::fromRoute('entity.hwdesk_asset.canonical', ['hwdesk_asset' => $id])->toString(),
      'hwdesk_invoice' => Url::fromRoute('entity.hwdesk_invoice.canonical', ['hwdesk_invoice' => $id])->toString(),
      'hwdesk_handover' => Url::fromRoute('hwdesk.handovers', [], ['query' => ['q' => $id]])->toString(),
      default => NULL,
    };
  }

}
