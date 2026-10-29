<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings → Předávací protokol: the text the employee has to agree with.
 *
 * The text is stored as written and copied onto every handover at the moment
 * of consent, so a later edit never changes what somebody already signed.
 */
final class HandoverTermsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'hwdesk_handover_terms';
  }

  protected function getEditableConfigNames(): array {
    return ['hwdesk.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('hwdesk.settings');

    $form['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['hwdesk-help']],
      '#value' => $this->t('Text se zaměstnanci ukáže na stránce s potvrzením převzetí a vytiskne se do protokolu. Bez zakliknutí souhlasu zařízení nepřevezme.'),
    ];

    $form['handover_terms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Podmínky převzetí'),
      '#description' => $this->t('Formátování se píše značkami HTML: odstavce &lt;p&gt;, nadpisy &lt;h2&gt; a &lt;h3&gt;, odrážky &lt;ul&gt;&lt;li&gt;, tučně &lt;strong&gt;, kurzíva &lt;em&gt;, odkaz &lt;a href&gt;, tabulka &lt;table&gt;. Prázdné = souhlas se nevyžaduje.'),
      '#default_value' => $config->get('handover_terms'),
      '#rows' => 14,
    ];

    $form['return_terms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Podmínky vrácení'),
      '#description' => $this->t('Totéž pro vracení zařízení. Prázdné = použijí se podmínky převzetí.'),
      '#default_value' => $config->get('return_terms'),
      '#rows' => 8,
    ];

    $form['handover_consent_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Text u zaškrtávátka'),
      '#description' => $this->t('Jedna věta, se kterou zaměstnanec souhlasí.'),
      '#default_value' => $config->get('handover_consent_label'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $saved = (string) $config->get('handover_terms');
    if (trim($saved) !== '') {
      $form['preview'] = [
        '#type' => 'details',
        '#title' => $this->t('Jak uložený text vypadá'),
        '#open' => TRUE,
      ];
      $form['preview']['text'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['hwdesk-terms']],
        '#value' => Xss::filterAdmin($saved),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('hwdesk.settings')
      ->set('handover_terms', trim((string) $form_state->getValue('handover_terms')))
      ->set('return_terms', trim((string) $form_state->getValue('return_terms')))
      ->set('handover_consent_label', trim((string) $form_state->getValue('handover_consent_label')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
