<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Service\LabelSheet;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Printable label sheet (browser print, 50×25 mm per label).
 */
final class LabelController extends ControllerBase {

  public function __construct(
    private readonly LabelSheet $labelSheet,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.label_sheet'), $container->get('tempstore.private'), $container->get('request_stack'));
  }

  public function single(Asset $hwdesk_asset): array {
    return $this->build([$hwdesk_asset]);
  }

  public function sheet(Request $request): array {
    $ids = self::idsFromRequest($request, $this->tempStoreFactory);
    $assets = $ids ? $this->entityTypeManager()->getStorage('hwdesk_asset')->loadMultiple($ids) : [];
    return $this->build(array_values($assets));
  }

  /**
   * @param list<\Drupal\hwdesk\Entity\Asset> $assets
   */
  private function build(array $assets): array {
    $base = rtrim((string) $this->config('hwdesk.settings')->get('base_url'), '/');
    $request = $this->requestStack->getCurrentRequest();
    if ($base === '' && $request !== NULL) {
      $base = rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/');
    }
    $labels = $this->labelSheet->labels($assets, $base);
    return [
      '#theme' => 'hwdesk_label_sheet',
      '#labels' => $labels,
      '#attached' => ['library' => ['hwdesk/labels']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Asset ids from ?ids=1,2,3 or from the bulk-action selection in tempstore.
   *
   * @return list<int>
   */
  public static function idsFromRequest(Request $request, PrivateTempStoreFactory $factory): array {
    $raw = (string) $request->query->get('ids', '');
    if ($raw !== '') {
      return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
    $selection = $factory->get('hwdesk')->get('label_selection');
    return is_array($selection) ? array_values(array_map('intval', $selection)) : [];
  }

}
