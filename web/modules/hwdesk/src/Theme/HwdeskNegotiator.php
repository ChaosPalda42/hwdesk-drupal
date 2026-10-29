<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Stránky HW Desku se kreslí vlastním vzhledem, zbytek webu se nemění.
 *
 * Služba má vyšší prioritu než Drupalí volba administračního vzhledu, jinak
 * by routy pod /admin a routy entit (ty jsou značené jako administrační)
 * skončily v Claru.
 */
final class HwdeskNegotiator implements ThemeNegotiatorInterface {

  public const THEME = 'hwdesk_ui';

  public function applies(RouteMatchInterface $route_match): bool {
    $name = (string) $route_match->getRouteName();
    if ($name === 'hwdesk.qr') {
      // Jen přesměrování, nic se nekreslí.
      return FALSE;
    }
    return str_starts_with($name, 'hwdesk.') || str_starts_with($name, 'entity.hwdesk_');
  }

  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return self::THEME;
  }

}
