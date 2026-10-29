<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\hwdesk\Controller\AssetController;
use Drupal\hwdesk\Controller\InvoicesController;
use Drupal\hwdesk\Controller\SettingsPagesController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Seznamy a detaily entit kreslíme sami, aby vypadaly jako zbytek HW Desku.
 *
 * Routy zůstávají Drupalí (odkazy z entit, akční odkazy, oprávnění), mění se
 * jen to, co je vykreslí.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  private const CONTROLLERS = [
    'entity.hwdesk_asset.canonical' => AssetController::class . '::detail',
    'entity.hwdesk_invoice.collection' => InvoicesController::class . '::page',
    'entity.hwdesk_invoice.canonical' => InvoicesController::class . '::detail',
    'entity.hwdesk_location.collection' => SettingsPagesController::class . '::locations',
    'entity.hwdesk_tag.collection' => SettingsPagesController::class . '::tags',
  ];

  protected function alterRoutes(RouteCollection $collection): void {
    foreach (self::CONTROLLERS as $name => $controller) {
      $route = $collection->get($name);
      if ($route === NULL) {
        continue;
      }
      $defaults = $route->getDefaults();
      unset($defaults['_entity_view'], $defaults['_entity_list'], $defaults['_controller']);
      $defaults['_controller'] = $controller;
      $route->setDefaults($defaults);
    }
  }

}
