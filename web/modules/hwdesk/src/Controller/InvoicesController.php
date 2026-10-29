<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Invoice;
use Drupal\hwdesk\Service\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Faktury a jejich detail se zařízeními, která se z nich pořídila.
 */
final class InvoicesController extends ControllerBase {

  public function __construct(
    private readonly Presenter $presenter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.presenter'));
  }

  /**
   * @return array<string, mixed>
   */
  public function page(Request $request): array {
    $query = trim((string) $request->query->get('q', ''));
    $storage = $this->entityTypeManager()->getStorage('hwdesk_invoice');
    $entity_query = $storage->getQuery()->accessCheck(TRUE)->sort('issued_on', 'DESC')->sort('id', 'DESC');
    if ($query !== '') {
      $entity_query->condition($entity_query->orConditionGroup()
        ->condition('number', $query, 'CONTAINS')
        ->condition('supplier', $query, 'CONTAINS'));
    }

    $rows = [];
    $sum = 0.0;
    foreach ($storage->loadMultiple($entity_query->execute()) as $invoice) {
      if (!$invoice instanceof Invoice) {
        continue;
      }
      $row = $this->presenter->invoice($invoice);
      $row['assets'] = (int) $this->entityTypeManager()->getStorage('hwdesk_asset')->getQuery()
        ->accessCheck(FALSE)
        ->condition('invoice', $invoice->id())
        ->count()
        ->execute();
      $sum += (float) ($row['total'] ?? 0);
      $rows[] = $row;
    }

    return [
      '#theme' => 'hwdesk_invoices',
      '#rows' => $rows,
      '#query' => $query,
      '#total' => count($rows),
      '#sum' => $sum,
      '#links' => [
        'add' => Url::fromRoute('entity.hwdesk_invoice.add_form')->toString(),
        'reset' => Url::fromRoute('entity.hwdesk_invoice.collection')->toString(),
      ],
      '#cache' => [
        'tags' => ['hwdesk_invoice_list', 'hwdesk_asset_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function detail(Invoice $hwdesk_invoice): array {
    $assets = [];
    $storage = $this->entityTypeManager()->getStorage('hwdesk_asset');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('invoice', $hwdesk_invoice->id())->sort('tag')->execute();
    foreach ($storage->loadMultiple($ids) as $asset) {
      if ($asset instanceof Asset) {
        $assets[] = $this->presenter->asset($asset);
      }
    }

    return [
      '#theme' => 'hwdesk_invoice',
      '#invoice' => $this->presenter->invoice($hwdesk_invoice),
      '#assets' => $assets,
      '#files' => $this->presenter->files('attachments', $hwdesk_invoice),
      '#links' => [
        'edit' => $hwdesk_invoice->toUrl('edit-form')->toString(),
        'collection' => Url::fromRoute('entity.hwdesk_invoice.collection')->toString(),
      ],
      '#cache' => [
        'tags' => array_merge($hwdesk_invoice->getCacheTags(), ['hwdesk_asset_list']),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
