<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\Entity\AuditEntry;
use Drupal\hwdesk\Service\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Audit: kdo co kdy v evidenci změnil.
 */
final class AuditController extends ControllerBase {

  private const PER_PAGE = 100;

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
    foreach (['action', 'target_type', 'q'] as $key) {
      $value = trim((string) $request->query->get($key, ''));
      if ($value !== '') {
        $filters[$key] = $value;
      }
    }

    $total = (int) $this->query($filters)->count()->execute();
    $pages = max(1, (int) ceil($total / self::PER_PAGE));
    $page = max(1, min((int) $request->query->get('page', 1), $pages));
    $ids = $this->query($filters)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range(($page - 1) * self::PER_PAGE, self::PER_PAGE)
      ->execute();

    $rows = [];
    foreach ($this->entityTypeManager()->getStorage('hwdesk_audit')->loadMultiple($ids) as $entry) {
      if ($entry instanceof AuditEntry) {
        $rows[] = $this->presenter->audit($entry);
      }
    }

    return [
      '#theme' => 'hwdesk_audit',
      '#rows' => $rows,
      '#filters' => $filters,
      '#options' => [
        'actions' => $this->distinct('action'),
        'targets' => [
          'hwdesk_asset' => $this->t('Zařízení'),
          'hwdesk_handover' => $this->t('Předání'),
          'hwdesk_invoice' => $this->t('Faktura'),
          'hwdesk_location' => $this->t('Lokalita'),
          'hwdesk_tag' => $this->t('Štítek'),
          'user' => $this->t('Zaměstnanec'),
        ],
      ],
      '#base' => Url::fromRoute('hwdesk.audit', [], ['query' => $filters ?: ['q' => '']])->toString(),
      '#page' => $page,
      '#pages' => $pages,
      '#total' => $total,
      '#cache' => [
        'tags' => ['hwdesk_audit_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
    ];
  }

  /**
   * @return array<string, string>
   */
  private function distinct(string $field): array {
    $values = [];
    foreach ($this->entityTypeManager()->getStorage('hwdesk_audit')->loadMultiple(
      $this->entityTypeManager()->getStorage('hwdesk_audit')->getQuery()->accessCheck(FALSE)->range(0, 500)->sort('id', 'DESC')->execute()
    ) as $entry) {
      $value = (string) $entry->get($field)->value;
      if ($value !== '') {
        $values[$value] = $value;
      }
    }
    asort($values);
    return $values;
  }

  /**
   * @param array<string, string> $filters
   */
  private function query(array $filters): \Drupal\Core\Entity\Query\QueryInterface {
    $query = $this->entityTypeManager()->getStorage('hwdesk_audit')->getQuery()->accessCheck(FALSE);
    foreach (['action', 'target_type'] as $key) {
      if (isset($filters[$key])) {
        $query->condition($key, $filters[$key]);
      }
    }
    if (isset($filters['q'])) {
      $query->condition($query->orConditionGroup()
        ->condition('target_label', $filters['q'], 'CONTAINS')
        ->condition('message', $filters['q'], 'CONTAINS'));
    }
    return $query;
  }

}
