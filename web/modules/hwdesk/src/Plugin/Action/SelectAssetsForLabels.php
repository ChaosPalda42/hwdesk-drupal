<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Remember the selection and offer the label sheet, ZPL print and CSV export.
 */
#[Action(id: 'hwdesk_asset_labels', label: new TranslatableMarkup('Štítky / export vybraných zařízení'), type: 'hwdesk_asset')]
final class SelectAssetsForLabels extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly MessengerInterface $messenger,
    private readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('tempstore.private'), $container->get('messenger'), $container->get('current_user'));
  }

  public function executeMultiple(array $entities): void {
    $ids = array_values(array_map(static fn($e): int => (int) $e->id(), $entities));
    $this->tempStoreFactory->get('hwdesk')->set('label_selection', $ids);
    $query = ['ids' => implode(',', $ids)];
    $this->messenger->addStatus(new TranslatableMarkup('Vybráno @count: @labels · @zpl · @csv', [
      '@count' => count($ids),
      '@labels' => Link::fromTextAndUrl(new TranslatableMarkup('štítky k tisku'), Url::fromRoute('hwdesk.labels', [], ['query' => $query]))->toString(),
      '@zpl' => Link::fromTextAndUrl(new TranslatableMarkup('tisk na Zebru'), Url::fromRoute('hwdesk.labels.zpl', [], ['query' => $query]))->toString(),
      '@csv' => Link::fromTextAndUrl(new TranslatableMarkup('export CSV'), Url::fromRoute('hwdesk.export', [], ['query' => $query]))->toString(),
    ]));
  }

  public function execute($entity = NULL): void {
    if ($entity !== NULL) {
      $this->executeMultiple([$entity]);
    }
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowedIfHasPermission($account ?? $this->currentUser, 'administer hwdesk');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
