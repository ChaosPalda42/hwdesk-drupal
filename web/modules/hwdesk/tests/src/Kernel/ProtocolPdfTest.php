<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\file\FileInterface;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\ProtocolPdf;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The protocol PDF: rendered from the hwdesk_protocol template, stored as a
 * permanent file under private://hwdesk/protocols/<number>.pdf.
 */
#[Group('hwdesk')]
final class ProtocolPdfTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->config('hwdesk.settings')->set('company_name', 'Firma s.r.o.')->save();
  }

  private function pdf(): ProtocolPdf {
    return $this->container->get('hwdesk.protocol_pdf');
  }

  public function testRenderProducesAPdfDocument(): void {
    $employee = $this->makeUser('jana.novakova@firma.cz', 'Jana Nováková');
    $admin = $this->makeUser('spravce@firma.cz', 'Správce');
    $asset = $this->makeAsset('NB-0001', ['serial_number' => 'PF1ABC']);
    $handover = $this->makeHandover($asset, $employee, ['status' => HandoverStatus::Confirmed->value, 'decided' => time(), 'protocol_number' => 'HP-2026-0001', 'requested_by' => $admin->id()]);
    $bytes = $this->pdf()->render($handover);
    $this->assertStringStartsWith('%PDF-', $bytes);
    $this->assertGreaterThan(1000, strlen($bytes));
  }

  public function testStoreWritesThePermanentFileAndReplacesItOnRepeat(): void {
    $employee = $this->makeUser('jana.novakova@firma.cz', 'Jana Nováková');
    $asset = $this->makeAsset('NB-0002');
    $handover = $this->makeHandover($asset, $employee, ['status' => HandoverStatus::Confirmed->value, 'decided' => time(), 'protocol_number' => 'HP-2026-0002']);
    $file = $this->pdf()->store($handover);
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertSame('private://hwdesk/protocols/HP-2026-0002.pdf', $file->getFileUri());
    $this->assertTrue($file->isPermanent());
    $this->assertSame('application/pdf', $file->getMimeType());
    $this->assertFileExists($this->container->get('file_system')->realpath($file->getFileUri()));
    $this->assertStringStartsWith('%PDF-', (string) file_get_contents($file->getFileUri()));

    $again = $this->pdf()->store($handover);
    $this->assertSame('private://hwdesk/protocols/HP-2026-0002.pdf', $again->getFileUri());
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('file')->loadByProperties(['uri' => 'private://hwdesk/protocols/HP-2026-0002.pdf']));
  }

  public function testStoreRefusesAHandoverWithoutAProtocolNumber(): void {
    $employee = $this->makeUser('jana.novakova@firma.cz');
    $handover = $this->makeHandover($this->makeAsset('NB-0003'), $employee, ['status' => HandoverStatus::Confirmed->value]);
    $this->expectException(\InvalidArgumentException::class);
    $this->pdf()->store($handover);
  }

  public function testTheTermsTheEmployeeAgreedToArePrintedInTheProtocol(): void {
    $employee = $this->makeUser('jana.novakova@firma.cz', 'Jana Nováková');
    $handover = $this->makeHandover($this->makeAsset('NB-0004'), $employee, [
      'status' => HandoverStatus::Confirmed->value,
      'decided' => 1761000000,
      'consented' => 1760999940,
      'protocol_number' => 'HP-2026-0004',
      'terms' => '<h3>Podmínky převzetí</h3><p>Zařízení zůstává majetkem firmy.</p><script>alert(1)</script>',
    ]);

    $html = $this->pdf()->renderHtml($handover);

    $this->assertStringContainsString('<div class="protokol-podminky">', $html, 'Podmínky mají v protokolu svůj rámeček.');
    $this->assertStringContainsString('<h3>Podmínky převzetí</h3>', $html, 'Text, se kterým zaměstnanec souhlasil, patří do protokolu.');
    $this->assertStringContainsString('Zařízení zůstává majetkem firmy.', $html);
    $this->assertStringNotContainsString('<script', $html, 'Do PDF se z textu nesmí dostat skript.');
    $souhlas = $this->container->get('date.formatter')->format(1760999940, 'custom', 'j. n. Y H:i');
    $this->assertStringContainsString($souhlas, $html, 'V protokolu je i čas, kdy souhlas padl.');
    $this->assertStringStartsWith('%PDF-', $this->pdf()->render($handover));
  }

  public function testWhatIsPrintedIsTheTextFromTheHandoverNotTheCurrentSetting(): void {
    $employee = $this->makeUser('jana.novakova@firma.cz', 'Jana Nováková');
    $handover = $this->makeHandover($this->makeAsset('NB-0005'), $employee, [
      'status' => HandoverStatus::Confirmed->value,
      'decided' => 1761000000,
      'consented' => 1761000000,
      'protocol_number' => 'HP-2026-0005',
      'terms' => '<p>Verze, se kterou zaměstnanec souhlasil.</p>',
    ]);
    $this->config('hwdesk.settings')->set('handover_terms', '<p>Nové podmínky z dneška.</p>')->save();

    $html = $this->pdf()->renderHtml($handover);

    $this->assertStringContainsString('Verze, se kterou zaměstnanec souhlasil.', $html);
    $this->assertStringNotContainsString('Nové podmínky z dneška.', $html);
  }

  public function testWithoutAnyTermsTheProtocolHasNoEmptySection(): void {
    $employee = $this->makeUser('jana.novakova@firma.cz', 'Jana Nováková');
    $handover = $this->makeHandover($this->makeAsset('NB-0006'), $employee, [
      'status' => HandoverStatus::Confirmed->value,
      'decided' => 1761000000,
      'protocol_number' => 'HP-2026-0006',
    ]);

    $html = $this->pdf()->renderHtml($handover);

    $this->assertStringNotContainsString('<div class="protokol-podminky">', $html, 'Bez textu podmínek se prázdná sekce nekreslí.');
    $this->assertStringStartsWith('%PDF-', $this->pdf()->render($handover));
  }

  public function testTheProtocolDoesNotClaimTheEmployeeLoggedIn(): void {
    $employee = $this->makeUser('jana.novakova@firma.cz', 'Jana Nováková');
    $handover = $this->makeHandover($this->makeAsset('NB-0007'), $employee, [
      'status' => HandoverStatus::Confirmed->value,
      'decided' => 1761000000,
      'consented' => 1761000000,
      'protocol_number' => 'HP-2026-0007',
      'terms' => '<p>Podmínky.</p>',
    ]);

    $html = $this->pdf()->renderHtml($handover);

    // Potvrzení chodí jednorázovým odkazem z e-mailu; nikdo se kvůli němu nepřihlašuje.
    $this->assertStringNotContainsString('přihlášeným účtem', $html);
    $this->assertStringContainsString('odkazem', $html);
  }

}
