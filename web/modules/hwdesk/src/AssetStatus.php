<?php

declare(strict_types=1);

namespace Drupal\hwdesk;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Lifecycle of one piece of hardware.
 *
 * in_stock -> pending_handover -> assigned -> pending_return -> in_stock;
 * retired and lost are set by hand from in_stock or assigned.
 */
enum AssetStatus: string {

  case InStock = 'in_stock';
  case PendingHandover = 'pending_handover';
  case Assigned = 'assigned';
  case PendingReturn = 'pending_return';
  case Retired = 'retired';
  case Lost = 'lost';

  public function label(): TranslatableMarkup {
    return match ($this) {
      self::InStock => new TranslatableMarkup('Skladem'),
      self::PendingHandover => new TranslatableMarkup('Čeká na převzetí'),
      self::Assigned => new TranslatableMarkup('Přiděleno'),
      self::PendingReturn => new TranslatableMarkup('Čeká na vrácení'),
      self::Retired => new TranslatableMarkup('Vyřazeno'),
      self::Lost => new TranslatableMarkup('Ztraceno'),
    };
  }

  /**
   * Whether a manual or workflow change from this status to $to is allowed.
   */
  public function canTransitionTo(self $to): bool {
    return in_array($to, match ($this) {
      self::InStock => [self::PendingHandover, self::Retired, self::Lost],
      self::PendingHandover => [self::Assigned, self::InStock],
      self::Assigned => [self::PendingReturn, self::InStock, self::Lost, self::Retired],
      self::PendingReturn => [self::InStock, self::Assigned],
      self::Retired => [self::InStock],
      self::Lost => [self::InStock],
    }, TRUE);
  }

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function options(): array {
    $options = [];
    foreach (self::cases() as $case) {
      $options[$case->value] = $case->label();
    }
    return $options;
  }

}
