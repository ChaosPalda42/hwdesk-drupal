<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\Core\Mail\MailInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Odesílání pošty: adresa asistentky, příloha s protokolem a čitelná chyba.
 */
#[Group('hwdesk')]
final class HwdeskMailTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'dblog', 'key', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->installConfig(['key']);
    $this->installSchema('dblog', ['watchdog']);
    $this->config('hwdesk.settings')
      ->set('mail_from', 'asistentka@firma.cz')
      ->set('mail_from_name', 'Asistentka Firma')
      ->set('mail_reply_to', 'it@firma.cz')
      ->save();
  }

  private function plugin(): MailInterface {
    $plugin = $this->container->get('plugin.manager.mail')->createInstance('hwdesk_mail');
    assert($plugin instanceof MailInterface);
    return $plugin;
  }

  /**
   * @return array<string, mixed>
   */
  private function zprava(array $prepis = []): array {
    return $prepis + [
      'id' => 'hwdesk_handover_request',
      'module' => 'hwdesk',
      'key' => 'handover_request',
      'to' => 'jana@firma.cz',
      'from' => 'web@firma.cz',
      'subject' => 'Převzetí zařízení NB-0001',
      'body' => ['Dobrý den,', 'potvrďte prosím převzetí.'],
      'headers' => [],
      'params' => [],
    ];
  }

  public function testThePluginIsRegisteredUnderItsOwnId(): void {
    $definice = $this->container->get('plugin.manager.mail')->getDefinitions();
    $this->assertArrayHasKey('hwdesk_mail', $definice, 'Plugin se musí najít podle id hwdesk_mail.');
  }

  public function testFormatJoinsTheBodyIntoPlainTextAndKeepsTheSubjectOnOneLine(): void {
    $zformatovana = $this->plugin()->format($this->zprava([
      'subject' => "Převzetí\nzařízení\r\nNB-0001",
      'body' => ['První odstavec.', 'Druhý odstavec.'],
    ]));

    $this->assertIsString($zformatovana['body']);
    $this->assertStringContainsString('První odstavec.', $zformatovana['body']);
    $this->assertStringContainsString('Druhý odstavec.', $zformatovana['body']);
    $this->assertSame(
      'Převzetí zařízení NB-0001',
      preg_replace('/\s+/u', ' ', (string) $zformatovana['subject']),
      'Předmět nesmí obsahovat konec řádku: dalo by se jím podstrčit další hlavička.'
    );
    $this->assertDoesNotMatchRegularExpression('/[\r\n]/', (string) $zformatovana['subject']);
  }

  public function testFormatSurvivesAnEmptyBody(): void {
    $zformatovana = $this->plugin()->format($this->zprava(['body' => []]));
    $this->assertIsString($zformatovana['body']);
    $this->assertSame('', trim($zformatovana['body']));
  }

  public function testAnUnreachableServerIsRefusedWithThePlaceAndTheReasonInTheLog(): void {
    $this->config('hwdesk.settings')
      ->set('smtp_enabled', TRUE)
      // Port 1 na localhostu nikdo neposlouchá: spojení se odmítne hned.
      ->set('smtp_host', '127.0.0.1')
      ->set('smtp_port', 1)
      ->set('smtp_security', 'none')
      ->set('smtp_user', '')
      ->set('smtp_key', '')
      ->save();

    $odeslano = $this->plugin()->mail($this->plugin()->format($this->zprava()));

    $this->assertFalse($odeslano, 'Neodeslaná pošta se nesmí tvářit jako odeslaná.');
    $zaznamy = $this->logy();
    $this->assertNotSame([], $zaznamy, 'O neodeslané poště musí být záznam v kanálu hwdesk.');
    $cely = implode("\n", $zaznamy);
    $this->assertStringContainsString('jana@firma.cz', $cely, 'Záznam má říct, komu se nepodařilo poslat.');
    $this->assertMatchesRegularExpression('/\.php:\d+/', $cely, 'A kde v kódu to spadlo.');
    $this->assertMatchesRegularExpression('/refused|Connection|spojení/iu', $cely, 'A proč.');
  }

  /**
   * Záznamy kanálu hwdesk s doplněnými zástupnými hodnotami.
   *
   * @return list<string>
   */
  private function logy(): array {
    $radky = [];
    $vysledek = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['message', 'variables'])
      ->condition('type', 'hwdesk')
      ->execute();
    foreach ($vysledek as $zaznam) {
      $promenne = unserialize((string) $zaznam->variables, ['allowed_classes' => FALSE]);
      $nahrady = [];
      if (is_array($promenne)) {
        foreach ($promenne as $klic => $hodnota) {
          if (is_scalar($hodnota) || $hodnota === NULL) {
            $nahrady[(string) $klic] = (string) $hodnota;
          }
        }
      }
      $radky[] = strtr((string) $zaznam->message, $nahrady);
    }
    return $radky;
  }

  public function testStitekPluginuJeCeskySDiakritikou(): void {
    $definice = $this->container->get('plugin.manager.mail')->getDefinition('hwdesk_mail');

    $this->assertSame('HW Desk (SMTP z nastavení modulu)', (string) $definice['label']);
  }

}
