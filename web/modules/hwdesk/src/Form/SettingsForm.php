<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Asset types with tag prefixes, protocol and label settings.
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
    $types = (array) $config->get('asset_types');
    $prefixes = (array) $config->get('tag_prefixes');
    $lines = [];
    foreach ($types as $key => $label) {
      $lines[] = sprintf('%s|%s|%s', $key, $label, $prefixes[$key] ?? '');
    }
    $form['types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Typy zařízení'),
      '#description' => $this->t('Jeden typ na řádek: klíč|Název|PREFIX (např. notebook|Notebook|NB). Klíč jen a–z, 0–9 a podtržítko; prefix velká písmena a číslice. Typ „other“ musí existovat.'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 8,
      '#required' => TRUE,
    ];
    $form['tag_pad'] = ['#type' => 'number', '#title' => $this->t('Minimální počet číslic v inventárním čísle'), '#min' => 1, '#max' => 8, '#default_value' => $config->get('tag_pad')];
    $form['handover_token_hours'] = ['#type' => 'number', '#title' => $this->t('Platnost výzvy k potvrzení (hodin)'), '#min' => 1, '#max' => 720, '#default_value' => $config->get('handover_token_hours')];
    $form['company_name'] = ['#type' => 'textfield', '#title' => $this->t('Název firmy na protokolech'), '#default_value' => $config->get('company_name')];
    $form['protocol_copy_to'] = ['#type' => 'email', '#title' => $this->t('Kopie každého protokolu na e-mail'), '#default_value' => $config->get('protocol_copy_to')];
    $form['base_url'] = ['#type' => 'url', '#title' => $this->t('Základní URL webu pro QR kódy a odkazy v e-mailech'), '#description' => $this->t('Prázdné = podle aktuálního požadavku.'), '#default_value' => $config->get('base_url')];
    $form['label_printer_host'] = ['#type' => 'textfield', '#title' => $this->t('Síťová tiskárna štítků (Zebra, port 9100)'), '#description' => $this->t('Adresa nebo hostname; prázdné = jen tisk z prohlížeče.'), '#default_value' => $config->get('label_printer_host')];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    [$types, $prefixes, $error] = self::parseTypes((string) $form_state->getValue('types'));
    if ($error !== NULL) {
      $form_state->setErrorByName('types', $error);
      return;
    }
    $form_state->set('parsed_types', [$types, $prefixes]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$types, $prefixes] = $form_state->get('parsed_types');
    $this->config('hwdesk.settings')
      ->set('asset_types', $types)
      ->set('tag_prefixes', $prefixes)
      ->set('tag_pad', (int) $form_state->getValue('tag_pad'))
      ->set('handover_token_hours', (int) $form_state->getValue('handover_token_hours'))
      ->set('company_name', trim((string) $form_state->getValue('company_name')))
      ->set('protocol_copy_to', strtolower(trim((string) $form_state->getValue('protocol_copy_to'))))
      ->set('base_url', rtrim(trim((string) $form_state->getValue('base_url')), '/'))
      ->set('label_printer_host', trim((string) $form_state->getValue('label_printer_host')))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * @return array{0: array<string, string>, 1: array<string, string>, 2: string|null}
   */
  public static function parseTypes(string $text): array {
    $types = [];
    $prefixes = [];
    foreach (preg_split('/\R/', trim($text)) ?: [] as $number => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) !== 3 || !preg_match('/^[a-z0-9_]+$/', $parts[0]) || $parts[1] === '' || !preg_match('/^[A-Z0-9]{1,8}$/', $parts[2])) {
        return [[], [], sprintf('Řádek %d: očekávám klíč|Název|PREFIX.', $number + 1)];
      }
      $types[$parts[0]] = $parts[1];
      $prefixes[$parts[0]] = $parts[2];
    }
    if (!isset($types['other'])) {
      return [[], [], 'Typ „other“ musí existovat (záložní prefix).'];
    }
    return [$types, $prefixes, NULL];
  }

}
