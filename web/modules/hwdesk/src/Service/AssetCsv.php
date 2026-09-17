<?php
declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\AssetCondition;
use Drupal\hwdesk\Entity\Asset;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Service for exporting and importing assets as CSV.
 */
final class AssetCsv {
  public const COLUMNS = [
    'tag',
    'type',
    'manufacturer',
    'model',
    'serial_number',
    'status',
    'condition',
    'holder',
    'location',
    'tags',
    'invoice',
    'price',
    'warranty_until',
    'cost_center',
    'notes',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TagGenerator $tagGenerator,
  ) {}

  /**
   * Export assets to CSV string.
   */
  public function export(iterable $assets): string {
    $lines = [];
    // Header.
    $lines[] = implode(';', self::COLUMNS);
    foreach ($assets as $asset) {
      if (!$asset instanceof Asset) {
        continue;
      }
      $row = [];
      $row[] = $this->maybeQuote($asset->getTag());
      $row[] = $this->maybeQuote($asset->getType());
      $row[] = $this->maybeQuote($asset->getManufacturer());
      $row[] = $this->maybeQuote($asset->getModel());
      $row[] = $this->maybeQuote($asset->getSerialNumber());
      $row[] = $this->maybeQuote($asset->getStatus()->value);
      $row[] = $this->maybeQuote($asset->getCondition()->value);
      $holder = $asset->getHolder();
      $row[] = $this->maybeQuote($holder ? $holder->getEmail() : '');
      $location = $asset->getLocation();
      $row[] = $this->maybeQuote($location ? (string) $location->label() : '');
      // Tags.
      $tagNames = [];
      foreach ($asset->getTags() as $t) {
        $tagNames[] = (string) $t->label();
      }
      $row[] = $this->maybeQuote(implode('|', $tagNames));
      $invoice = $asset->getInvoice();
      $row[] = $this->maybeQuote($invoice ? (string) $invoice->label() : '');
      $price = $asset->get('price')->value;
      $row[] = $this->maybeQuote(($price === NULL || $price === '') ? '' : number_format((float) $price, 2, '.', ''));
      $warranty = $asset->get('warranty_until')->value;
      if ($warranty) {
        $date = new \DateTime($warranty);
        $row[] = $this->maybeQuote($date->format('Y-m-d'));
      }
      else {
        $row[] = $this->maybeQuote('');
      }
      $row[] = $this->maybeQuote((string) $asset->get('cost_center')->value);
      $row[] = $this->maybeQuote((string) $asset->get('notes')->value);
      $lines[] = implode(';', $row);
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
  }

  /**
   * Import CSV string.
   */
  public function import(string $csv): array {
    // Strip BOM.
    if (str_starts_with($csv, "\xEF\xBB\xBF")) {
      $csv = substr($csv, 3);
    }
    $rawLines = preg_split('/\r\n|\n/', $csv);
    $lines = [];
    foreach ($rawLines as $l) {
      if (trim((string) $l) !== '') {
        $lines[] = $l;
      }
    }
    $result = ['created' => 0, 'updated' => 0, 'errors' => []];
    if (empty($lines)) {
      return $result;
    }
    $header = array_shift($lines);
    $headerCells = array_map('trim', str_getcsv($header, ';'));
    if ($headerCells !== self::COLUMNS) {
      throw new InvalidArgumentException('Invalid CSV header.');
    }

    $assetStorage = $this->entityTypeManager->getStorage('hwdesk_asset');
    $userStorage = $this->entityTypeManager->getStorage('user');
    $locationStorage = $this->entityTypeManager->getStorage('hwdesk_location');
    $tagStorage = $this->entityTypeManager->getStorage('hwdesk_tag');
    $invoiceStorage = $this->entityTypeManager->getStorage('hwdesk_invoice');

    $typeConfig = $this->configFactory->get('hwdesk.settings')->get('asset_types') ?? [];

    foreach ($lines as $idx => $line) {
      $rowNum = $idx + 2; // data rows start at line 2.
      $cells = array_map('trim', str_getcsv($line, ';', '"', '\\'));
      if (count($cells) !== count(self::COLUMNS)) {
        $result['errors'][] = "řádek {$rowNum}: incorrect column count";
        continue;
      }
      [$tag, $type, $manufacturer, $model, $serial_number, $status, $condition, $holderEmail, $locationName, $tagsStr, $invoiceNumber, $priceStr, $warrantyStr, $cost_center, $notes] = $cells;

      // Validation.
      if (!array_key_exists($type, $typeConfig)) {
        $result['errors'][] = "řádek {$rowNum}: unknown type '{$type}'";
        continue;
      }
      if ($model === '') {
        $result['errors'][] = "řádek {$rowNum}: model is empty";
        continue;
      }
      // Status.
      $statusVal = $status !== '' ? $status : AssetStatus::InStock->value;
      try {
        $statusEnum = AssetStatus::from($statusVal);
      } catch (\ValueError) {
        $result['errors'][] = "řádek {$rowNum}: unknown status '{$status}'";
        continue;
      }
      // Condition.
      $condVal = $condition !== '' ? $condition : AssetCondition::NewItem->value;
      try {
        $condEnum = AssetCondition::from($condVal);
      } catch (\ValueError) {
        $result['errors'][] = "řádek {$rowNum}: unknown condition '{$condition}'";
        continue;
      }
      // Holder.
      $holder = NULL;
      if ($holderEmail !== '') {
        $userIds = $userStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('mail', strtolower($holderEmail), '=')
          ->range(0, 1)
          ->execute();
        if (empty($userIds)) {
          $result['errors'][] = "řádek {$rowNum}: unknown holder '{$holderEmail}'";
          continue;
        }
        $holder = $userStorage->load(reset($userIds));
      }
      // Invoice handling.
      $invoice = NULL;
      if ($invoiceNumber !== '') {
        $invIds = $invoiceStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('number', $invoiceNumber)
          ->range(0, 1)
          ->execute();
        if (empty($invIds)) {
          $result['errors'][] = "řádek {$rowNum}: unknown invoice '{$invoiceNumber}'";
          continue;
        }
        $invoice = $invoiceStorage->load(reset($invIds));
      }
      // Location.
      $location = NULL;
      if ($locationName !== '') {
        $locIds = $locationStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('name', $locationName)
          ->range(0, 1)
          ->execute();
        if (empty($locIds)) {
          $location = $locationStorage->create(['name' => $locationName]);
          $location->save();
        }
        else {
          $location = $locationStorage->load(reset($locIds));
        }
      }
      // Tags.
      $tagEntities = [];
      if ($tagsStr !== '') {
        $tagNames = array_filter(array_map('trim', explode('|', $tagsStr)), fn(string $t) => $t !== '');
        foreach ($tagNames as $tn) {
          $tagIds = $tagStorage->getQuery()
            ->accessCheck(FALSE)
            ->condition('name', $tn)
            ->range(0, 1)
            ->execute();
          if (empty($tagIds)) {
            $newTag = $tagStorage->create(['name' => $tn]);
            $newTag->save();
            $tagEntities[] = $newTag;
          }
          else {
            $tagEntities[] = $tagStorage->load(reset($tagIds));
          }
        }
      }

      // Load or create asset.
      $asset = NULL;
      if ($tag !== '') {
        $existing = $assetStorage->loadByProperties(['tag' => strtoupper($tag)]);
        if (!empty($existing)) {
          $asset = reset($existing);
        }
      }

      if ($asset) {
        /** @var Asset $asset */
        $asset->set('type', $type)
          ->set('manufacturer', $manufacturer)
          ->set('model', $model)
          ->set('serial_number', $serial_number)
          ->setStatus($statusEnum)
          ->set('condition', $condEnum->value);
        $asset->setHolder($holder);
        $asset->set('location', $location ? $location->id() : NULL);
        $asset->set('tags', array_map(fn($e) => $e->id(), $tagEntities));
        $asset->set('invoice', $invoice ? $invoice->id() : NULL);
        $asset->set('price', $priceStr === '' ? NULL : $priceStr);
        $asset->set('warranty_until', $warrantyStr === '' ? NULL : $warrantyStr);
        $asset->set('cost_center', $cost_center);
        $asset->set('notes', $notes);
        $asset->save();
        $result['updated']++;
      }
      else {
        // Create new asset.
        if ($tag === '') {
          $generatedTag = $this->tagGenerator->next($type);
        }
        else {
          $generatedTag = strtoupper($tag);
          $this->tagGenerator->reserve($generatedTag);
        }
        $newAsset = $assetStorage->create([
          'tag' => $generatedTag,
          'type' => $type,
          'manufacturer' => $manufacturer,
          'model' => $model,
          'serial_number' => $serial_number,
          'status' => $statusEnum->value,
          'condition' => $condEnum->value,
          'holder' => $holder ? $holder->id() : NULL,
          'location' => $location ? $location->id() : NULL,
          'tags' => array_map(fn($e) => $e->id(), $tagEntities),
          'invoice' => $invoice ? $invoice->id() : NULL,
          'price' => $priceStr === '' ? NULL : $priceStr,
          'warranty_until' => $warrantyStr === '' ? NULL : $warrantyStr,
          'cost_center' => $cost_center,
          'notes' => $notes,
        ]);
        $newAsset->save();
        $result['created']++;
      }
    }

    return $result;
  }

  /**
   * Quote a CSV field when needed.
   */
  private function maybeQuote(string $value): string {
    if (strpbrk($value, ";\"\r\n") !== false) {
      $escaped = str_replace('"', '""', $value);
      return "\"{$escaped}\"";
    }
    return $value;
  }
}
?>