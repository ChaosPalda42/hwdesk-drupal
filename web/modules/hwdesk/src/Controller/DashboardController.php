<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\DashboardStats;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The overview: every number is a link to the asset list with that filter.
 */
final class DashboardController extends ControllerBase {

  public function __construct(private readonly DashboardStats $stats) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.dashboard_stats'));
  }

  public function page(): array {
    $list = static fn(array $query): Url => Url::fromRoute('view.hwdesk_assets.page', [], ['query' => $query]);
    $types = (array) $this->config('hwdesk.settings')->get('asset_types');

    $tiles = [];
    foreach ($this->stats->byStatus() as $status => $count) {
      $tiles[] = ['label' => AssetStatus::from($status)->label(), 'count' => $count, 'url' => $list(['status' => $status]), 'key' => $status];
    }
    $tiles[] = ['label' => $this->t('Čeká na potvrzení'), 'count' => $this->stats->pendingHandovers(), 'url' => Url::fromRoute('view.hwdesk_handovers.page', [], ['query' => ['status' => HandoverStatus::Pending->value]]), 'key' => 'pending'];
    $tiles[] = ['label' => $this->t('Záruka končí do 90 dnů'), 'count' => $this->stats->warrantyExpiring(), 'url' => $list(['warranty_until' => ['min' => date('Y-m-d'), 'max' => date('Y-m-d', strtotime('+90 days'))]]), 'key' => 'warranty'];
    $tiles[] = ['label' => $this->t('Držitelů'), 'count' => $this->stats->holders(), 'url' => $list(['status' => AssetStatus::Assigned->value]), 'key' => 'holders'];

    $byType = [];
    foreach ($this->stats->byType() as $type => $count) {
      $byType[] = ['label' => $types[$type] ?? $type, 'count' => $count, 'url' => $list(['type' => $type])];
    }
    $byLocation = [];
    $locations = Location::loadMultiple(array_filter(array_keys($this->stats->byLocation())));
    foreach ($this->stats->byLocation() as $id => $count) {
      $label = $id === 0 ? $this->t('Bez lokality') : ($locations[$id] ?? NULL)?->getName() ?? (string) $id;
      $byLocation[] = ['label' => $label, 'count' => $count, 'url' => $id === 0 ? $list([]) : $list(['location' => $id])];
    }

    return [
      '#theme' => 'hwdesk_dashboard',
      '#tiles' => $tiles,
      '#by_type' => $byType,
      '#by_location' => $byLocation,
      '#attached' => ['library' => ['hwdesk/ui']],
      '#cache' => ['tags' => ['hwdesk_asset_list', 'hwdesk_handover_list'], 'contexts' => ['user.permissions']],
    ];
  }

}
