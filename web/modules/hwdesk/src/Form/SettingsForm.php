<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings → Obecné: company, links, how long a handover request lives.
 */
final class SettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'hwdesk_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['hwdesk.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('hwdesk.settings');
    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Název firmy na protokolech'),
      '#default_value' => $config->get('company_name'),
    ];
    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Základní URL webu'),
      '#description' => $this->t('Pro QR kódy a odkazy v e-mailech. Prázdné = podle aktuálního požadavku.'),
      '#default_value' => $config->get('base_url'),
    ];
    $form['protocol_copy_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Kopie každého protokolu na e-mail'),
      '#description' => $this->t('Prázdné = protokol dostane jen zaměstnanec.'),
      '#default_value' => $config->get('protocol_copy_to'),
    ];
    $form['handover_token_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Platnost výzvy k potvrzení (hodin)'),
      '#description' => $this->t('Po této době odkaz v e-mailu přestane platit a výzva se sama zruší.'),
      '#min' => 1,
      '#max' => 720,
      '#default_value' => $config->get('handover_token_hours'),
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('hwdesk.settings')
      ->set('company_name', trim((string) $form_state->getValue('company_name')))
      ->set('base_url', rtrim(trim((string) $form_state->getValue('base_url')), '/'))
      ->set('protocol_copy_to', strtolower(trim((string) $form_state->getValue('protocol_copy_to'))))
      ->set('handover_token_hours', (int) $form_state->getValue('handover_token_hours'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
