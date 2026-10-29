<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Sloupec vlevo: skupiny, odkazy a to, který z nich je zapnutý.
 *
 * Jedna definice pro všechny stránky — co tu není, v nabídce nebude.
 */
final class Navigation {

  use StringTranslationTrait;

  /**
   * Nabídka v nastavení. Klíč je jméno routy, hodnota popisek.
   */
  public const SETTINGS = [
    'hwdesk.settings' => 'Obecné',
    'hwdesk.settings.email' => 'E-mail (SMTP)',
    'hwdesk.settings.handover' => 'Předávací protokol',
    'hwdesk.settings.labels' => 'Inventární čísla a štítky',
    'entity.hwdesk_location.collection' => 'Lokality',
    'entity.hwdesk_tag.collection' => 'Štítky',
  ];

  /**
   * Routy, u kterých má být v nabídce zapnutá položka nastavení.
   */
  private const SETTINGS_ROUTES = [
    'hwdesk.settings', 'hwdesk.settings.email', 'hwdesk.settings.handover', 'hwdesk.settings.labels',
    'entity.hwdesk_location.collection', 'entity.hwdesk_location.add_form', 'entity.hwdesk_location.edit_form', 'entity.hwdesk_location.delete_form',
    'entity.hwdesk_tag.collection', 'entity.hwdesk_tag.add_form', 'entity.hwdesk_tag.edit_form', 'entity.hwdesk_tag.delete_form',
  ];

  /**
   * Která routa zapne kterou položku. Jméno položky -> routy, co ji zapínají.
   */
  private const ACTIVE = [
    'my' => ['hwdesk.my'],
    'dashboard' => ['hwdesk.dashboard'],
    'assets' => [
      'hwdesk.assets', 'entity.hwdesk_asset.canonical', 'entity.hwdesk_asset.add_form',
      'entity.hwdesk_asset.edit_form', 'entity.hwdesk_asset.delete_form',
      'entity.hwdesk_asset.version_history', 'entity.hwdesk_asset.revision',
      'hwdesk.asset.handover', 'hwdesk.asset.label', 'hwdesk.import', 'hwdesk.labels', 'hwdesk.labels.zpl',
    ],
    'intake' => ['hwdesk.intake', 'hwdesk.intake.serials'],
    'handovers' => ['hwdesk.handovers', 'hwdesk.handover.cancel', 'hwdesk.handover.confirm'],
    'employees' => ['hwdesk.employees', 'hwdesk.employee'],
    'invoices' => [
      'entity.hwdesk_invoice.collection', 'entity.hwdesk_invoice.canonical',
      'entity.hwdesk_invoice.add_form', 'entity.hwdesk_invoice.edit_form', 'entity.hwdesk_invoice.delete_form',
    ],
    'audit' => ['hwdesk.audit'],
  ];

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * @return array<string, mixed>
   *   Data pro šablonu hwdesk-nav.html.twig.
   */
  public function build(RouteMatchInterface $route_match): array {
    $route = (string) $route_match->getRouteName();
    $admin = $this->currentUser->hasPermission('administer hwdesk');
    $in_settings = in_array($route, self::SETTINGS_ROUTES, TRUE);

    $items = [
      $this->item('my', 'home', new TranslatableMarkup('Moje zařízení'), 'hwdesk.my', $route),
    ];
    $groups = [];
    if ($admin) {
      $groups[] = [
        'label' => new TranslatableMarkup('Správa'),
        'items' => [
          $this->item('dashboard', 'home', new TranslatableMarkup('Přehled'), 'hwdesk.dashboard', $route),
          $this->item('assets', 'laptop', new TranslatableMarkup('Zařízení'), 'hwdesk.assets', $route),
          $this->item('intake', 'inbox', new TranslatableMarkup('Příjem zařízení'), 'hwdesk.intake', $route),
          $this->item('handovers', 'handover', new TranslatableMarkup('Předání a vrácení'), 'hwdesk.handovers', $route),
          $this->item('employees', 'users', new TranslatableMarkup('Zaměstnanci'), 'hwdesk.employees', $route),
        ],
      ];
      $groups[] = [
        'label' => new TranslatableMarkup('Evidence'),
        'items' => [
          $this->item('invoices', 'invoice', new TranslatableMarkup('Faktury'), 'entity.hwdesk_invoice.collection', $route),
          $this->item('audit', 'audit', new TranslatableMarkup('Audit'), 'hwdesk.audit', $route),
          [
            'name' => 'settings',
            'icon' => 'settings',
            'label' => new TranslatableMarkup('Nastavení'),
            'route' => 'hwdesk.settings',
            'active' => $in_settings,
            'sub' => $in_settings ? $this->settingsSub($route) : [],
          ],
        ],
      ];
    }

    return [
      'items' => $items,
      'groups' => $groups,
      'is_admin' => $admin,
      'account' => $this->currentUser->isAuthenticated() ? $this->currentUser->getAccountName() : NULL,
      'role_label' => $admin ? new TranslatableMarkup('správce') : new TranslatableMarkup('zaměstnanec'),
    ];
  }

  /**
   * Řada záložek nad obsahem stránek nastavení.
   *
   * @return array<int, array<string, mixed>>
   */
  public function settingsTabs(string $route): array {
    $tabs = [];
    foreach (self::SETTINGS as $name => $label) {
      $tabs[] = ['route' => $name, 'label' => $label, 'active' => $name === $route];
    }
    return $tabs;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function settingsSub(string $route): array {
    return $this->settingsTabs($route);
  }

  /**
   * @return array<string, mixed>
   */
  private function item(string $name, string $icon, TranslatableMarkup $label, string $route, string $current): array {
    return [
      'name' => $name,
      'icon' => $icon,
      'label' => $label,
      'route' => $route,
      'active' => in_array($current, self::ACTIVE[$name] ?? [], TRUE),
      'sub' => [],
    ];
  }

}
