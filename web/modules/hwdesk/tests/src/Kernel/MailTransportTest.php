<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\Service\MailTransport;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Adresa odesílatele a cesta k SMTP serveru: heslo jen z klíče, nikdy do textu.
 */
#[Group('hwdesk')]
final class MailTransportTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  private const HESLO = 'tajne heslo+42';

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'key', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->installConfig(['key']);
    Key::create([
      'id' => 'smtp_heslo',
      'label' => 'SMTP heslo',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => self::HESLO],
    ])->save();
  }

  private function transport(): MailTransport {
    return $this->container->get('hwdesk.mail_transport');
  }

  /**
   * @param array<string, mixed> $values
   */
  private function nastav(array $values): void {
    $config = $this->config('hwdesk.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  public function testWithoutSmtpTheSiteSendsTheUsualWay(): void {
    $this->nastav(['smtp_enabled' => FALSE, 'smtp_host' => 'smtp.firma.cz']);
    $this->assertFalse($this->transport()->isSmtp());
    $this->assertSame('sendmail://default', $this->transport()->dsn());

    // Zapnuté SMTP bez adresy serveru není SMTP; jinak by pošta tiše mizela.
    $this->nastav(['smtp_enabled' => TRUE, 'smtp_host' => '   ']);
    $this->assertFalse($this->transport()->isSmtp());
    $this->assertSame('sendmail://default', $this->transport()->dsn());
  }

  public function testStarttlsIsTheOrdinaryCase(): void {
    $this->nastav([
      'smtp_enabled' => TRUE,
      'smtp_host' => 'smtp.firma.cz',
      'smtp_port' => 587,
      'smtp_security' => 'tls',
      'smtp_user' => 'asistentka@firma.cz',
      'smtp_key' => 'smtp_heslo',
    ]);
    $this->assertTrue($this->transport()->isSmtp());
    $this->assertSame(
      'smtp://asistentka%40firma.cz:' . rawurlencode(self::HESLO) . '@smtp.firma.cz:587',
      $this->transport()->dsn(),
      'Jméno i heslo musí být zakódované, jinak zavináč v jménu rozbije adresu.'
    );
  }

  public function testImplicitTlsUsesItsOwnScheme(): void {
    $this->nastav([
      'smtp_enabled' => TRUE,
      'smtp_host' => 'smtp.firma.cz',
      'smtp_port' => 465,
      'smtp_security' => 'ssl',
      'smtp_user' => '',
      'smtp_key' => '',
    ]);
    $this->assertSame('smtps://smtp.firma.cz:465', $this->transport()->dsn(), 'Bez přihlašovacího jména se zavináč nepíše.');
  }

  public function testUnencryptedHasToBeSaidOutLoud(): void {
    $this->nastav([
      'smtp_enabled' => TRUE,
      'smtp_host' => '10.0.0.5',
      'smtp_port' => 25,
      'smtp_security' => 'none',
      'smtp_user' => '',
      'smtp_key' => '',
    ]);
    $this->assertSame('smtp://10.0.0.5:25?auto_tls=false', $this->transport()->dsn());
  }

  public function testAUserWithoutAKeyGetsNoPassword(): void {
    $this->nastav([
      'smtp_enabled' => TRUE,
      'smtp_host' => 'smtp.firma.cz',
      'smtp_port' => 587,
      'smtp_security' => 'tls',
      'smtp_user' => 'asistentka',
      'smtp_key' => 'neexistujici_klic',
    ]);
    $this->assertSame('smtp://asistentka@smtp.firma.cz:587', $this->transport()->dsn(), 'Chybějící klíč nesmí shodit sestavení adresy.');
  }

  public function testTheSenderIsTheAddressFromTheSettings(): void {
    $this->nastav([
      'mail_from' => 'asistentka@firma.cz',
      'mail_from_name' => 'Asistentka Firma',
      'mail_reply_to' => 'it@firma.cz',
    ]);
    $this->assertSame(['address' => 'asistentka@firma.cz', 'name' => 'Asistentka Firma'], $this->transport()->from());
    $this->assertSame('it@firma.cz', $this->transport()->replyTo());

    $this->nastav(['mail_from' => '', 'mail_from_name' => '', 'mail_reply_to' => '']);
    $this->assertSame([], $this->transport()->from(), 'Bez adresy v nastavení se odesílatel nepřepisuje.');
    $this->assertSame('', $this->transport()->replyTo());
  }

  public function testTheFailureNamesThePlaceAndTheReasonButNotThePassword(): void {
    $this->nastav([
      'smtp_enabled' => TRUE,
      'smtp_host' => 'smtp.firma.cz',
      'smtp_port' => 587,
      'smtp_security' => 'tls',
      'smtp_user' => 'asistentka@firma.cz',
      'smtp_key' => 'smtp_heslo',
    ]);
    // Takhle mluví Symfony: v hlášce bývá celá adresa včetně hesla.
    $vyjimka = new \RuntimeException(sprintf(
      'Connection could not be established with host "smtp://asistentka%%40firma.cz:%s@smtp.firma.cz:587": stream_socket_client(): Connection refused',
      rawurlencode(self::HESLO)
    ));

    $popis = $this->transport()->describeFailure($vyjimka, 'odeslání protokolu');

    $this->assertStringContainsString('Connection refused', $popis, 'Důvod musí být v hlášce.');
    $this->assertStringContainsString('odeslání protokolu', $popis, 'Místo v aplikaci taky.');
    $this->assertStringContainsString('MailTransportTest.php', $popis, 'A soubor s řádkem, kde to spadlo.');
    $this->assertMatchesRegularExpression('/MailTransportTest\.php:\d+/', $popis);
    $this->assertStringNotContainsString(self::HESLO, $popis, 'Heslo nesmí do logu.');
    $this->assertStringNotContainsString(rawurlencode(self::HESLO), $popis, 'Ani zakódované.');
  }

  public function testAFailureWithoutAPasswordIsDescribedToo(): void {
    $this->nastav(['smtp_enabled' => FALSE, 'smtp_user' => '', 'smtp_key' => '']);
    $popis = $this->transport()->describeFailure(new \LogicException('sendmail chybí'), 'test odeslání');
    $this->assertStringContainsString('sendmail chybí', $popis);
    $this->assertStringContainsString('test odeslání', $popis);
    $this->assertMatchesRegularExpression('/MailTransportTest\.php:\d+/', $popis);
  }

  /**
   * Modul Key je v závislostech, takže na webu je vždycky. Kdyby ho ale někdo
   * vypnul, nesmí se pošta rozsypat nesrozumitelně: heslo prostě není a
   * transport to řekne nahlas, aby se důvod dostal do logu.
   */
  public function testWithoutTheKeyModuleThePasswordIsSimplyMissingAndItSaysSo(): void {
    $this->nastav([
      'smtp_enabled' => TRUE,
      'smtp_host' => 'smtp.firma.cz',
      'smtp_port' => 587,
      'smtp_security' => 'tls',
      'smtp_user' => 'asistentka@firma.cz',
      'smtp_key' => 'smtp_heslo',
    ]);
    $bezKlicenky = new MailTransport($this->container->get('config.factory'), NULL);

    $this->assertTrue($bezKlicenky->isSmtp(), 'Server je nastavený, takže SMTP to je.');
    $this->assertTrue($bezKlicenky->keyMissing(), 'Nastavený klíč bez klíčenky je důvod k zápisu do logu.');
    $this->assertStringNotContainsString(self::HESLO, $bezKlicenky->dsn());
    $this->assertSame('smtp://asistentka%40firma.cz@smtp.firma.cz:587', $bezKlicenky->dsn());

    $sKlicenkou = $this->transport();
    $this->assertFalse($sKlicenkou->keyMissing(), 'S klíčenkou a nalezeným klíčem není co hlásit.');
    $this->nastav(['smtp_key' => '']);
    $this->assertFalse($sKlicenkou->keyMissing(), 'Nenastavené heslo není chybějící heslo.');
  }

}
