<?php

declare(strict_types=1);

namespace Drupal\hwdesk;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Physical condition of a piece of hardware.
 */
enum AssetCondition: string {

  case NewItem = 'new';
  case Good = 'good';
  case Worn = 'worn';
  case Broken = 'broken';

  public function label(): TranslatableMarkup {
    return match ($this) {
      self::NewItem => new TranslatableMarkup('Nové'),
      self::Good => new TranslatableMarkup('Dobrý'),
      self::Worn => new TranslatableMarkup('Opotřebené'),
      self::Broken => new TranslatableMarkup('Poškozené'),
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
