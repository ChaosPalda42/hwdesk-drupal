<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\HandoverStatus;

/**
 * Counts for the overview page; every number becomes a link to a filtered list.
 */
final class DashboardStats {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * @return array<string, int>
   */
  public function byStatus(): array {
    $counts = [];
    foreach (AssetStatus::cases() as $case) {
      $counts[$case->value] = 0;
    }
    foreach ($this->groupCounts('status') as $key => $count) {
      if (isset($counts[$key])) {
        $counts[$key] = $count;
      }
    }
    return $counts;
  }

  /**
   * @return array<string, int>
   */
  public function byType(): array {
    $counts = $this->groupCounts('type');
    uksort($counts, static fn(string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);
    return $counts;
  }

  /**
   * @return array<int, int>
   */
  public function byLocation(): array {
    $with = [];
    $without = 0;
    foreach ($this->groupCounts('location') as $key => $count) {
      if ($key === '' || $key === 'NULL') {
        $without += $count;
      }
      else {
        $with[(int) $key] = $count;
      }
    }
    uksort($with, static fn(int $a, int $b): int => [$with[$b], $a] <=> [$with[$a], $b]);
    if ($without > 0) {
      $with[0] = $without;
    }
    return $with;
  }

  public function pendingHandovers(): int {
    return (int) $this->entityTypeManager->getStorage('hwdesk_handover')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', HandoverStatus::Pending->value)
      ->count()
      ->execute();
  }

  public function warrantyExpiring(int $days = 90): int {
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime("+$days days"));
    return (int) $this->entityTypeManager->getStorage('hwdesk_asset')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', [AssetStatus::Retired->value, AssetStatus::Lost->value], 'NOT IN')
      ->condition('warranty_until', $today, '>=')
      ->condition('warranty_until', $until, '<=')
      ->count()
      ->execute();
  }

  public function holders(): int {
    $result = $this->database->select('hwdesk_asset', 'a')
      ->fields('a', ['holder'])
      ->condition('a.status', [AssetStatus::Assigned->value, AssetStatus::PendingHandover->value, AssetStatus::PendingReturn->value], 'IN')
      ->isNotNull('a.holder')
      ->distinct()
      ->execute();
    return $result === NULL ? 0 : count($result->fetchCol());
  }

  /**
   * COUNT(*) per distinct value of one base-table column of hwdesk_asset.
   *
   * @return array<string, int>
   */
  private function groupCounts(string $column): array {
    $query = $this->database->select('hwdesk_asset', 'a');
    $query->addField('a', $column, 'k');
    $query->addExpression('COUNT(*)', 'n');
    $query->groupBy('a.' . $column);
    $result = $query->execute();
    $counts = [];
    foreach ($result === NULL ? [] : $result->fetchAll() as $row) {
      $counts[(string) ($row->k ?? '')] = (int) $row->n;
    }
    return $counts;
  }

}
