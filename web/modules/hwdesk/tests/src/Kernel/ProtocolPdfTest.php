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

}
