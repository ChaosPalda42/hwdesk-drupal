<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Unit;

use Drupal\api_csobrec\Service\IndexXml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The batch index XML, byte for byte as ČSOB accepts it.
 *
 * Their importer is picky: CRLF after every line including the last one, tab
 * indentation, fixed field order. Anything prettier is a rejected batch, so
 * the bytes are asserted, not the structure.
 */
#[Group('api_csobrec')]
final class IndexXmlTest extends UnitTestCase {

  private const LINE = [
    'soubor' => 'DA_20261026080903_a1.mp3',
    'prostredi' => 'D',
    'operator' => 'Jan Novák',
    'id_kampane' => '13001',
    'telefon' => '420777123456',
    'id_callcentra' => '159',
  ];

  public function testEmptyBatchIsStillAValidDocument(): void {
    $xml = (new IndexXml())->build([]);
    $this->assertSame(
      "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n"
      . "<root>\r\n\t<vlastnosti>\r\n\t\t<prioritaCm>50</prioritaCm>\r\n\t</vlastnosti>\r\n"
      . "\t<dokumenty>\r\n\t</dokumenty>\r\n</root>\r\n",
      $xml,
    );
  }

  public function testOneDocumentKeepsFieldOrderAndTabIndentation(): void {
    $xml = (new IndexXml())->build([self::LINE]);
    $expected = "\t\t<dokument>\r\n"
      . "\t\t\t<soubor>DA_20261026080903_a1.mp3</soubor>\r\n"
      . "\t\t\t<prostredi>D</prostredi>\r\n"
      . "\t\t\t<operator>Jan Novák</operator>\r\n"
      . "\t\t\t<id_kampane>13001</id_kampane>\r\n"
      . "\t\t\t<telefon>420777123456</telefon>\r\n"
      . "\t\t\t<id_callcentra>159</id_callcentra>\r\n"
      . "\t\t</dokument>\r\n";
    $this->assertStringContainsString($expected, $xml);
    $this->assertStringEndsWith("\t</dokumenty>\r\n</root>\r\n", $xml);
    // No bare LF anywhere: every line ends with CRLF.
    $this->assertSame(substr_count($xml, "\r\n"), substr_count($xml, "\n"));
  }

  public function testOptionalPolicyNumberComesLastAndOnlyWhenPresent(): void {
    $xml = (new IndexXml())->build([self::LINE + ['c_poj_sml' => '6100000001']]);
    $this->assertStringContainsString(
      "\t\t\t<id_callcentra>159</id_callcentra>\r\n\t\t\t<c_poj_sml>6100000001</c_poj_sml>\r\n\t\t</dokument>",
      $xml,
    );
    $this->assertStringNotContainsString('c_poj_sml', (new IndexXml())->build([self::LINE]));
  }

  public function testPriorityIsConfigurableAndDefaultsToFifty(): void {
    $this->assertStringContainsString('<prioritaCm>50</prioritaCm>', (new IndexXml())->build([]));
    $this->assertStringContainsString('<prioritaCm>10</prioritaCm>', (new IndexXml())->build([], 10));
  }

  public function testMarkupInAValueIsEscapedAndNeverBreaksTheDocument(): void {
    $xml = (new IndexXml())->build([['operator' => 'Fiala & <Syn>'] + self::LINE]);
    $this->assertStringContainsString('<operator>Fiala &amp; &lt;Syn&gt;</operator>', $xml);
    $this->assertStringNotContainsString('<Syn>', $xml);
  }

  public function testAnUnknownOrMissingFieldIsRefusedRatherThanSentOut(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new IndexXml())->build([self::LINE + ['neznamy' => 'x']]);
  }

  public function testAMissingMandatoryFieldIsRefused(): void {
    $line = self::LINE;
    unset($line['telefon']);
    $this->expectException(\InvalidArgumentException::class);
    (new IndexXml())->build([$line]);
  }

}
