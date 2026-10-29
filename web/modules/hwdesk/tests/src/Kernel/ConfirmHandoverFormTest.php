<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\HandoverService;
use Drupal\hwdesk\Service\HandoverTokens;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Předávák: stránka z e-mailu, souhlas s podmínkami a jednorázový odkaz.
 */
#[Group('hwdesk')]
final class ConfirmHandoverFormTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  private const FORM = '\Drupal\hwdesk\Form\ConfirmHandoverForm';

  private const PODMINKY = '<h3>Podmínky převzetí</h3><p>Zařízení zůstává majetkem firmy.</p>';

  private const PODMINKY_VRACENI = '<p>Vracím zařízení v pořádku.</p>';

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  private UserInterface $jana;

  private UserInterface $spravce;

  private int $serial = 0;

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('hwdesk.settings')
      ->set('base_url', 'https://intranet.example')
      ->set('company_name', 'Firma s.r.o.')
      ->set('handover_token_hours', 72)
      ->set('handover_terms', self::PODMINKY)
      ->set('return_terms', '')
      ->set('handover_consent_label', 'Přečetl/a jsem podmínky a souhlasím s nimi.')
      ->save();
    $this->jana = $this->makeUser('jana@firma.cz', 'Jana Nováková');
    $this->spravce = $this->makeUser('spravce@firma.cz', 'Správce');
  }

  private function service(): HandoverService {
    return $this->container->get('hwdesk.handover');
  }

  private function tokens(): HandoverTokens {
    return $this->container->get('hwdesk.handover_tokens');
  }

  /**
   * Výzva tak, jak ji vystaví aplikace, plus odkaz, který jinak zná jen e-mail.
   *
   * @return array{0: \Drupal\hwdesk\Entity\Handover, 1: string}
   */
  private function vyzva(HandoverKind $kind = HandoverKind::Handover): array {
    $this->serial++;
    $asset = $this->makeAsset(sprintf('NB-%04d', $this->serial));
    if ($kind === HandoverKind::ReturnItem) {
      $asset->setStatus(AssetStatus::Assigned)->setHolder($this->jana)->save();
    }
    $handover = $this->service()->request($asset, $this->jana, $kind, $this->spravce);
    $token = $this->tokens()->issue((int) $handover->id(), (int) $this->jana->id(), $handover->getExpires());
    return [$handover, $token];
  }

  private function reload(Handover $handover): Handover {
    $storage = $this->container->get('entity_type.manager')->getStorage('hwdesk_handover');
    $storage->resetCache([$handover->id()]);
    $loaded = $storage->load($handover->id());
    assert($loaded instanceof Handover);
    return $loaded;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function submit(string $token, string $button, array $values = []): FormState {
    $form_state = new FormState();
    $form_state->setValues($values);
    $form_state->setTriggeringElement(['#name' => $button, '#parents' => []]);
    $this->container->get('form_builder')->submitForm(self::FORM, $form_state, $token);
    return $form_state;
  }

  /**
   * @return array<string, mixed>
   */
  private function build(string $token): array {
    return $this->container->get('form_builder')->getForm(self::FORM, $token);
  }

  /**
   * Druhá cesta: přihlášený zaměstnanec, žádný odkaz.
   *
   * @param array<string, mixed> $values
   */
  private function submitAsOwner(Handover $handover, string $button, array $values = []): FormState {
    $form_state = new FormState();
    $form_state->setValues($values);
    $form_state->setTriggeringElement(['#name' => $button, '#parents' => []]);
    $this->container->get('form_builder')->submitForm(self::FORM, $form_state, '', $handover);
    return $form_state;
  }

  public function testAnonymousVisitorConfirmsWithoutLoggingIn(): void {
    [$handover, $token] = $this->vyzva();
    $this->assertTrue($this->container->get('current_user')->isAnonymous(), 'Odkaz z e-mailu se otevírá nepřihlášeně.');

    $form_state = $this->submit($token, 'confirm', ['consent' => 1]);

    $this->assertSame([], $form_state->getErrors());
    $potvrzene = $this->reload($handover);
    $this->assertSame(HandoverStatus::Confirmed, $potvrzene->getStatus());
    $this->assertNotSame('', $potvrzene->getProtocolNumber(), 'Potvrzení vystaví protokol.');
  }

  public function testTheConsentedTextAndItsTimeAreStoredOnTheHandover(): void {
    [$handover, $token] = $this->vyzva();
    $this->submit($token, 'confirm', ['consent' => 1]);

    $potvrzene = $this->reload($handover);
    $this->assertSame(self::PODMINKY, (string) $potvrzene->get('terms')->value, 'Ukládá se text, který měl zaměstnanec před sebou.');
    $this->assertGreaterThan(0, (int) $potvrzene->get('consented')->value, 'Ukládá se i čas souhlasu.');
  }

  public function testConfirmingWithoutConsentIsRefused(): void {
    [$handover, $token] = $this->vyzva();
    $form_state = $this->submit($token, 'confirm', ['consent' => 0]);

    $this->assertArrayHasKey('consent', $form_state->getErrors(), 'Bez zakliknutí souhlasu se převzít nedá.');
    $nedotcene = $this->reload($handover);
    $this->assertSame(HandoverStatus::Pending, $nedotcene->getStatus());
    $this->assertSame('', (string) $nedotcene->get('consented')->value);
    $this->assertNotNull($this->tokens()->verify($token), 'Neúspěšný pokus odkaz nespálí.');
  }

  public function testALaterEditOfTheTextDoesNotChangeWhatWasSigned(): void {
    [$handover, $token] = $this->vyzva();
    $this->submit($token, 'confirm', ['consent' => 1]);
    $this->config('hwdesk.settings')->set('handover_terms', '<p>Úplně jiné podmínky.</p>')->save();

    $this->assertSame(self::PODMINKY, (string) $this->reload($handover)->get('terms')->value);
  }

  public function testReturnHasItsOwnTextOnlyWhenItIsFilledIn(): void {
    [$bezVlastnich, $token1] = $this->vyzva(HandoverKind::ReturnItem);
    $this->submit($token1, 'confirm', ['consent' => 1]);
    $this->assertSame(self::PODMINKY, (string) $this->reload($bezVlastnich)->get('terms')->value, 'Prázdné podmínky vrácení znamenají ty převzetí.');

    $this->config('hwdesk.settings')->set('return_terms', self::PODMINKY_VRACENI)->save();
    [$sVlastnimi, $token2] = $this->vyzva(HandoverKind::ReturnItem);
    $this->submit($token2, 'confirm', ['consent' => 1]);
    $this->assertSame(self::PODMINKY_VRACENI, (string) $this->reload($sVlastnimi)->get('terms')->value);

    [$prevzeti, $token3] = $this->vyzva();
    $this->submit($token3, 'confirm', ['consent' => 1]);
    $this->assertSame(self::PODMINKY, (string) $this->reload($prevzeti)->get('terms')->value, 'Převzetí se podmínkami vrácení neřídí.');
  }

  public function testRejectionNeedsAReasonButNoConsent(): void {
    [$bezDuvodu, $token1] = $this->vyzva();
    $form_state = $this->submit($token1, 'reject', ['reason' => '   ']);
    $this->assertArrayHasKey('reason', $form_state->getErrors());
    $this->assertSame(HandoverStatus::Pending, $this->reload($bezDuvodu)->getStatus());

    [$odmitnute, $token2] = $this->vyzva();
    $form_state = $this->submit($token2, 'reject', ['reason' => 'Prasklý displej.']);
    $this->assertSame([], $form_state->getErrors(), 'Odmítnout se dá bez souhlasu s podmínkami.');
    $hotove = $this->reload($odmitnute);
    $this->assertSame(HandoverStatus::Rejected, $hotove->getStatus());
    $this->assertSame('Prasklý displej.', $hotove->getReason());
  }

  public function testTheLinkCannotBeUsedTwice(): void {
    [, $token] = $this->vyzva();
    $this->submit($token, 'confirm', ['consent' => 1]);

    $this->expectException(NotFoundHttpException::class);
    $this->build($token);
  }

  public function testAnInvalidLinkIsNotFound(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->build(str_repeat('a', 43));
  }

  public function testThePageShowsTheTextAndTheConsentLabel(): void {
    $this->config('hwdesk.settings')
      ->set('handover_terms', self::PODMINKY . '<script>alert(1)</script>')
      ->save();
    [, $token] = $this->vyzva();
    $form = $this->build($token);

    $this->assertArrayHasKey('terms', $form, 'Text podmínek patří na stránku.');
    $markup = (string) $form['terms']['#markup'];
    $this->assertStringContainsString('hwdesk-terms', $markup, 'Text je v bloku s třídou hwdesk-terms.');
    $this->assertStringContainsString('Zařízení zůstává majetkem firmy.', $markup);
    $this->assertStringContainsString('<h3>', $markup, 'Formátování textu se zachová.');
    $this->assertStringNotContainsString('<script', $markup, 'Text z nastavení se propouští přes Xss::filterAdmin.');

    $this->assertArrayHasKey('consent', $form);
    $this->assertSame('Přečetl/a jsem podmínky a souhlasím s nimi.', (string) $form['consent']['#title']);
    $this->assertSame('checkbox', $form['consent']['#type']);
  }

  public function testTheLoggedInEmployeeConfirmsWithoutSpendingTheMailedLink(): void {
    [$handover, $token] = $this->vyzva();
    $this->container->get('current_user')->setAccount($this->jana);

    $form_state = $this->submitAsOwner($handover, 'confirm', ['consent' => 1]);

    $this->assertSame([], $form_state->getErrors());
    $potvrzene = $this->reload($handover);
    $this->assertSame(HandoverStatus::Confirmed, $potvrzene->getStatus());
    $this->assertSame(self::PODMINKY, (string) $potvrzene->get('terms')->value);
    $this->assertSame(hash('sha256', $token), (string) $potvrzene->get('token_hash')->value, 'Odkaz z e-mailu se tu nespotřebovává.');
  }

  public function testSomebodyElsesHandoverIsRefused(): void {
    [$handover] = $this->vyzva();
    $this->container->get('current_user')->setAccount($this->spravce);

    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('form_builder')->getForm(self::FORM, '', $handover);
  }

  public function testWithoutAnyTermsNothingHasToBeTicked(): void {
    $this->config('hwdesk.settings')->set('handover_terms', '')->set('return_terms', '')->save();
    [$handover, $token] = $this->vyzva();

    $form = $this->build($token);
    $this->assertArrayNotHasKey('consent', $form, 'Bez podmínek není co zaklikávat.');

    $form_state = $this->submit($token, 'confirm');
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(HandoverStatus::Confirmed, $this->reload($handover)->getStatus());
  }

}
