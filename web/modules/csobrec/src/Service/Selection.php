<?php

declare(strict_types=1);

namespace Drupal\csobrec\Service;

use Drupal\csobrec\Call;
use Drupal\csobrec\Item;
use Drupal\csobrec\QueueMode;

/**
 * Chooses which calls of a day are handed over to ČSOB.
 *
 * Pure function over the day's calls and the queue table: a queue in mode
 * All hands over every call; a queue in mode Logic hands over a call with a
 * triggering disposition together with the earlier history of the same phone
 * number in the same queue. An X-sell call is handed over twice.
 */
final class Selection {

  /**
   * Dispositions that trigger the Logic hand-over.
   *
   * @var string[]
   */
  private const TRIGGER_DISPOSITIONS = [
    '[CSOB] Nový prodej',
    '[CSOB] Nový prodej TZ',
  ];

  /**
   * Selects the calls to hand over.
   *
   * @param \Drupal\csobrec\Call[] $calls
   *   The calls of the day, in any order.
   * @param array<string, \Drupal\csobrec\QueueMode> $modes
   *   The queue table: queue number => mode. Queues not listed are ignored.
   *
   * @return \Drupal\csobrec\Item[]
   *   The lines of the batch, sorted by time, then activity, then copy.
   */
  public function select(array $calls, array $modes): array {
    $selected = $this->selectCalls($calls, $modes);
    $items = [];
    foreach ($selected as $call) {
      $items[] = new Item($call, $call->policyNumber, 1);
      if ($call->xSell && $call->xSellNumber !== NULL && $call->xSellNumber !== '') {
        $items[] = new Item($call, $call->xSellNumber, 2);
      }
    }
    usort($items, static function (Item $a, Item $b): int {
      return $a->call->time <=> $b->call->time
        ?: $a->call->activity <=> $b->call->activity
        ?: $a->copy <=> $b->copy;
    });
    return $items;
  }

  /**
   * The calls that go to the hand-over, keyed by activity.
   *
   * The same activity reported twice (two queries of the same day) is one
   * call, so the key keeps the batch free of duplicates.
   *
   * @param \Drupal\csobrec\Call[] $calls
   * @param array<string, \Drupal\csobrec\QueueMode> $modes
   *
   * @return array<string, \Drupal\csobrec\Call>
   */
  private function selectCalls(array $calls, array $modes): array {
    $selected = [];
    foreach ($calls as $call) {
      $mode = $modes[$call->queue] ?? NULL;
      if ($mode === NULL) {
        continue;
      }
      if ($mode === QueueMode::All) {
        $selected[$call->activity] = $call;
        continue;
      }
      if ($mode === QueueMode::Logic && in_array($call->disposition, self::TRIGGER_DISPOSITIONS, TRUE)) {
        // The triggering call plus the earlier history of the same phone
        // number in the same queue; later calls are not part of it.
        foreach ($calls as $other) {
          if ($other->queue === $call->queue
            && $other->phone === $call->phone
            && $other->time <= $call->time) {
            $selected[$other->activity] = $other;
          }
        }
      }
    }
    return $selected;
  }

}
