<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\HandoverService;
use Drupal\hwdesk\Service\HandoverTokens;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page behind the link in the e-mail: confirm, or reject with a reason.
 *
 * The link itself proves who the visitor is, so nobody has to log in. The
 * employee ticks that they agree with the handover terms; the text they
 * agreed to is copied onto the handover at that moment, so a later edit of
 * the settings never changes what somebody already signed.
 */
final class ConfirmHandoverForm extends FormBase {

  public function __construct(
    protected readonly HandoverService $handovers,
    protected readonly HandoverTokens $tokens,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('hwdesk.handover'),
      $container->get('hwdesk.handover_tokens'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
    // FormBase already declares an untyped, read-write $configFactory; a
    // typed redeclaration would be a fatal error, so the inherited property
    // is assigned here from the same service the constructor would take.
    $form->configFactory = $container->get('config.factory');
    return $form;
  }

  public function getFormId(): string {
    return 'hwdesk_confirm_handover';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $token = '', ?Handover $hwdesk_handover = NULL): array {
    if ($token !== '') {
      // The link is the proof of identity; nothing is compared with the
      // logged-in account.
      $claims = $this->tokens->verify($token);
      if ($claims === NULL) {
        throw new NotFoundHttpException('Odkaz je neplatný nebo vypršel.');
      }
      $handover = Handover::load($claims['handover']);
      if (!$handover instanceof Handover) {
        throw new NotFoundHttpException('Odkaz je neplatný nebo vypršel.');
      }
    }
    elseif ($hwdesk_handover !== NULL) {
      // The logged-in employee opens the same page without a link; the
      // handover must belong to them and still be open. Nothing is consumed,
      // so the link in the e-mail stays valid.
      if ((int) $hwdesk_handover->getUser()?->id() !== (int) $this->currentUser()->id()) {
        throw new AccessDeniedHttpException('Tato výzva patří jinému zaměstnanci.');
      }
      if ($hwdesk_handover->getStatus() !== HandoverStatus::Pending || $hwdesk_handover->getExpires() < $this->time->getRequestTime()) {
        throw new NotFoundHttpException('Odkaz je neplatný nebo vypršel.');
      }
      $handover = $hwdesk_handover;
    }
    else {
      throw new NotFoundHttpException('Odkaz je neplatný nebo vypršel.');
    }

    $config = $this->configFactory->get('hwdesk.settings');
    if ($handover->getKind() === HandoverKind::ReturnItem) {
      $terms = (string) $config->get('return_terms');
      if (trim($terms) === '') {
        $terms = (string) $config->get('handover_terms');
      }
    }
    else {
      $terms = (string) $config->get('handover_terms');
    }

    $form_state->set('handover_id', (int) $handover->id());
    $form_state->set('token', $token);
    $form_state->set('terms', $terms);

    $asset = $handover->getAsset();
    $form['summary'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Druh: @kind', ['@kind' => $handover->getKind()->label()]),
        $this->t('Zařízení: @name (@tag)', ['@name' => $asset?->getDisplayName() ?? '', '@tag' => $asset?->getTag() ?? '']),
        $this->t('Sériové číslo: @sn', ['@sn' => $asset?->getSerialNumber() ?: '–']),
        $this->t('Stav kusu: @c', ['@c' => $asset?->getCondition()->label() ?? '']),
        $this->t('Platnost výzvy do: @until', ['@until' => $this->dateFormatter->format($handover->getExpires(), 'custom', 'j. n. Y H:i')]),
      ],
    ];

    if ($terms !== '') {
      $form['terms'] = ['#markup' => '<div class="hwdesk-terms">' . Xss::filterAdmin($terms) . '</div>'];
      // No '#required': rejecting must stay possible without ticking it.
      $form['consent'] = [
        '#type' => 'checkbox',
        '#title' => (string) $config->get('handover_consent_label'),
        '#default_value' => 0,
      ];
    }

    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Důvod odmítnutí (jen pokud odmítáte)'),
      '#maxlength' => 500,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $handover->getKind() === HandoverKind::ReturnItem ? $this->t('Potvrzuji vrácení') : $this->t('Potvrzuji převzetí'),
      '#button_type' => 'primary',
      '#name' => 'confirm',
    ];
    $form['actions']['reject'] = ['#type' => 'submit', '#value' => $this->t('Odmítnout'), '#name' => 'reject'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $form = $form;
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'confirm') {
      // The checkbox value callback turns both a missing input and NULL into
      // integer 0, so empty() is the only correct test for "not ticked".
      if ((string) $form_state->get('terms') !== '' && empty($form_state->getValue('consent'))) {
        $form_state->setErrorByName('consent', $this->t('Bez souhlasu s podmínkami převzetí nelze pokračovat.'));
      }
    }
    elseif ($trigger === 'reject') {
      if (trim((string) $form_state->getValue('reason')) === '') {
        $form_state->setErrorByName('reason', $this->t('Při odmítnutí uveďte důvod.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form = $form;
    $handover = Handover::load($form_state->get('handover_id'));
    if (!$handover instanceof Handover) {
      return;
    }
    // The identified employee is what the service checks; the visitor is
    // usually anonymous here, so never the current user.
    $actor = $handover->getUser();
    if ($actor === NULL) {
      return;
    }
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    try {
      if ($trigger === 'reject') {
        $this->handovers->reject($handover, $actor, (string) $form_state->getValue('reason'));
        $this->messenger()->addStatus($this->t('Odmítnuto, zadavatel dostal zprávu.'));
      }
      else {
        // Freeze the agreement first: the PDF protocol is generated inside
        // confirm() and prints the terms, so the text must be stored before.
        $terms = (string) $form_state->get('terms');
        $handover->set('terms', $terms)->set('consented', $this->time->getRequestTime())->save();
        $confirmed = $this->handovers->confirm($handover, $actor);
        // The e-mail is only sent, never read back: say what was done and
        // what to do if nothing comes, never that it was delivered.
        $this->messenger()->addStatus($this->t('Potvrzeno. Protokol @number vám posíláme e-mailem. Kdyby nedorazil, ozvěte se IT.', ['@number' => $confirmed->getProtocolNumber()]));
      }
    }
    catch (\DomainException | \InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    // Only a successful decision spends the link; on the logged-in route the
    // token is empty and the mailed link stays valid.
    $t = (string) $form_state->get('token');
    if ($t !== '') {
      $this->tokens->consume($t);
    }
    // An anonymous visitor cannot open the employee page.
    $form_state->setRedirect($this->currentUser()->isAuthenticated() ? 'hwdesk.my' : '<front>');
  }

}
