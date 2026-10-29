<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Seznam zařízení podle filtrů: co se hledá, jak se řadí a kolikátá stránka.
 *
 * Filtry jsou tytéž jako v předloze. Neznámý filtr se zahodí, aby se do
 * dotazu nedostalo nic, co nepochází odsud.
 */
final class AssetQuery {

  public const PER_PAGE = 50;

  /**
   * Pole, kterými se smí řadit. Klíč je to, co přijde v adrese.
   */
  private const SORTABLE = [
    'tag' => 'tag',
    'type' => 'type',
    'status' => 'status',
    'warranty_until' => 'warranty_until',
    'price' => 'price',
    'created' => 'created',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Filtry očištěné z adresy. Co tu není, dál neputuje.
   *
   * @param array<string, mixed> $query
   *
   * @return array<string, string>
   */
  public function filters(array $query): array {
    $filters = [];
    foreach (['q', 'type', 'status', 'condition', 'tag', 'location', 'holder', 'warranty'] as $key) {
      $value = trim((string) ($query[$key] ?? ''));
      if ($value !== '') {
        $filters[$key] = $value;
      }
    }
    return $filters;
  }

  /**
   * @param array<string, string> $filters
   *
   * @return array{ids: list<int>, total: int, page: int, pages: int}
   */
  public function execute(array $filters, string $sort = 'tag', int $page = 1): array {
    $total = (int) $this->query($filters)->count()->execute();
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = max(1, min($page, $pages));

    $field = ltrim($sort, '-');
    $field = self::SORTABLE[$field] ?? 'tag';
    $direction = str_starts_with($sort, '-') ? 'DESC' : 'ASC';

    $ids = $this->query($filters)
      ->sort($field, $direction)
      ->sort('id', 'ASC')
      ->range(($page - 1) * self::PER_PAGE, self::PER_PAGE)
      ->execute();

    return [
      'ids' => array_values(array_map('intval', $ids)),
      'total' => $total,
      'page' => $page,
      'pages' => $pages,
    ];
  }

  /**
   * Všechna id, která filtru odpovídají — pro hromadné akce a export.
   *
   * @param array<string, string> $filters
   *
   * @return list<int>
   */
  public function allIds(array $filters): array {
    return array_values(array_map('intval', $this->query($filters)->execute()));
  }

  /**
   * @param array<string, string> $filters
   */
  private function query(array $filters): \Drupal\Core\Entity\Query\QueryInterface {
    $query = $this->entityTypeManager->getStorage('hwdesk_asset')->getQuery()->accessCheck(TRUE);

    if (isset($filters['q'])) {
      $like = $query->orConditionGroup()
        ->condition('tag', $filters['q'], 'CONTAINS')
        ->condition('name', $filters['q'], 'CONTAINS')
        ->condition('serial_number', $filters['q'], 'CONTAINS')
        ->condition('model', $filters['q'], 'CONTAINS')
        ->condition('manufacturer', $filters['q'], 'CONTAINS')
        ->condition('oem', $filters['q'], 'CONTAINS')
        ->condition('cost_center', $filters['q'], 'CONTAINS');
      $query->condition($like);
    }
    foreach (['type' => 'type', 'status' => 'status', 'condition' => 'condition'] as $key => $field) {
      if (isset($filters[$key])) {
        $query->condition($field, $filters[$key]);
      }
    }
    if (isset($filters['tag'])) {
      $query->condition('tags', (int) $filters['tag']);
    }
    if (isset($filters['location'])) {
      $query->condition('location', (int) $filters['location']);
    }
    if (isset($filters['holder'])) {
      $query->condition('holder', (int) $filters['holder']);
    }
    if (isset($filters['warranty'])) {
      $today = date('Y-m-d');
      if ($filters['warranty'] === 'expired') {
        $query->condition('warranty_until', $today, '<');
      }
      elseif (ctype_digit($filters['warranty'])) {
        $query->condition('warranty_until', $today, '>=');
        $query->condition('warranty_until', date('Y-m-d', strtotime('+' . (int) $filters['warranty'] . ' days')), '<=');
      }
    }
    return $query;
  }

}
