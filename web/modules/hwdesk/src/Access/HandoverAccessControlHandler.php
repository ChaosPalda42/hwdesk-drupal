<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\hwdesk\Entity\Handover;

/**
 * Administrators do everything; the addressed employee may view their own.
 */
final class HandoverAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    $admin = AccessResult::allowedIfHasPermission($account, 'administer hwdesk');
    if ($operation === 'view' && $entity instanceof Handover) {
      $user = $entity->getUser();
      $own = $user !== NULL && (int) $user->id() === (int) $account->id();
      return $admin->orIf(
        AccessResult::allowedIf($own)
          ->andIf(AccessResult::allowedIfHasPermission($account, 'confirm own hwdesk handovers'))
          ->addCacheableDependency($entity)
          ->cachePerUser()
      );
    }
    return $admin;
  }

}
