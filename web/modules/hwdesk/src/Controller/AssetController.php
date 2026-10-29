<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\AuditEntry;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\Form\AssetStatusForm;
use Drupal\hwdesk\Form\RequestHandoverForm;
use Drupal\hwdesk\Service\HandoverService;
use Drupal\hwdesk\Service\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detail zařízení: přehled, historie, protokoly, soubory.
 */
final class AssetController extends ControllerBase {

  public function __construct(
    private readonly Presenter $presenter,
    private readonly HandoverService $handovers,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.presenter'), $container->get('hwdesk.handover'));
  }

  /**
   * @return array<string, mixed>
   */
  public function detail(Asset $hwdesk_asset): array {
    $open = $this->handovers->openFor($hwdesk_asset);

    return [
      '#theme' => 'hwdesk_asset',
      '#asset' => $this->presenter->asset($hwdesk_asset),
      '#handovers' => $this->assetHandovers($hwdesk_asset),
      '#history' => $this->assetHistory($hwdesk_asset),
      '#files' => $this->presenter->files('attachments', $hwdesk_asset),
      '#status_form' => $this->formBuilder()->getForm(AssetStatusForm::class, $hwdesk_asset),
      '#handover_form' => $this->formBuilder()->getForm(RequestHandoverForm::class, $hwdesk_asset),
      '#open_handover' => $open instanceof Handover ? $this->presenter->handover($open) : NULL,
      '#links' => [
        'edit' => $hwdesk_asset->toUrl('edit-form')->toString(),
        'label' => Url::fromRoute('hwdesk.labels', [], ['query' => ['ids' => $hwdesk_asset->id()]])->toString(),
        'assets' => Url::fromRoute('hwdesk.assets')->toString(),
        'type' => Url::fromRoute('hwdesk.assets', [], ['query' => ['type' => $hwdesk_asset->get('type')->value]])->toString(),
      ],
      '#cache' => [
        'tags' => array_merge($hwdesk_asset->getCacheTags(), ['hwdesk_handover_list', 'hwdesk_audit_list']),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function assetHandovers(Asset $asset): array {
    $storage = $this->entityTypeManager()->getStorage('hwdesk_handover');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('asset', $asset->id())
      ->sort('created', 'DESC')
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $handover) {
      if ($handover instanceof Handover) {
        $rows[] = $this->presenter->handover($handover);
      }
    }
    return $rows;
  }

  /**
   * Co se se zařízením dělo. Bere se z auditu, ne z revizí — audit ví i to,
   * co se stalo mimo formulář (potvrzení, vrácení, hromadná úprava).
   *
   * @return array<int, array<string, mixed>>
   */
  private function assetHistory(Asset $asset): array {
    $storage = $this->entityTypeManager()->getStorage('hwdesk_audit');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_type', 'hwdesk_asset')
      ->condition('target_id', (int) $asset->id())
      ->sort('created', 'DESC')
      ->range(0, 100)
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $entry) {
      if ($entry instanceof AuditEntry) {
        $rows[] = $this->presenter->audit($entry);
      }
    }
    return $rows;
  }

}
