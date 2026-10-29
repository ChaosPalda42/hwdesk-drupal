<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\AssetCondition;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Form\BulkAssetsForm;
use Drupal\hwdesk\Service\AssetQuery;
use Drupal\hwdesk\Service\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seznam zařízení: filtry, řazení, hromadné akce.
 */
final class AssetsController extends ControllerBase {

  public function __construct(
    private readonly AssetQuery $assetQuery,
    private readonly Presenter $presenter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.asset_query'), $container->get('hwdesk.presenter'));
  }

  /**
   * @return array<string, mixed>
   */
  public function page(Request $request): array {
    $filters = $this->assetQuery->filters($request->query->all());
    $sort = (string) $request->query->get('sort', 'tag');
    $result = $this->assetQuery->execute($filters, $sort, (int) $request->query->get('page', 1));

    $rows = [];
    $storage = $this->entityTypeManager()->getStorage('hwdesk_asset');
    foreach ($storage->loadMultiple($result['ids']) as $asset) {
      if ($asset instanceof Asset) {
        $rows[] = $this->presenter->asset($asset);
      }
    }

    return [
      '#theme' => 'hwdesk_assets',
      '#rows' => $rows,
      '#filters' => $filters,
      '#options' => [
        'types' => $this->config('hwdesk.settings')->get('asset_types') ?? [],
        'statuses' => AssetStatus::options(),
        'conditions' => AssetCondition::options(),
        'tags' => $this->labels('hwdesk_tag'),
        'locations' => $this->labels('hwdesk_location'),
        'holders' => $this->holders(),
      ],
      '#sort' => $sort,
      '#base' => $this->base($filters),
      '#page' => $result['page'],
      '#pages' => $result['pages'],
      '#total' => $result['total'],
      '#bulk_form' => $this->formBuilder()->getForm(BulkAssetsForm::class),
      '#links' => [
        'add' => Url::fromRoute('entity.hwdesk_asset.add_form')->toString(),
        'intake' => Url::fromRoute('hwdesk.intake')->toString(),
        'import' => Url::fromRoute('hwdesk.import')->toString(),
        'export' => Url::fromRoute('hwdesk.export', [], ['query' => $filters])->toString(),
        'reset' => Url::fromRoute('hwdesk.assets')->toString(),
      ],
      '#cache' => [
        'tags' => ['hwdesk_asset_list', 'hwdesk_tag_list', 'hwdesk_location_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
    ];
  }

  /**
   * Adresa seznamu se zachovanými filtry; šablona na ni nabaluje sort a page.
   *
   * @param array<string, string> $filters
   */
  private function base(array $filters): string {
    return Url::fromRoute('hwdesk.assets', [], ['query' => $filters ?: ['q' => '']])->toString();
  }

  /**
   * @return array<int, string>
   */
  private function labels(string $entity_type): array {
    $labels = [];
    $storage = $this->entityTypeManager()->getStorage($entity_type);
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('name')->execute();
    foreach ($storage->loadMultiple($ids) as $entity) {
      $labels[(int) $entity->id()] = (string) $entity->label();
    }
    return $labels;
  }

  /**
   * Lidé, kteří mají něco u sebe — jen ti mají smysl jako filtr držitele.
   *
   * @return array<int, string>
   */
  private function holders(): array {
    $uids = $this->entityTypeManager()->getStorage('hwdesk_asset')->getQuery()
      ->accessCheck(FALSE)
      ->exists('holder')
      ->execute();
    $holders = [];
    foreach ($this->entityTypeManager()->getStorage('hwdesk_asset')->loadMultiple($uids) as $asset) {
      $user = $asset instanceof Asset ? $asset->get('holder')->entity : NULL;
      if ($user !== NULL) {
        $holders[(int) $user->id()] = $user->getDisplayName();
      }
    }
    asort($holders);
    return $holders;
  }

}
