<?php

declare(strict_types=1);

namespace Drupal\hwdesk;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Direction of a handover request.
 */
enum HandoverKind: string {

  case Handover = 'handover';
  case ReturnItem = 'return';

  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Handover => new TranslatableMarkup('Předání'),
      self::ReturnItem => new TranslatableMarkup('Vrácení'),
    };
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
