<?php

declare(strict_types=1);

namespace Drupal\csobrec;

/**
 * How a queue is handed over to ČSOB.
 *
 * From the client's "Fronty_ukládání hovoru" table: either every call of the
 * queue is handed over, or only calls with a triggering disposition together
 * with the earlier history of the same phone number in the same queue.
 */
enum QueueMode: string {

  case All = 'VSE';
  case Logic = 'LOGIKA';

  /**
   * The mode of a queue, or NULL when the queue is not handed over at all.
   */
  public static function fromLabel(?string $label): ?self {
    return $label === NULL ? NULL : self::tryFrom($label);
  }

}
