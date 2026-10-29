<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the signed-in employee holds and what they still have to confirm.
 */
final class MyAssetsController extends ControllerBase {

  public function __construct(
    private readonly Presenter $presenter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.presenter'));
  }

  /**
   * @return array<string, mixed>
   */
  public function page(): array {
    $uid = (int) $this->currentUser()->id();
    $handoverStorage = $this->entityTypeManager()->getStorage('hwdesk_handover');
    $assetStorage = $this->entityTypeManager()->getStorage('hwdesk_asset');

    // Open handovers: the mailed link is single use, so the page only points
    // at the token-free confirmation route for the logged-in employee.
    $pending = [];
    $pendingIds = $handoverStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user', $uid)
      ->condition('status', HandoverStatus::Pending->value)
      ->sort('created', 'DESC')
      ->execute();
    foreach ($handoverStorage->loadMultiple($pendingIds) as $handover) {
      if (!$handover instanceof Handover) {
        continue;
      }
      $row = $this->presenter->handover($handover);
      $row['confirm_url'] = Url::fromRoute('hwdesk.handover.confirm_own', ['hwdesk_handover' => $handover->id()])->toString();
      $pending[] = $row;
    }

    $assets = [];
    $assetIds = $assetStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('holder', $uid)
      ->condition('status', [AssetStatus::Assigned->value, AssetStatus::PendingReturn->value, AssetStatus::PendingHandover->value], 'IN')
      ->sort('tag')
      ->execute();
    foreach ($assetStorage->loadMultiple($assetIds) as $asset) {
      if ($asset instanceof Asset) {
        $assets[] = $this->presenter->asset($asset);
      }
    }

    // Decided handovers: the protocol file is already in the row and the
    // module's file download hook lets the employee fetch their own copy.
    $handovers = [];
    $handoverIds = $handoverStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user', $uid)
      ->condition('status', [HandoverStatus::Confirmed->value, HandoverStatus::Rejected->value], 'IN')
      ->sort('decided', 'DESC')
      ->range(0, 50)
      ->execute();
    foreach ($handoverStorage->loadMultiple($handoverIds) as $handover) {
      if ($handover instanceof Handover) {
        $handovers[] = $this->presenter->handover($handover);
      }
    }

    return [
      '#theme' => 'hwdesk_my',
      '#account' => $this->currentUser()->getDisplayName(),
      '#assets' => $assets,
      '#pending' => $pending,
      '#handovers' => $handovers,
      '#attached' => ['library' => ['hwdesk/ui']],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['hwdesk_asset_list', 'hwdesk_handover_list'],
      ],
    ];
  }

}
