<?php

declare(strict_types=1);

namespace Drupal\Tests\csobrec\Unit;

use Drupal\csobrec\Call;
use Drupal\csobrec\Item;
use Drupal\csobrec\Service\CallMapper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Daktela's activity shape turned into a Call, and an Item into an XML line.
 *
 * The field shapes are read off the live instance on 26 October 2026: campaign
 * record fields arrive as arrays, the phone number hides in item.clid, and the
 * operator may only be on the call record.
 */
#[Group('csobrec')]
final class CallMapperTest extends UnitTestCase {

  /**
   * A minimal activity; $extra overrides or adds keys.
   */
  private function activity(array $extra = []): array {
    return $extra + [
      'name' => 'aktivita-1',
      'queue' => ['name' => '13001'],
      'time' => '2026-10-26 08:09:03',
      'statuses' => [['title' => '[CSOB] Nový prodej']],
      'item' => ['clid' => '+420 777 123 456'],
      'user' => ['title' => 'Jan Novák'],
      'record' => ['customFields' => []],
    ];
  }

  public function testAnActivityBecomesACall(): void {
    $call = (new CallMapper())->fromActivity($this->activity());
    $this->assertInstanceOf(Call::class, $call);
    $this->assertSame('aktivita-1', $call->activity);
    $this->assertSame('13001', $call->queue);
    $this->assertSame('2026-10-26 08:09:03', $call->time->format('Y-m-d H:i:s'));
    $this->assertSame('[CSOB] Nový prodej', $call->disposition);
    $this->assertSame('420777123456', $call->phone);
    $this->assertSame('Jan Novák', $call->operator);
  }

  public function testACallWithoutADispositionHasNone(): void {
    $call = (new CallMapper())->fromActivity($this->activity(['statuses' => []]));
    $this->assertNull($call->disposition);
  }

  public function testThePhoneFallsBackToTheTitleAndKeepsOnlyDigits(): void {
    $mapper = new CallMapper();
    $call = $mapper->fromActivity($this->activity(['item' => [], 'title' => '420 777 123 456']));
    $this->assertSame('420777123456', $call->phone);
  }

  public function testTheOperatorFallsBackToTheAgentOnTheCallRecord(): void {
    $call = (new CallMapper())->fromActivity($this->activity([
      'user' => NULL,
      'item' => ['clid' => '420777123456', 'id_agent' => ['title' => 'Eva Dvořáková']],
    ]));
    $this->assertSame('Eva Dvořáková', $call->operator);
  }

  public function testCampaignFieldsArriveAsArraysAndTheFirstNonEmptyValueWins(): void {
    $call = (new CallMapper())->fromActivity($this->activity([
      'record' => ['customFields' => ['csob_cislo_navrhu_ps' => ['', '6100000001']]],
    ]));
    $this->assertSame('6100000001', $call->policyNumber);
  }

  public function testAShortNumberIsNotAPolicyNumber(): void {
    // Operators type notes into that field too; under seven digits it is not
    // a contract number and must not reach ČSOB as one.
    $call = (new CallMapper())->fromActivity($this->activity([
      'record' => ['customFields' => ['csob_cislo_navrhu_ps' => ['123']]],
    ]));
    $this->assertNull($call->policyNumber);
  }

  public function testXSellNeedsBothTheBenefitAndAUsableNumber(): void {
    $mapper = new CallMapper();
    $both = $mapper->fromActivity($this->activity(['record' => ['customFields' => [
      '_csobp__vyuzit_x_sell_benefit' => ['ANO'],
      '_csobp__x_sell___cislo_navrhu_ps' => ['6100000999'],
    ]]]));
    $this->assertTrue($both->xSell);
    $this->assertSame('6100000999', $both->xSellNumber);

    $noNumber = $mapper->fromActivity($this->activity(['record' => ['customFields' => [
      '_csobp__vyuzit_x_sell_benefit' => ['ANO'],
      '_csobp__x_sell___cislo_navrhu_ps' => ['12'],
    ]]]));
    $this->assertTrue($noNumber->xSell);
    $this->assertNull($noNumber->xSellNumber);
  }

  public function testAnActivityWithoutACampaignRecordDoesNotBlowUp(): void {
    $call = (new CallMapper())->fromActivity($this->activity(['record' => NULL]));
    $this->assertNull($call->policyNumber);
    $this->assertFalse($call->xSell);
  }

  public function testAMissingQueueOrTimeIsAnError(): void {
    $mapper = new CallMapper();
    $this->expectException(\InvalidArgumentException::class);
    $mapper->fromActivity($this->activity(['queue' => NULL]));
  }

  public function testAnUnparsableTimeIsAnError(): void {
    $mapper = new CallMapper();
    $this->expectException(\InvalidArgumentException::class);
    $mapper->fromActivity($this->activity(['time' => 'vcera odpoledne']));
  }

  public function testAnItemBecomesAnXmlLineInTheOrderTheXmlExpects(): void {
    $call = (new CallMapper())->fromActivity($this->activity());
    $line = (new CallMapper())->lineFromItem(new Item($call, '6100000001', 1), 'DA_1.mp3');
    $this->assertSame(
      ['soubor', 'prostredi', 'operator', 'id_kampane', 'telefon', 'id_callcentra', 'c_poj_sml'],
      array_keys($line),
    );
    $this->assertSame('DA_1.mp3', $line['soubor']);
    $this->assertSame('D', $line['prostredi']);
    $this->assertSame('13001', $line['id_kampane']);
    $this->assertSame('159', $line['id_callcentra']);
    $this->assertSame('6100000001', $line['c_poj_sml']);
  }

  public function testALineWithoutAPolicyNumberLeavesTheFieldOut(): void {
    $call = (new CallMapper())->fromActivity($this->activity());
    $line = (new CallMapper())->lineFromItem(new Item($call, NULL, 1), 'DA_1.mp3');
    $this->assertArrayNotHasKey('c_poj_sml', $line);
  }

}
