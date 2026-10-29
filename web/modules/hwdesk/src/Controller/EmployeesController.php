<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\Service\Presenter;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Zaměstnanci: kdo má co u sebe a co komu teprve chystáme.
 */
final class EmployeesController extends ControllerBase {

  public function __construct(
    private readonly Presenter $presenter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.presenter'));
  }

  public function title(UserInterface $user): string {
    return $user->getDisplayName();
  }

  /**
   * @return array<string, mixed>
   */
  public function page(Request $request): array {
    $query = trim((string) $request->query->get('q', ''));
    $storage = $this->entityTypeManager()->getStorage('user');

    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->condition('status', 1)
      ->sort('name')
      ->execute();
    if ($query !== '') {
      $uids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', 0, '>')
        ->condition($storage->getQuery()->orConditionGroup()
          ->condition('name', $query, 'CONTAINS')
          ->condition('mail', $query, 'CONTAINS'))
        ->sort('name')
        ->execute();
    }

    $rows = [];
    foreach ($storage->loadMultiple($uids) as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }
      $held = $this->assetIds('holder', (int) $user->id());
      $prepared = $this->assetIds('prepared_for', (int) $user->id());
      $pending = (int) $this->entityTypeManager()->getStorage('hwdesk_handover')->getQuery()
        ->accessCheck(FALSE)
        ->condition('user', $user->id())
        ->condition('status', 'pending')
        ->count()
        ->execute();
      if ($held === [] && $prepared === [] && $pending === 0 && $query === '') {
        // Bez hardwaru a bez rozdělané výzvy není co v evidenci ukazovat.
        continue;
      }
      $rows[] = [
        'id' => (int) $user->id(),
        'name' => $user->getDisplayName(),
        'mail' => $user->getEmail(),
        'url' => \Drupal\Core\Url::fromRoute('hwdesk.employee', ['user' => $user->id()])->toString(),
        'assets' => count($held),
        'prepared' => count($prepared),
        'pending' => $pending,
      ];
    }

    return [
      '#theme' => 'hwdesk_employees',
      '#rows' => $rows,
      '#query' => $query,
      '#total' => count($rows),
      '#cache' => [
        'tags' => ['hwdesk_asset_list', 'hwdesk_handover_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function detail(UserInterface $user): array {
    $assets = [];
    $storage = $this->entityTypeManager()->getStorage('hwdesk_asset');
    foreach ($storage->loadMultiple(array_merge($this->assetIds('holder', (int) $user->id()), $this->assetIds('prepared_for', (int) $user->id()))) as $asset) {
      if ($asset instanceof Asset) {
        $assets[] = $this->presenter->asset($asset);
      }
    }

    $handovers = [];
    $handover_storage = $this->entityTypeManager()->getStorage('hwdesk_handover');
    $ids = $handover_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user', $user->id())
      ->sort('created', 'DESC')
      ->execute();
    foreach ($handover_storage->loadMultiple($ids) as $handover) {
      if ($handover instanceof Handover) {
        $handovers[] = $this->presenter->handover($handover);
      }
    }

    return [
      '#theme' => 'hwdesk_employee',
      '#employee' => [
        'id' => (int) $user->id(),
        'name' => $user->getDisplayName(),
        'mail' => $user->getEmail(),
        'active' => $user->isActive(),
        'roles' => array_values(array_filter($user->getRoles(), static fn(string $r): bool => $r !== 'authenticated')),
      ],
      '#assets' => $assets,
      '#handovers' => $handovers,
      '#cache' => [
        'tags' => array_merge($user->getCacheTags(), ['hwdesk_asset_list', 'hwdesk_handover_list']),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * @return list<int>
   */
  private function assetIds(string $field, int $uid): array {
    return array_values(array_map('intval', $this->entityTypeManager()->getStorage('hwdesk_asset')->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $uid)
      ->sort('tag')
      ->execute()));
  }

}
