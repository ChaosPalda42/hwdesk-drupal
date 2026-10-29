<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Předání a vrácení: co čeká na zaměstnance a co už je potvrzené.
 */
final class HandoversController extends ControllerBase {

  private const PER_PAGE = 50;

  public function __construct(
    private readonly Presenter $presenter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.presenter'));
  }

  /**
   * @return array<string, mixed>
   */
  public function page(Request $request): array {
    $filters = [];
    foreach (['status', 'kind', 'q'] as $key) {
      $value = trim((string) $request->query->get($key, ''));
      if ($value !== '') {
        $filters[$key] = $value;
      }
    }
    $storage = $this->entityTypeManager()->getStorage('hwdesk_handover');

    $total = (int) $this->query($filters)->count()->execute();
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = max(1, min((int) $request->query->get('page', 1), $pages));
    $ids = $this->query($filters)
      ->sort('created', 'DESC')
      ->range(($page - 1) * self::PER_PAGE, self::PER_PAGE)
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $handover) {
      if ($handover instanceof Handover) {
        $rows[] = $this->presenter->handover($handover);
      }
    }

    return [
      '#theme' => 'hwdesk_handovers',
      '#rows' => $rows,
      '#filters' => $filters,
      '#options' => [
        'statuses' => HandoverStatus::options(),
        'kinds' => HandoverKind::options(),
      ],
      '#base' => Url::fromRoute('hwdesk.handovers', [], ['query' => $filters ?: ['q' => '']])->toString(),
      '#page' => $page,
      '#pages' => $pages,
      '#total' => $total,
      '#counts' => $this->counts(),
      '#cache' => [
        'tags' => ['hwdesk_handover_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
    ];
  }

  /**
   * Kolik je čeho — do dlaždic nad seznamem.
   *
   * @return array<string, int>
   */
  private function counts(): array {
    $counts = [];
    foreach (HandoverStatus::cases() as $case) {
      $counts[$case->value] = (int) $this->entityTypeManager()->getStorage('hwdesk_handover')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', $case->value)
        ->count()
        ->execute();
    }
    return $counts;
  }

  /**
   * @param array<string, string> $filters
   */
  private function query(array $filters): \Drupal\Core\Entity\Query\QueryInterface {
    $query = $this->entityTypeManager()->getStorage('hwdesk_handover')->getQuery()->accessCheck(FALSE);
    foreach (['status', 'kind'] as $key) {
      if (isset($filters[$key])) {
        $query->condition($key, $filters[$key]);
      }
    }
    if (isset($filters['q'])) {
      $group = $query->orConditionGroup()
        ->condition('protocol_number', $filters['q'], 'CONTAINS')
        ->condition('asset.entity.tag', $filters['q'], 'CONTAINS')
        ->condition('asset.entity.serial_number', $filters['q'], 'CONTAINS');
      if (ctype_digit($filters['q'])) {
        $group->condition('id', (int) $filters['q']);
      }
      $query->condition($group);
    }
    return $query;
  }

}
