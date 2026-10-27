<?php

declare(strict_types=1);

namespace Drupal\csobrec;

/**
 * One call as the hand-over cares about it.
 *
 * Immutable on purpose: selection rules are pure functions over these, so a
 * call can be read twice and still be the same call.
 */
final class Call {

  public function __construct(
    public readonly string $activity,
    public readonly string $queue,
    public readonly \DateTimeImmutable $time,
    public readonly ?string $disposition,
    public readonly string $phone,
    public readonly string $operator,
    public readonly ?string $policyNumber = NULL,
    public readonly bool $xSell = FALSE,
    public readonly ?string $xSellNumber = NULL,
  ) {}

}
