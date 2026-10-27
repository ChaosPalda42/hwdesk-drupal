<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Unit;

use Drupal\api_csobrec\Call;
use Drupal\api_csobrec\QueueMode;
use Drupal\api_csobrec\Service\Selection;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which calls of a day go to ČSOB.
 *
 * Rules from the 26 October 2026 meeting and the client's queue table:
 * a queue in mode All hands over every call; a queue in mode Logic hands over
 * a call with a triggering disposition together with the earlier calls of the
 * same phone number *in the same queue*; an X-sell call goes twice.
 */
#[Group('api_csobrec')]
final class SelectionTest extends UnitTestCase {

  private function call(
    string $activity,
    string $queue = '13001',
    string $time = '2026-10-26 08:00:00',
    ?string $disposition = NULL,
    string $phone = '420777123456',
    ?string $policyNumber = NULL,
    bool $xSell = FALSE,
    ?string $xSellNumber = NULL,
  ): Call {
    return new Call(
      activity: $activity,
      queue: $queue,
      time: new \DateTimeImmutable($time),
      disposition: $disposition,
      phone: $phone,
      operator: 'Jan Novák',
      policyNumber: $policyNumber,
      xSell: $xSell,
      xSellNumber: $xSellNumber,
    );
  }

  /**
   * @return string[]
   */
  private function activities(array $items): array {
    return array_map(static fn ($item) => $item->call->activity, $items);
  }

  public function testModeAllTakesEveryCallWhateverTheDisposition(): void {
    $items = (new Selection())->select(
      [$this->call('a1'), $this->call('a2', disposition: 'Nedovoláno')],
      ['13001' => QueueMode::All],
    );
    $this->assertSame(['a1', 'a2'], $this->activities($items));
  }

  public function testModeLogicTakesNothingWithoutATrigger(): void {
    $items = (new Selection())->select(
      [$this->call('a1', disposition: 'Nedovoláno'), $this->call('a2')],
      ['13001' => QueueMode::Logic],
    );
    $this->assertSame([], $items);
  }

  public function testBothTriggeringDispositionsCount(): void {
    foreach (['[CSOB] Nový prodej', '[CSOB] Nový prodej TZ'] as $trigger) {
      $items = (new Selection())->select(
        [$this->call('a1', disposition: $trigger)],
        ['13001' => QueueMode::Logic],
      );
      $this->assertSame(['a1'], $this->activities($items), "trigger: $trigger");
    }
  }

  public function testLogicAlsoTakesTheEarlierHistoryOfTheSameNumber(): void {
    $items = (new Selection())->select([
      $this->call('older', time: '2026-10-26 07:00:00', disposition: 'Nedovoláno'),
      $this->call('sale', time: '2026-10-26 09:00:00', disposition: '[CSOB] Nový prodej'),
      $this->call('later', time: '2026-10-26 11:00:00', disposition: 'Nedovoláno'),
      $this->call('other-number', time: '2026-10-26 07:30:00', phone: '420777999888'),
    ], ['13001' => QueueMode::Logic]);
    // The call that came after the sale is not part of its history.
    $this->assertSame(['older', 'sale'], $this->activities($items));
  }

  public function testHistoryFromAnotherQueueIsNotTakenAlong(): void {
    $items = (new Selection())->select([
      $this->call('other-queue', queue: '13031', time: '2026-10-26 07:00:00'),
      $this->call('sale', disposition: '[CSOB] Nový prodej', time: '2026-10-26 09:00:00'),
    ], ['13001' => QueueMode::Logic, '13031' => QueueMode::Logic]);
    $this->assertSame(['sale'], $this->activities($items));
  }

  public function testAQueueOutsideTheListIsIgnored(): void {
    $items = (new Selection())->select(
      [$this->call('a1', queue: '99999')],
      ['13001' => QueueMode::All],
    );
    $this->assertSame([], $items);
  }

  public function testXSellAddsASecondCopyWithTheProposalNumber(): void {
    $items = (new Selection())->select(
      [$this->call('a1', policyNumber: '6100000001', xSell: TRUE, xSellNumber: '6100000999')],
      ['13001' => QueueMode::All],
    );
    $this->assertCount(2, $items);
    $this->assertSame([1, 2], array_map(static fn ($i) => $i->copy, $items));
    $this->assertSame('6100000001', $items[0]->policyNumber);
    $this->assertSame('6100000999', $items[1]->policyNumber);
    $this->assertSame('a1', $items[1]->call->activity);
  }

  public function testXSellWithoutAProposalNumberStaysOneCopy(): void {
    $items = (new Selection())->select(
      [$this->call('a1', policyNumber: '6100000001', xSell: TRUE)],
      ['13001' => QueueMode::All],
    );
    $this->assertCount(1, $items);
    $this->assertSame(1, $items[0]->copy);
  }

  public function testTheSameActivityTwiceIsStillOneLine(): void {
    // Daktela can report the same activity in two queries of the same day;
    // sending the recording twice would be a duplicate at ČSOB.
    $items = (new Selection())->select(
      [$this->call('a1'), $this->call('a1')],
      ['13001' => QueueMode::All],
    );
    $this->assertCount(1, $items);
  }

  public function testOrderIsTimeThenActivityThenCopy(): void {
    $items = (new Selection())->select([
      $this->call('b', time: '2026-10-26 09:00:00'),
      $this->call('a', time: '2026-10-26 09:00:00', xSell: TRUE, xSellNumber: '6100000999'),
      $this->call('c', time: '2026-10-26 08:00:00'),
    ], ['13001' => QueueMode::All]);
    $this->assertSame(['c', 'a', 'a', 'b'], $this->activities($items));
    $this->assertSame([1, 1, 2, 1], array_map(static fn ($i) => $i->copy, $items));
  }

  public function testTheInputArrayIsLeftAlone(): void {
    $calls = [$this->call('b', time: '2026-10-26 09:00:00'), $this->call('a')];
    $before = array_map(static fn ($c) => $c->activity, $calls);
    (new Selection())->select($calls, ['13001' => QueueMode::All]);
    $this->assertSame($before, array_map(static fn ($c) => $c->activity, $calls));
  }

}
