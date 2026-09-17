<?php
declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use InvalidArgumentException;

/**
 * Service for generating inventory tags.
 */
final class TagGenerator {
  private ConfigFactoryInterface $configFactory;
  private StateInterface $state;

  public function __construct(ConfigFactoryInterface $configFactory, StateInterface $state) {
    $this->configFactory = $configFactory;
    $this->state = $state;
  }

  /**
   * Get next tag for a given type and increment the counter.
   */
  public function next(string $type): string {
    $prefix = $this->resolvePrefix($type);
    $key = "hwdesk.tag_seq.$prefix";
    $seq = $this->state->get($key) ?? 0;
    $nextSeq = $seq + 1;
    $this->state->set($key, $nextSeq);
    return $this->formatTag($prefix, $nextSeq);
  }

  /**
   * Peek at the next tag without incrementing.
   */
  public function peek(string $type): string {
    $prefix = $this->resolvePrefix($type);
    $key = "hwdesk.tag_seq.$prefix";
    $seq = $this->state->get($key) ?? 0;
    $nextSeq = $seq + 1;
    return $this->formatTag($prefix, $nextSeq);
  }

  /**
   * Reserve a manually chosen tag.
   */
  public function reserve(string $tag): void {
    if (!$this->isValid($tag)) {
      throw new InvalidArgumentException("Malformed tag: $tag");
    }
    [$prefix, $number] = explode('-', $tag);
    $num = (int) $number;
    $key = "hwdesk.tag_seq.$prefix";
    $current = $this->state->get($key) ?? 0;
    if ($num > $current) {
      $this->state->set($key, $num);
    }
  }

  /**
   * Validate tag format.
   */
  public function isValid(string $tag): bool {
    return (bool) preg_match('/^[A-Z0-9]{1,8}-\d+$/', $tag);
  }

  /**
   * Resolve prefix for a given type using configuration.
   */
  private function resolvePrefix(string $type): string {
    $config = $this->configFactory->get('hwdesk.settings');
    $prefixes = $config->get('tag_prefixes') ?? [];
    if (isset($prefixes[$type])) {
      return $prefixes[$type];
    }
    // fallback to 'other'
    if (isset($prefixes['other'])) {
      return $prefixes['other'];
    }
    return 'HW';
  }

  /**
   * Format tag string with padding.
   */
  private function formatTag(string $prefix, int $number): string {
    $config = $this->configFactory->get('hwdesk.settings');
    $pad = (int) ($config->get('tag_pad') ?? 4);
    $numStr = str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    return $prefix . '-' . $numStr;
  }
}
