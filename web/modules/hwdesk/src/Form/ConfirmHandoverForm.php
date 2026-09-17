<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\HandoverService;
use Drupal\hwdesk\Service\HandoverTokens;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page behind the link in the e-mail: confirm, or reject with a reason.
 */
final class ConfirmHandoverForm extends FormBase {

  public function __construct(
    protected readonly HandoverService $handovers,
    protected readonly HandoverTokens $tokens,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.handover'), $container->get('hwdesk.handover_tokens'), $container->get('date.formatter'));
  }

  public function getFormId(): string {
    return 'hwdesk_confirm_handover';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $token = ''): array {
    $claims = $this->tokens->verify($token);
    if ($claims === NULL) {
      throw new NotFoundHttpException('Odkaz je neplatný nebo vypršel.');
    }
    if ($claims['uid'] !== (int) $this->currentUser()->id()) {
      throw new AccessDeniedHttpException('Tento odkaz patří jinému účtu.');
    }
    $handover = Handover::load($claims['handover']);
    if (!$handover instanceof Handover) {
      throw new NotFoundHttpException();
    }
    $form_state->set('handover_id', $handover->id());
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
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      $form['done'] = ['#markup' => '<p>' . $this->t('Tato výzva už byla vyřízena: @status.', ['@status' => $handover->getStatus()->label()]) . '</p>'];
      return $form;
    }
    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Důvod odmítnutí (jen pokud odmítáte)'),
      '#maxlength' => 500,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirm'] = ['#type' => 'submit', '#value' => $this->t('Potvrzuji převzetí'), '#button_type' => 'primary', '#name' => 'confirm'];
    if ($handover->getKind()->value === 'return') {
      $form['actions']['confirm']['#value'] = $this->t('Potvrzuji vrácení');
    }
    $form['actions']['reject'] = ['#type' => 'submit', '#value' => $this->t('Odmítnout'), '#name' => 'reject'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'reject' && trim((string) $form_state->getValue('reason')) === '') {
      $form_state->setErrorByName('reason', $this->t('Při odmítnutí uveďte důvod.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $handover = Handover::load($form_state->get('handover_id'));
    if (!$handover instanceof Handover) {
      return;
    }
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    try {
      if ($trigger === 'reject') {
        $this->handovers->reject($handover, $this->currentUser(), (string) $form_state->getValue('reason'));
        $this->messenger()->addStatus($this->t('Odmítnuto, zadavatel dostal zprávu.'));
      }
      else {
        $confirmed = $this->handovers->confirm($handover, $this->currentUser());
        $this->messenger()->addStatus($this->t('Potvrzeno. Protokol @number vám přišel e-mailem.', ['@number' => $confirmed->getProtocolNumber()]));
      }
    }
    catch (\DomainException | \InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('hwdesk.my');
  }

}
