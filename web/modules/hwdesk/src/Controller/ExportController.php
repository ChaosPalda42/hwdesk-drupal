<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hwdesk\Service\AssetCsv;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV of all assets, or of ?ids=… (the bulk action passes a selection).
 */
final class ExportController extends ControllerBase {

  public function __construct(private readonly AssetCsv $csv) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.asset_csv'));
  }

  public function csv(Request $request): Response {
    $storage = $this->entityTypeManager()->getStorage('hwdesk_asset');
    $raw = (string) $request->query->get('ids', '');
    $ids = $raw !== ''
      ? array_values(array_filter(array_map('intval', explode(',', $raw))))
      : $storage->getQuery()->accessCheck(FALSE)->sort('tag')->execute();
    $assets = $storage->loadMultiple($ids);
    $response = new Response($this->csv->export($assets));
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="hwdesk-' . date('Y-m-d') . '.csv"');
    return $response;
  }

}
