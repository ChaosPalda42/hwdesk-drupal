<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Render\RendererInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\hwdesk\Entity\Handover;
use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\file\FileInterface;

/**
 * Service to generate and store protocol PDFs.
 */
final class ProtocolPdf {
  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Render the PDF bytes for a handover.
   */
  public function render(Handover $handover): string {
    $asset = $handover->getAsset();
    $employee = $handover->getUser();
    $admin = $handover->getRequestedBy();
    if (!$asset || !$employee) {
      throw new \InvalidArgumentException('Asset or employee missing.');
    }
    $config = $this->configFactory->get('hwdesk.settings');
    $company = (string) $config->get('company_name');
    $confirmedAt = $this->dateFormatter->format(
      $handover->getDecided() ?? time(),
      'custom',
      'j. n. Y H:i'
    );

    $build = [
      '#theme' => 'hwdesk_protocol',
      '#handover' => $handover,
      '#asset' => $asset,
      '#employee' => $employee,
      '#admin' => $admin,
      '#company' => $company,
      '#confirmed_at' => $confirmedAt,
    ];

    $html = (string) $this->renderer->renderInIsolation($build);

    $options = new Options([
      'isRemoteEnabled' => FALSE,
      'defaultFont' => 'DejaVu Sans',
    ]);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4');
    $dompdf->render();
    return (string) $dompdf->output();
  }

  /**
   * Store the generated PDF as a file entity.
   */
  public function store(Handover $handover): FileInterface {
    $protocolNumber = $handover->getProtocolNumber();
    if ($protocolNumber === '') {
      throw new \InvalidArgumentException('Protocol number empty.');
    }
    $destination = "private://hwdesk/protocols/{$protocolNumber}.pdf";
    $dir = dirname($destination);
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $data = $this->render($handover);
    $file = $this->fileRepository->writeData(
      $data,
      $destination,
      \Drupal\Core\File\FileExists::Replace
    );
    $file->setPermanent();
    $file->setMimeType('application/pdf');
    $file->save();
    return $file;
  }
}
