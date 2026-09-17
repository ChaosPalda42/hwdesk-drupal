<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\user\UserInterface;

/**
 * A blocked account (HR offboarding) still holding hardware: tell the
 * administrators what has to come back.
 */
final class Offboarding {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * @return list<\Drupal\hwdesk\Entity\Asset>
   */
  public function heldBy(UserInterface $user): array {
    $storage = $this->entityTypeManager->getStorage('hwdesk_asset');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('holder', $user->id())
      ->condition('status', [AssetStatus::Assigned->value, AssetStatus::PendingHandover->value, AssetStatus::PendingReturn->value], 'IN')
      ->sort('tag')
      ->execute();
    $assets = [];
    foreach ($storage->loadMultiple($ids) as $asset) {
      if ($asset instanceof Asset) {
        $assets[] = $asset;
      }
    }
    return $assets;
  }

  /**
   * Mails the list of held assets to every administrator (and the protocol
   * copy address); returns the number of assets found.
   */
  public function userBlocked(UserInterface $user): int {
    $assets = $this->heldBy($user);
    if (!$assets) {
      return 0;
    }
    $lines = [
      sprintf('Účet %s (%s) byl zablokován a má u sebe %d zařízení:', $user->getDisplayName(), $user->getEmail(), count($assets)),
      '',
    ];
    foreach ($assets as $asset) {
      $lines[] = sprintf('- %s · %s · %s', $asset->getTag(), $asset->getDisplayName(), $asset->getSerialNumber() ?: 'bez S/N');
    }
    $lines[] = '';
    $lines[] = 'Vrácení potvrďte v HW Desku (Zařízení → detail → Předat / vrátit).';
    $params = ['subject' => sprintf('[HW Desk] Offboarding: %s má u sebe %d zařízení', $user->getDisplayName(), count($assets)), 'body' => $lines];
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    foreach ($this->recipients() as $to) {
      $this->mailManager->mail('hwdesk', 'offboarding', $to, $langcode, $params, NULL, TRUE);
    }
    return count($assets);
  }

  /**
   * @return list<string>
   */
  private function recipients(): array {
    $to = [];
    $copy = strtolower(trim((string) $this->configFactory->get('hwdesk.settings')->get('protocol_copy_to')));
    if ($copy !== '') {
      $to[] = $copy;
    }
    $roleStorage = $this->entityTypeManager->getStorage('user_role');
    $roles = [];
    foreach ($roleStorage->loadMultiple() as $role) {
      if ($role->hasPermission('administer hwdesk') || $role->isAdmin()) {
        $roles[] = $role->id();
      }
    }
    if ($roles) {
      $userStorage = $this->entityTypeManager->getStorage('user');
      $ids = $userStorage->getQuery()->accessCheck(FALSE)->condition('status', 1)->condition('roles', $roles, 'IN')->execute();
      foreach ($userStorage->loadMultiple($ids) as $admin) {
        $mail = strtolower((string) $admin->getEmail());
        if ($mail !== '' && !in_array($mail, $to, TRUE)) {
          $to[] = $mail;
        }
      }
    }
    return $to;
  }

}
