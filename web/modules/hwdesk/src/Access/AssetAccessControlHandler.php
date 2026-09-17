<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\hwdesk\Entity\Asset;

/**
 * Administrators do everything; an employee may view what they hold.
 */
final class AssetAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($operation === 'view' && $entity instanceof Asset) {
      $holder = $entity->getHolder();
      $own = $holder !== NULL && (int) $holder->id() === (int) $account->id();
      return AccessResult::allowedIf($own)
        ->andIf(AccessResult::allowedIfHasPermission($account, 'view own hwdesk assets'))
        ->addCacheableDependency($entity)
        ->cachePerUser();
    }
    return AccessResult::neutral();
  }

}
