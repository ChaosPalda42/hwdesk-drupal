<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\Service\HandoverService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrator cancels a pending handover/return request.
 */
final class CancelHandoverForm extends ConfirmFormBase {

  private ?Handover $handover = NULL;

  public function __construct(protected readonly HandoverService $handovers) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.handover'));
  }

  public function getFormId(): string {
    return 'hwdesk_cancel_handover';
  }

  public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    $asset = $this->handover?->getAsset();
    return $this->t('Zrušit výzvu pro @tag (@user)?', ['@tag' => $asset?->getTag() ?? '', '@user' => $this->handover?->getUser()?->getDisplayName() ?? '']);
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('view.hwdesk_handovers.page');
  }

  public function getConfirmText(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Zrušit výzvu');
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?Handover $hwdesk_handover = NULL): array {
    $this->handover = $hwdesk_handover;
    $form_state->set('handover_id', $hwdesk_handover?->id());
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $handover = Handover::load($form_state->get('handover_id'));
    if ($handover instanceof Handover) {
      try {
        $this->handovers->cancel($handover, $this->currentUser());
        $this->messenger()->addStatus($this->t('Výzva zrušena, zařízení je zpět v původním stavu.'));
      }
      catch (\DomainException $e) {
        $this->messenger()->addError($e->getMessage());
      }
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
