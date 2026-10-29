<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\AuditEntry;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\Service\DashboardStats;
use Drupal\hwdesk\Service\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The overview: tiles plus three short lists, every number a link to a list.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly DashboardStats $stats,
    private readonly Presenter $presenter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.dashboard_stats'), $container->get('hwdesk.presenter'));
  }

  /**
   * @return array<string, mixed>
   */
  public function page(): array {
    $list = static fn(array $query): string => Url::fromRoute('hwdesk.assets', [], ['query' => $query])->toString();
    $types = (array) $this->config('hwdesk.settings')->get('asset_types');

    $tiles = [];
    foreach ($this->stats->byStatus() as $status => $count) {
      $tiles[] = ['label' => AssetStatus::from($status)->label(), 'count' => $count, 'url' => $list(['status' => $status]), 'key' => $status];
    }
    $tiles[] = ['label' => $this->t('Čeká na potvrzení'), 'count' => $this->stats->pendingHandovers(), 'url' => Url::fromRoute('hwdesk.handovers', [], ['query' => ['status' => 'pending']])->toString(), 'key' => 'pending'];
    $tiles[] = ['label' => $this->t('Záruka končí do 90 dnů'), 'count' => $this->stats->warrantyExpiring(), 'url' => $list(['warranty_until' => ['min' => date('Y-m-d'), 'max' => date('Y-m-d', strtotime('+90 days'))]]), 'key' => 'warranty'];
    $tiles[] = ['label' => $this->t('Držitelů'), 'count' => $this->stats->holders(), 'url' => $list(['status' => AssetStatus::Assigned->value]), 'key' => 'holders'];

    $pending = [];
    foreach ($this->stats->pendingHandoverIds(10) as $id) {
      $handover = $this->entityTypeManager()->getStorage('hwdesk_handover')->load($id);
      if ($handover instanceof Handover) {
        $pending[] = $this->presenter->handover($handover);
      }
    }

    $warranty = [];
    foreach ($this->stats->warrantyExpiringIds(90, 10) as $id) {
      $asset = $this->entityTypeManager()->getStorage('hwdesk_asset')->load($id);
      if ($asset instanceof Asset) {
        $warranty[] = $this->presenter->asset($asset);
      }
    }

    $activity = [];
    foreach ($this->stats->recentAuditIds(10) as $id) {
      $entry = $this->entityTypeManager()->getStorage('hwdesk_audit')->load($id);
      if ($entry instanceof AuditEntry) {
        $activity[] = $this->presenter->audit($entry);
      }
    }

    $byType = [];
    foreach ($this->stats->byType() as $type => $count) {
      $byType[] = ['label' => $types[$type] ?? $type, 'count' => $count, 'url' => $list(['type' => $type])];
    }

    $byLocation = [];
    $locationIds = array_filter(array_keys($this->stats->byLocation()));
    $locations = $this->entityTypeManager()->getStorage('hwdesk_location')->loadMultiple($locationIds);
    foreach ($this->stats->byLocation() as $id => $count) {
      $location = $locations[$id] ?? NULL;
      $label = $id === 0 ? $this->t('Bez lokality') : ($location instanceof Location ? $location->getName() : (string) $id);
      $byLocation[] = ['label' => $label, 'count' => $count, 'url' => $id === 0 ? $list([]) : $list(['location' => $id])];
    }

    return [
      '#theme' => 'hwdesk_dashboard',
      '#totals' => $this->stats->totals(),
      '#tiles' => $tiles,
      '#pending' => $pending,
      '#warranty' => $warranty,
      '#activity' => $activity,
      '#by_type' => $byType,
      '#by_location' => $byLocation,
      '#attached' => ['library' => ['hwdesk/ui']],
      '#cache' => ['tags' => ['hwdesk_asset_list', 'hwdesk_handover_list', 'hwdesk_audit_list'], 'contexts' => ['user.permissions']],
    ];
  }

}
