<?php
declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\hwdesk\Entity\Asset;
use InvalidArgumentException;

/**
 * Receiving goods: one form creates N assets, then serial numbers are set.
 */
final class IntakeService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TagGenerator $tagGenerator,
  ) {}

  /**
   * Create a batch of assets from one set of values.
   *
   * @param array<string, mixed> $values
   *   Asset field values. Keys: type, model, manufacturer, condition,
   *   location, tags, invoice, price, warranty_until, cost_center, notes.
   *   Unknown keys are ignored.
   * @param int $count
   *   Number of assets to create, 1 to 500.
   *
   * @return Asset[]
   *   List of saved assets, in tag order.
   */
  public function createBatch(array $values, int $count): array {
    if ($count < 1 || $count > 500) {
      throw new InvalidArgumentException('Počet kusů musí být od 1 do 500.');
    }
    $type = $values['type'] ?? '';
    $model = $values['model'] ?? '';
    if (!is_string($type) || trim($type) === '' || !is_string($model) || trim($model) === '') {
      throw new InvalidArgumentException('Vyplňte typ zařízení i model.');
    }

    $storage = $this->entityTypeManager->getStorage('hwdesk_asset');
    $assets = [];
    for ($i = 0; $i < $count; $i++) {
      $data = [
        'tag' => $this->tagGenerator->next($type),
        'type' => $type,
        'model' => $model,
        'status' => 'in_stock',
        'holder' => NULL,
        'serial_number' => '',
        'condition' => $values['condition'] ?? 'new',
      ];
      if (isset($values['manufacturer'])) {
        $data['manufacturer'] = $values['manufacturer'];
      }
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
      $asset = $storage->create($data);
      $asset->save();
      $assets[] = $asset;
    }
    return $assets;
  }

  /**
   * Set serial numbers on assets; all-or-nothing.
   *
   * @param array<int, string> $serialsByAssetId
   *   Map of asset id => serial number. Blank values are skipped.
   */
  public function setSerialNumbers(array $serialsByAssetId): void {
    $storage = $this->entityTypeManager->getStorage('hwdesk_asset');

    // Every asset id must exist.
    foreach (array_keys($serialsByAssetId) as $id) {
      if ($storage->load($id) === NULL) {
        throw new InvalidArgumentException(sprintf('Zařízení č. %d neexistuje.', $id));
      }
    }

    // Trim, skip blanks, and reject the same serial twice (case-insensitive).
    $serials = [];
    $seen = [];
    foreach ($serialsByAssetId as $id => $serial) {
      $trimmed = trim((string) $serial);
      if ($trimmed === '') {
        continue;
      }
      $key = mb_strtolower($trimmed);
      if (isset($seen[$key])) {
        throw new InvalidArgumentException(sprintf('Sériové číslo %s je v zadání dvakrát.', $trimmed));
      }
      $seen[$key] = TRUE;
      $serials[$id] = $trimmed;
    }

    // Reject serials already used by a different existing asset.
    foreach ($serials as $id => $serial) {
      $like = str_replace(['%', '_'], ['\\%', '\\_'], $serial);
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('serial_number', $like, 'LIKE');
      $query->condition('id', $id, '<>');
      foreach ($query->execute() as $clashId) {
        /** @var Asset|null $clash */
        $clash = $storage->load($clashId);
        if ($clash === NULL) {
          continue;
        }
        if (mb_strtolower(trim($clash->getSerialNumber())) === mb_strtolower($serial)) {
          throw new InvalidArgumentException(sprintf('Sériové číslo %s už má zařízení %s.', $serial, $clash->getTag()));
        }
      }
    }

    // Everything validated; now write.
    foreach ($serials as $id => $serial) {
      /** @var Asset $asset */
      $asset = $storage->load($id);
      $asset->set('serial_number', $serial);
      $asset->save();
    }
  }

}
