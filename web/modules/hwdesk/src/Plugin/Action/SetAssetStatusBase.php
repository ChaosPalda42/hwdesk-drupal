<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk status change; rows whose status cannot transition are left alone.
 */
abstract class SetAssetStatusBase extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_user'));
  }

  abstract protected function target(): AssetStatus;

  public function execute($entity = NULL): void {
    if ($entity instanceof Asset && $entity->getStatus()->canTransitionTo($this->target())) {
      $entity->setStatus($this->target());
      if (in_array($this->target(), [AssetStatus::Retired, AssetStatus::Lost, AssetStatus::InStock], TRUE)) {
        $entity->setHolder(NULL);
      }
      $entity->setNewRevision();
      $entity->setRevisionLogMessage('Hromadná akce: ' . $this->target()->label());
      $entity->save();
    }
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowedIfHasPermission($account ?? $this->currentUser, 'administer hwdesk');
    return $return_as_object ? $result : $result->isAllowed();
  }

}
