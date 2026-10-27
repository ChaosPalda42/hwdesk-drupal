<?php

declare(strict_types=1);

namespace Drupal\csobrec;

/**
 * One line of a batch: a call, the policy number to report, and which copy.
 *
 * An X-sell call is handed over twice — the second copy carries the X-sell
 * proposal number instead of the original one — so the call alone is not
 * enough to identify a line.
 */
final class Item {

  public function __construct(
    public readonly Call $call,
    public readonly ?string $policyNumber,
    public readonly int $copy,
  ) {}

}
