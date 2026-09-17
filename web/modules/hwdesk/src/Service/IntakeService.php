<?php
declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\hwdesk\Entity\Asset;
use InvalidArgumentException;

/**
 * Service for intake of assets.
 */
final class IntakeService {
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TagGenerator $tagGenerator,
  ) {}

  /**
   * Create a batch of assets.
   *
   * @param array $values
   *   Asset field values. Keys: type, model, manufacturer, condition, location,
   *   tags, invoice, price, warranty_until, cost_center, notes.
   * @param int $count
   *   Number of assets to create.
   *
   * @return Asset[]
   *   List of saved assets in tag order.
   */
  public function createBatch(array $values, int $count): array {
    if ($count < 1 || $count > 500) {
      throw new InvalidArgumentException('Count must be between 1 and 500.');
    }
    $type = $values['type'] ?? null;
    $model = $values['model'] ?? null;
    if (!is_string($type) || trim($type) === '' || !is_string($model) || trim($model) === '') {
      throw new InvalidArgumentException('Both type and model are required and must be non‑empty.');
    }
    $assets = [];
    for ($i = 0; $i < $count; $i++) {
      $tag = $this->tagGenerator->next($type);
      $data = [
        'type' => $type,
        'model' => $model,
        'tag' => $tag,
        'status' => 'in_stock',
        'holder' => NULL,
        // serial_number left empty by default
      ];
      // optional fields
      if (isset($values['manufacturer'])) {
        $data['manufacturer'] = $values['manufacturer'];
      }
      $condition = $values['condition'] ?? 'new';
      $data['condition'] = $condition;
      if (array_key_exists('location', $values)) {
        $data['location'] = $values['location'];
      }
      if (!empty($values['tags'])) {
        $data['tags'] = $values['tags'];
      }
      if (array_key_exists('invoice', $values)) {
        $data['invoice'] = $values['invoice'];
      }
      if (array_key_exists('price', $values)) {
        $data['price'] = $values['price'];
      }
      if (!empty($values['warranty_until'])) {
        $data['warranty_until'] = $values['warranty_until'];
      }
      if (array_key_exists('cost_center', $values)) {
        $data['cost_center'] = $values['cost_center'];
      }
      if (array_key_exists('notes', $values)) {
        $data['notes'] = $values['notes'];
      }

      /** @var Asset $asset */
      $asset = $this->entityTypeManager->getStorage('hwdesk_asset')->create($data);
      $asset->save();
      $assets[] = $asset;
    }
    // sort by tag to ensure order
    usort($assets, fn(Asset $a, Asset $b) => strcmp($a->getTag(), $b->getTag()));
    return $assets;
  }

  /**
   * Set serial numbers for assets.
   *
   * @param array $serialsByAssetId
   *   Map of asset id => serial string.
   */
  public function setSerialNumbers(array $serialsByAssetId): void {
    if (empty($serialsByAssetId)) {
      return;
    }
    // Validate duplicate serials in input (case‑insensitive).
    $lowerMap = [];
    foreach ($serialsByAssetId as $id => $serial) {
      $trimmed = trim((string) $serial);
      if ($trimmed === '') {
        continue; // skip blanks
      }
      $lc = mb_strtolower($trimmed);
      if (isset($lowerMap[$lc])) {
        throw new InvalidArgumentException('Duplicate serial number in input: ' . $trimmed);
      }
      $lowerMap[$lc] = $id;
    }

    // Load assets and check existence.
    $storage = $this->entityTypeManager->getStorage('hwdesk_asset');
    $ids = array_keys($serialsByAssetId);
    /** @var Asset[] $assets */
    $assets = $storage->loadMultiple($ids);
    foreach ($ids as $id) {
      if (!isset($assets[$id])) {
        throw new InvalidArgumentException('Unknown asset id: ' . $id);
      }
    }

    // Check existing serials in DB (case‑insensitive).
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('serial_number', NULL, '<>');
    $existingIds = $query->execute();
    if (!empty($existingIds)) {
      /** @var Asset[] $existing */
      $existing = $storage->loadMultiple($existingIds);
      foreach ($existing as $asset) {
        $sid = $asset->id();
        // Skip assets we are updating.
        if (isset($serialsByAssetId[$sid])) {
          continue;
        }
        $sn = trim((string) $asset->getSerialNumber());
        if ($sn === '') {
          continue;
        }
        $lcSn = mb_strtolower($sn);
        if (isset($lowerMap[$lcSn])) {
          throw new InvalidArgumentException("Serial number already used: {$sn}");
        }
      }
    }

    // All validations passed, set serials.
    foreach ($serialsByAssetId as $id => $serial) {
      $trimmed = trim((string) $serial);
      if ($trimmed === '') {
        continue;
      }
      /** @var Asset $asset */
      $asset = $assets[$id];
      $asset->set('serial_number', $trimmed);
      $asset->save();
    }
  }
}
