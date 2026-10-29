<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings → Inventární čísla a štítky: device types, tag format, printer.
 */
final class LabelSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'hwdesk_label_settings';
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
    $form['tag_pad'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimální počet číslic v inventárním čísle'),
      '#description' => $this->t('Například 4 dá NB-0001. Inventární číslo je zároveň to, co je na štítku a v QR kódu.'),
      '#min' => 1,
      '#max' => 8,
      '#default_value' => $config->get('tag_pad'),
      '#required' => TRUE,
    ];
    $form['label_printer_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Síťová tiskárna štítků (Zebra, port 9100)'),
      '#description' => $this->t('Adresa nebo hostname; prázdné = jen tisk z prohlížeče.'),
      '#default_value' => $config->get('label_printer_host'),
    ];
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
