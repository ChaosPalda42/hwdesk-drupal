<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;

/**
 * Přepnutí stavu zařízení rovnou na detailu.
 *
 * V nabídce jsou jen stavy, do kterých se z toho současného smí přejít —
 * nabídka tak nikdy nenabídne krok, který by evidence odmítla. Výzvu k
 * převzetí tudy zrušit nejde; ta se ruší u protokolu, aby se zařízení vrátilo
 * do stavu, ve kterém bylo předtím.
 */
final class AssetStatusForm extends FormBase {

  public function getFormId(): string {
    return 'hwdesk_asset_status';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?Asset $hwdesk_asset = NULL): array {
    if ($hwdesk_asset === NULL) {
      return $form;
    }
    $from = AssetStatus::tryFrom((string) $hwdesk_asset->get('status')->value);
    if ($from === NULL) {
      return $form;
    }
    $form_state->set('asset_id', $hwdesk_asset->id());

    $options = [];
    foreach (AssetStatus::cases() as $case) {
      if ($case !== $from && $from->canTransitionTo($case) && $case !== AssetStatus::PendingHandover && $case !== AssetStatus::PendingReturn) {
        $options[$case->value] = $case->label();
      }
    }
    if ($options === []) {
      return $form;
    }

    $form['#attributes']['class'][] = 'row-edit';
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Přepnout stav'),
      '#title_display' => 'invisible',
      '#options' => $options,
      '#empty_option' => $this->t('— přepnout stav —'),
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Důvod'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('důvod do historie'),
      '#size' => 24,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Změnit'),
      '#attributes' => ['class' => ['btn', 'secondary', 'sm']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ((string) $form_state->getValue('status') === '') {
      $form_state->setErrorByName('status', $this->t('Vyberte stav.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $asset = Asset::load($form_state->get('asset_id'));
    $to = AssetStatus::tryFrom((string) $form_state->getValue('status'));
    if (!$asset instanceof Asset || $to === NULL) {
      $this->messenger()->addError($this->t('Zařízení nebo stav nebyl nalezen.'));
      return;
    }
    $from = AssetStatus::tryFrom((string) $asset->get('status')->value);
    if ($from === NULL || !$from->canTransitionTo($to)) {
      $this->messenger()->addError($this->t('Ze stavu @from do @to přejít nelze.', [
        '@from' => $from?->label() ?? '?',
        '@to' => $to->label(),
      ]));
      return;
    }
    $note = trim((string) $form_state->getValue('note'));
    $asset->set('status', $to->value);
    if ($to === AssetStatus::InStock) {
      $asset->set('holder', NULL);
    }
    $asset->setNewRevision(TRUE);
    $asset->setRevisionLogMessage($note !== ''
      ? (string) $this->t('Stav @to: @note', ['@to' => $to->label(), '@note' => $note])
      : (string) $this->t('Stav @to', ['@to' => $to->label()]));
    $asset->save();
    $this->messenger()->addStatus($this->t('Stav zařízení @tag je @to.', ['@tag' => $asset->label(), '@to' => $to->label()]));
    $form_state->setRedirect('entity.hwdesk_asset.canonical', ['hwdesk_asset' => $asset->id()]);
  }

}
