<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\HandoverService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the signed-in employee holds and what they still have to confirm.
 */
final class MyAssetsController extends ControllerBase {

  public function __construct(private readonly HandoverService $handovers) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.handover'));
  }

  public function page(): array {
    $uid = (int) $this->currentUser()->id();
    $handoverStorage = $this->entityTypeManager()->getStorage('hwdesk_handover');
    $pendingIds = $handoverStorage->getQuery()->accessCheck(FALSE)
      ->condition('user', $uid)->condition('status', HandoverStatus::Pending->value)->sort('created', 'DESC')->execute();
    $pending = [];
    foreach ($handoverStorage->loadMultiple($pendingIds) as $handover) {
      assert($handover instanceof Handover);
      $asset = $handover->getAsset();
      $pending[] = [
        $handover->getKind()->label(),
        $asset ? $asset->getTag() : '',
        $asset ? $asset->getDisplayName() : '',
        $this->dateFormatter()->format($handover->getExpires(), 'custom', 'j. n. Y H:i'),
        Link::fromTextAndUrl($this->t('Potvrdit / odmítnout'), Url::fromUri($this->handovers->confirmUrl($handover))),
      ];
    }

    $assetStorage = $this->entityTypeManager()->getStorage('hwdesk_asset');
    $ids = $assetStorage->getQuery()->accessCheck(FALSE)
      ->condition('holder', $uid)
      ->condition('status', [AssetStatus::Assigned->value, AssetStatus::PendingReturn->value, AssetStatus::PendingHandover->value], 'IN')
      ->sort('tag')->execute();
    $rows = [];
    foreach ($assetStorage->loadMultiple($ids) as $asset) {
      assert($asset instanceof Asset);
      $rows[] = [
        Link::createFromRoute($asset->getTag(), 'entity.hwdesk_asset.canonical', ['hwdesk_asset' => $asset->id()]),
        $asset->getDisplayName(),
        $asset->getSerialNumber(),
        $asset->getStatus()->label(),
        $asset->getStatus() === AssetStatus::Assigned
          ? Link::createFromRoute($this->t('Vrátit'), 'hwdesk.asset.handover', ['hwdesk_asset' => $asset->id()])
          : '',
      ];
    }

    $build = ['#cache' => ['contexts' => ['user'], 'tags' => ['hwdesk_asset_list', 'hwdesk_handover_list']]];
    if ($pending) {
      $build['pending'] = [
        '#type' => 'table',
        '#caption' => $this->t('Čeká na vaše potvrzení'),
        '#header' => [$this->t('Druh'), $this->t('Inv. číslo'), $this->t('Zařízení'), $this->t('Platnost do'), ''],
        '#rows' => $pending,
      ];
    }
    $build['assets'] = [
      '#type' => 'table',
      '#caption' => $this->t('Moje zařízení'),
      '#header' => [$this->t('Inv. číslo'), $this->t('Zařízení'), $this->t('Sériové číslo'), $this->t('Stav'), ''],
      '#rows' => $rows,
      '#empty' => $this->t('Nemáte u sebe žádné zařízení.'),
    ];
    return $build;
  }

}
