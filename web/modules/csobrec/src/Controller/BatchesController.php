<?php

declare(strict_types=1);

namespace Drupal\csobrec\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\csobrec\Service\SentLedger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What went out, when, and what did not.
 *
 * Everything on this page is an identifier, a count or a state: no phone
 * numbers, no customer names, no proposal numbers. That is not a layout
 * decision — the overview is the page a team leader opens, and the recordings
 * themselves are ČSOB's business.
 */
final class BatchesController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly SentLedger $ledger,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('csobrec.sent_ledger'),
      $container->get('date.formatter'),
    );
  }

  public function page(): array {
    $counts = $this->ledger->counts();

    $rows = [];
    $query = $this->database->select('csobrec_davka', 'd')
      ->fields('d')
      ->orderBy('d.zalozeno', 'DESC')
      ->orderBy('d.id', 'DESC')
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    foreach ($query->execute() as $batch) {
      $rows[] = [
        'den' => $batch->den,
        'jmeno' => $batch->jmeno,
        'stav' => $this->stateLabel((string) $batch->stav),
        'hovoru' => (int) $batch->hovoru,
        'nahrano' => (int) $batch->nahrano,
        'bajtu' => $this->formatBytes((int) $batch->bajtu),
        'zalozeno' => $this->dateFormatter->format((int) $batch->zalozeno, 'short'),
        'chyba' => (string) ($batch->chyba ?? ''),
      ];
    }

    return [
      'souhrn' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Celkem'),
        '#items' => [
          $this->t('Hovorů, které ČSOB má: @pocet', ['@pocet' => $counts['hovoru']]),
          $this->t('Dávek: @pocet', ['@pocet' => $counts['davek']]),
        ],
      ],
      'davky' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Den'),
          $this->t('Dávka'),
          $this->t('Stav'),
          $this->t('Hovorů'),
          $this->t('Nahraných souborů'),
          $this->t('Velikost'),
          $this->t('Spuštěno'),
          $this->t('Chyba'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Zatím neproběhla žádná dávka.'),
      ],
      'strankovani' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

  private function stateLabel(string $state): string {
    return match ($state) {
      'bezi' => (string) $this->t('běží'),
      'odeslano' => (string) $this->t('odesláno'),
      'chyba' => (string) $this->t('chyba'),
      'preskoceno' => (string) $this->t('přeskočeno'),
      default => $state,
    };
  }

  private function formatBytes(int $bytes): string {
    if ($bytes <= 0) {
      return '—';
    }
    return (string) $this->t('@mb MB', ['@mb' => number_format($bytes / 1048576, 1, ',', ' ')]);
  }

}
