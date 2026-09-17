<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\Service\ProtocolNumbers;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Protocol numbers HP-<year>-<sequence>, one counter per year, kept in state.
 */
#[Group('hwdesk')]
final class ProtocolNumbersTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'hwdesk'];

  private function numbers(): ProtocolNumbers {
    return $this->container->get('hwdesk.protocol_numbers');
  }

  public function testSequenceRunsWithinTheCurrentYear(): void {
    $year = date('Y');
    $this->assertSame("HP-$year-0001", $this->numbers()->next());
    $this->assertSame("HP-$year-0002", $this->numbers()->next());
    $this->assertSame("HP-$year-0003", $this->numbers()->peek());
    $this->assertSame("HP-$year-0003", $this->numbers()->peek());
    $this->assertSame("HP-$year-0003", $this->numbers()->next());
  }

  public function testEveryYearHasItsOwnCounter(): void {
    $year = (int) date('Y');
    $this->container->get('state')->set('hwdesk.protocol_seq.' . ($year - 1), 250);
    $this->assertSame("HP-$year-0001", $this->numbers()->next());
    $this->assertSame(1, $this->container->get('state')->get("hwdesk.protocol_seq.$year"));
  }

  public function testSequenceSurvivesANewServiceInstance(): void {
    $year = date('Y');
    $this->numbers()->next();
    $fresh = new ProtocolNumbers($this->container->get('state'), $this->container->get('datetime.time'));
    $this->assertSame("HP-$year-0002", $fresh->next());
  }

  public function testPaddingGrowsBeyondFourDigits(): void {
    $year = date('Y');
    $this->container->get('state')->set("hwdesk.protocol_seq.$year", 9999);
    $this->assertSame("HP-$year-10000", $this->numbers()->next());
  }

}
