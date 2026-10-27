<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\api_csobrec\Service\Application;
use Drupal\api_csobrec\Service\SentLedger;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The morning run from the command line.
 *
 * This, not Drupal's cron, is how the transfer runs: a day is hundreds of
 * recordings and hundreds of megabytes through ffmpeg, which has no business
 * inside a web request. The system cron calls `drush api-csobrec:vcera` once a
 * morning; everything these commands print are counts and paths, never a
 * phone number or a name, because the output ends up in the cron mail.
 */
final class ApiCsobrecCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    private readonly Application $application,
    private readonly SentLedger $ledger,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('api_csobrec.application'),
      $container->get('api_csobrec.sent_ledger'),
    );
  }

  /**
   * Předá ČSOB nahrávky jednoho dne.
   */
  #[CLI\Command(name: 'api-csobrec:den', aliases: ['api-csobrec-den'])]
  #[CLI\Argument(name: 'day', description: 'Den ve tvaru RRRR-MM-DD.')]
  #[CLI\Option(name: 'bez-nahrani', description: 'Dávku složí na disk, ale na SFTP nic nepošle a do evidence nic nezapíše.')]
  #[CLI\Option(name: 'znovu', description: 'Pustí den, i když už jednou odešel. Dávka dostane vlastní složku a vlastní jméno.')]
  #[CLI\Usage(name: 'drush api-csobrec:den 2026-10-26', description: 'Jeden den.')]
  #[CLI\Usage(name: 'drush api-csobrec:den 2026-10-26 --bez-nahrani', description: 'Zkouška: nic neodejde.')]
  public function day(string $day, array $options = ['bez-nahrani' => FALSE, 'znovu' => FALSE]): int {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if ($parsed === FALSE) {
      $this->logger()->error('Den se píše jako RRRR-MM-DD, dostal jsem {den}.', ['den' => $day]);
      return self::EXIT_FAILURE;
    }
    return $this->report($parsed, !$options['bez-nahrani'], (bool) $options['znovu']);
  }

  /**
   * Předá ČSOB nahrávky včerejšího dne. Tohle volá ranní cron.
   */
  #[CLI\Command(name: 'api-csobrec:vcera', aliases: ['api-csobrec-vcera'])]
  #[CLI\Option(name: 'bez-nahrani', description: 'Dávku složí na disk, ale nic nepošle.')]
  #[CLI\Usage(name: 'drush api-csobrec:vcera', description: 'Ranní předávka včerejšího dne.')]
  public function yesterday(array $options = ['bez-nahrani' => FALSE]): int {
    $day = new \DateTimeImmutable('yesterday 00:00:00');
    return $this->report($day, !$options['bez-nahrani'], FALSE);
  }

  /**
   * Co ČSOB má a jak skončily poslední dávky.
   */
  #[CLI\Command(name: 'api-csobrec:stav', aliases: ['api-csobrec-stav'])]
  public function status(): void {
    $counts = $this->ledger->counts();
    $this->output()->writeln(sprintf('Hovorů, které ČSOB má: %d, dávek: %d', $counts['hovoru'], $counts['davek']));
    $last = $this->ledger->lastBatchOfDay(new \DateTimeImmutable('yesterday 00:00:00'));
    if ($last === NULL) {
      $this->output()->writeln('Za včerejší den dávka neproběhla.');
      return;
    }
    $this->output()->writeln(sprintf(
      'Včerejší den: %s, dávka %s, stav %s, hovorů %d, nahráno %d souborů%s',
      $last['den'],
      $last['jmeno'],
      $last['stav'],
      (int) $last['hovoru'],
      (int) $last['nahrano'],
      $last['chyba'] === NULL ? '' : ' — ' . $last['chyba'],
    ));
  }

  /**
   * Smaže z disku dávky starší, než je nastavená lhůta. Evidenci nechá být.
   */
  #[CLI\Command(name: 'api-csobrec:ukliz', aliases: ['api-csobrec-ukliz'])]
  public function prune(): void {
    $removed = $this->application->prune(new \DateTimeImmutable('now'));
    $this->output()->writeln(sprintf('Smazaných složek: %d', $removed));
  }

  /**
   * Runs one day and prints what came of it — counts only.
   */
  private function report(\DateTimeImmutable $day, bool $upload, bool $force): int {
    try {
      $result = $this->application->run($day, $upload, $force);
    }
    catch (\Throwable $e) {
      // The message of our own exceptions names a path and a status code, not
      // a credential; this is the one place an operator reads the reason.
      $this->logger()->error('Předávka {den} selhala: {chyba}', [
        'den' => $day->format('Y-m-d'),
        'chyba' => $e->getMessage(),
      ]);
      return self::EXIT_FAILURE;
    }

    if ($result['skipped'] ?? FALSE) {
      $this->output()->writeln(sprintf(
        'Den %s už odešel jako dávka %s. Znovu jen s --znovu.',
        $day->format('Y-m-d'),
        $result['batch'],
      ));
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln(sprintf('Dávka %s za den %s', $result['batch'], $day->format('Y-m-d')));
    foreach ($result as $key => $value) {
      if (is_int($value)) {
        $this->output()->writeln(sprintf('  %-16s %d', $key, $value));
      }
    }
    if (!$upload) {
      $this->output()->writeln('Nahrávání vypnuté: na SFTP nic neodešlo a do evidence se nic nezapsalo.');
    }
    return self::EXIT_SUCCESS;
  }

}
