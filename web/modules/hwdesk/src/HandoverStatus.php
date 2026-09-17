<?php

declare(strict_types=1);

namespace Drupal\hwdesk;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * State of a handover or return request.
 */
enum HandoverStatus: string {

  case Pending = 'pending';
  case Confirmed = 'confirmed';
  case Rejected = 'rejected';
  case Expired = 'expired';
  case Cancelled = 'cancelled';

  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Pending => new TranslatableMarkup('Čeká na potvrzení'),
      self::Confirmed => new TranslatableMarkup('Potvrzeno'),
      self::Rejected => new TranslatableMarkup('Odmítnuto'),
      self::Expired => new TranslatableMarkup('Vypršelo'),
      self::Cancelled => new TranslatableMarkup('Zrušeno'),
    };
  }

  public function isOpen(): bool {
    return $this === self::Pending;
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
