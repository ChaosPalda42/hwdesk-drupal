<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\Service\TagGenerator;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Inventory tags: prefix per type, zero-padded sequence per prefix, kept in state.
 */
#[Group('hwdesk')]
final class TagGeneratorTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['hwdesk']);
  }

  private function generator(): TagGenerator {
    return $this->container->get('hwdesk.tag_generator');
  }

  public function testSequencesRunPerPrefixAndArePadded(): void {
    $gen = $this->generator();
    $this->assertSame('NB-0001', $gen->next('notebook'));
    $this->assertSame('NB-0002', $gen->next('notebook'));
    $this->assertSame('TEL-0001', $gen->next('phone'));
    $this->assertSame('NB-0003', $gen->next('notebook'));
  }

  public function testUnknownTypeFallsBackToOtherPrefix(): void {
    $gen = $this->generator();
    $this->assertSame('HW-0001', $gen->next('toaster'));
    $this->assertSame('HW-0002', $gen->next('other'));
  }

  public function testPeekDoesNotConsume(): void {
    $gen = $this->generator();
    $this->assertSame('MON-0001', $gen->peek('monitor'));
    $this->assertSame('MON-0001', $gen->peek('monitor'));
    $this->assertSame('MON-0001', $gen->next('monitor'));
    $this->assertSame('MON-0002', $gen->peek('monitor'));
  }

  public function testSequenceSurvivesANewServiceInstance(): void {
    $this->generator()->next('notebook');
    $fresh = new TagGenerator($this->container->get('config.factory'), $this->container->get('state'));
    $this->assertSame('NB-0002', $fresh->next('notebook'));
  }

  public function testReserveBumpsTheSequencePastAManualTag(): void {
    $gen = $this->generator();
    $gen->reserve('NB-0042');
    $this->assertSame('NB-0043', $gen->next('notebook'));
    $gen->reserve('NB-0007');
    $this->assertSame('NB-0044', $gen->next('notebook'));
    $gen->reserve('PER-3');
    $this->assertSame('PER-0004', $gen->next('peripheral'));
  }

  public function testReserveRejectsMalformedTags(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generator()->reserve('notebook 12');
  }

  public function testPaddingGrowsBeyondTheConfiguredWidth(): void {
    $gen = $this->generator();
    $gen->reserve('NB-9999');
    $this->assertSame('NB-10000', $gen->next('notebook'));
  }

  public function testPadComesFromConfig(): void {
    $this->config('hwdesk.settings')->set('tag_pad', 2)->save();
    $this->assertSame('NB-01', $this->generator()->next('notebook'));
  }

  public function testIsValid(): void {
    $gen = $this->generator();
    $this->assertTrue($gen->isValid('NB-0001'));
    $this->assertTrue($gen->isValid('HW-12'));
    $this->assertFalse($gen->isValid('nb-0001'));
    $this->assertFalse($gen->isValid('NB0001'));
    $this->assertFalse($gen->isValid('NB-'));
    $this->assertFalse($gen->isValid(''));
  }

}
