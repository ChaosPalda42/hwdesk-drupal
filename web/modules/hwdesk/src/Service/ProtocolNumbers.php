<?php
declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Service for generating protocol numbers.
 */
final class ProtocolNumbers {
  /**
   * Constructs a new ProtocolNumbers service.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the next protocol number and stores it.
   */
  public function next(): string {
    $year = (string) date('Y', $this->time->getRequestTime());
    $key = "hwdesk.protocol_seq.$year";
    $current = $this->state->get($key) ?? 0;
    $next = $current + 1;
    $this->state->set($key, $next);
    return $this->formatNumber($year, $next);
  }

  /**
   * Returns the next protocol number without storing it.
   */
  public function peek(): string {
    $year = (string) date('Y', $this->time->getRequestTime());
    $key = "hwdesk.protocol_seq.$year";
    $current = $this->state->get($key) ?? 0;
    $next = $current + 1;
    return $this->formatNumber($year, $next);
  }

  /**
   * Formats the protocol number.
   */
  private function formatNumber(string $year, int $seq): string {
    // Pad sequence to at least 4 digits, but allow longer numbers unchanged.
    $padded = str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    return "HP-{$year}-{$padded}";
  }
}
