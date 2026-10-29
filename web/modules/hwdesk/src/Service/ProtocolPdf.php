<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\hwdesk\Entity\Handover;
use Dompdf\Dompdf;
use Dompdf\Options;

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
   * Render the protocol HTML for a handover.
   *
   * The terms are taken from the handover itself, not from the current
   * configuration: the paper must show the text the employee actually agreed
   * to, even if the settings changed since.
   */
  public function renderHtml(Handover $handover): string {
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

    $termsValue = $handover->get('terms')->value;
    $terms = $termsValue === NULL || $termsValue === ''
      ? NULL
      : ['#markup' => Xss::filterAdmin((string) $termsValue)];

    $consentedValue = $handover->get('consented')->value;
    $consentedAt = $consentedValue === NULL || $consentedValue === ''
      ? ''
      : $this->dateFormatter->format((int) $consentedValue, 'custom', 'j. n. Y H:i');

    $build = [
      '#theme' => 'hwdesk_protocol',
      '#handover' => $handover,
      '#asset' => $asset,
      '#employee' => $employee,
      '#admin' => $admin,
      '#company' => $company,
      '#confirmed_at' => $confirmedAt,
      '#terms' => $terms,
      '#consented_at' => $consentedAt,
    ];

    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Render the PDF bytes for a handover.
   */
  public function render(Handover $handover): string {
    $html = $this->renderHtml($handover);

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
      FileExists::Replace
    );
    $file->setPermanent();
    $file->setMimeType('application/pdf');
    $file->save();
    return $file;
  }

}
