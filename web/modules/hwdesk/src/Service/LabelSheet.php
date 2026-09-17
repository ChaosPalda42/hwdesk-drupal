<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\hwdesk\Entity\Asset;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\Common\EccLevel;

/**
 * Service to generate label data with QR codes.
 */
final class LabelSheet {
  /**
   * No dependencies for now, but constructor kept for future injection.
   */
  public function __construct() {}

  /**
   * Generate label information for a list of assets.
   *
   * @param iterable<Asset> $assets
   *   The assets to generate labels for.
   * @param string $baseUrl
   *   Base URL used to build the asset link.
   *
   * @return array<int, array<string, mixed>>
   *   List of label data arrays with keys: tag, name, url, qr.
   */
  public function labels(iterable $assets, string $baseUrl): array {
    $result = [];

    // Prepare QR code generator options once.
    $options = new QROptions([
      'outputBase64' => FALSE,
      'outputInterface' => QRMarkupSVG::class,
      'svgAddXmlHeader' => FALSE,
      'addQuietzone' => TRUE,
      'quietzoneSize' => 1,
      'eccLevel' => EccLevel::M,
    ]);
    $qr = new QRCode($options);

    foreach ($assets as $asset) {
      if (!$asset instanceof Asset) {
        continue;
      }
      $url = rtrim($baseUrl, '/') . '/a/' . $asset->getTag();
      /** @var string $svg */
      $svg = $qr->render($url);

      $result[] = [
        'tag' => $asset->getTag(),
        'name' => $asset->getDisplayName(),
        'url' => $url,
        'qr' => $svg,
      ];
    }

    return $result;
  }
}
