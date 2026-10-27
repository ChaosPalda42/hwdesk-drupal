<?php

declare(strict_types=1);

namespace Drupal\csobrec\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\csobrec\QueueMode;

/**
 * Queues, the hour of the morning run and the access to ČSOB's SFTP.
 *
 * The two secrets are deliberately missing from this form: config is exported
 * to yml and travels between machines, so the Daktela token and the SFTP
 * password are read from settings.php. The form only says whether they are
 * there, never what they are.
 */
final class SettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'csobrec_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['csobrec.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('csobrec.settings');

    $lines = [];
    foreach ((array) $config->get('fronty') as $queue => $mode) {
      $lines[] = $queue . '|' . $mode;
    }

    $form['daktela'] = [
      '#type' => 'details',
      '#title' => $this->t('Daktela'),
      '#open' => TRUE,
    ];
    $form['daktela']['daktela_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Adresa Daktely'),
      '#default_value' => $config->get('daktela_url'),
      '#required' => TRUE,
    ];
    $form['daktela']['fronty'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fronty a jejich režim'),
      '#description' => $this->t('Jedna fronta na řádek: číslo|režim. VSE = předá se každý hovor fronty. LOGIKA = jen hovor s dispozicí „[CSOB] Nový prodej“ nebo „[CSOB] Nový prodej TZ“ a k němu starší historie téhož telefonního čísla z téže fronty. Fronta, která tu není, se Daktely vůbec neptá.'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 8,
    ];
    $form['daktela']['token'] = [
      '#type' => 'item',
      '#title' => $this->t('Token k API'),
      '#markup' => Settings::get('csobrec_daktela_token', '') === ''
        ? $this->t('Není nastavený. Doplňte v settings.php: <code>$settings[\'csobrec_daktela_token\'] = \'…\';</code>')
        : $this->t('Nastavený v settings.php.'),
    ];

    $form['sftp'] = [
      '#type' => 'details',
      '#title' => $this->t('SFTP ČSOB'),
      '#open' => TRUE,
    ];
    $form['sftp']['sftp_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Adresa serveru'),
      '#default_value' => $config->get('sftp_host'),
    ];
    $form['sftp']['sftp_port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#min' => 1,
      '#max' => 65535,
      '#default_value' => $config->get('sftp_port') ?: 22,
    ];
    $form['sftp']['sftp_uzivatel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Přihlašovací jméno'),
      '#default_value' => $config->get('sftp_uzivatel'),
    ];
    $form['sftp']['sftp_cil'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cílová cesta na jejich serveru'),
      '#description' => $this->t('Například /upload/nahravky. Složku na jejich straně modul nezakládá: překlep by dávku poslal tam, kam se nikdo nekouká, a evidence by tvrdila, že ji ČSOB má. Když běh tu cestu jmenuje, je v ní překlep, nebo ji musí založit ČSOB.'),
      '#default_value' => $config->get('sftp_cil'),
    ];
    $form['sftp']['sftp_klic'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cesta k privátnímu klíči'),
      '#description' => $this->t('Prázdné = přihlašuje se heslem ze settings.php.'),
      '#default_value' => $config->get('sftp_klic'),
    ];
    $form['sftp']['heslo'] = [
      '#type' => 'item',
      '#title' => $this->t('Heslo'),
      '#markup' => Settings::get('csobrec_sftp_heslo', '') === ''
        ? $this->t('Není nastavené. Doplňte v settings.php: <code>$settings[\'csobrec_sftp_heslo\'] = \'…\';</code>, nebo vyplňte cestu ke klíči.')
        : $this->t('Nastavené v settings.php.'),
    ];
    $form['sftp']['sftp_otisk'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Otisk klíče serveru'),
      '#description' => $this->t('Tvar SHA256:… . Otisk toho, co na druhé straně odpovědělo, si každý běh zapisuje do logu — ale <strong>ověřte si ho u ČSOB</strong>, ne z našeho logu: ten by potvrdil i podvodníka. Jakmile je tu vyplněný, porovnává se vždycky.'),
      '#default_value' => $config->get('sftp_otisk'),
    ];
    $form['sftp']['sftp_overit_klic'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Bez ověřeného klíče serveru nic neposílat'),
      '#description' => $this->t('Dokud ČSOB otisk nepotvrdí, musí tohle zůstat vypnuté, jinak každý ranní běh spadne.'),
      '#default_value' => (bool) $config->get('sftp_overit_klic'),
    ];

    $form['provoz'] = [
      '#type' => 'details',
      '#title' => $this->t('Provoz'),
      '#open' => TRUE,
    ];
    $form['provoz']['hodina'] = [
      '#type' => 'number',
      '#title' => $this->t('Hodina ranní předávky'),
      '#description' => $this->t('Jen pro přehled a pro příkaz <code>drush csobrec:vcera</code>; spouští to systémový cron, ne Drupal.'),
      '#min' => 0,
      '#max' => 23,
      '#default_value' => (int) $config->get('hodina'),
    ];
    $form['provoz']['ffmpeg'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cesta k ffmpegu'),
      '#description' => $this->t('Prázdné = hledá se v PATH.'),
      '#default_value' => $config->get('ffmpeg'),
    ];
    $form['provoz']['davky_dni'] = [
      '#type' => 'number',
      '#title' => $this->t('Po kolika dnech mazat dávky z disku'),
      '#description' => $this->t('Evidence toho, co ČSOB dostal, se nemaže nikdy — jen soubory. 0 = nemazat nic.'),
      '#min' => 0,
      '#max' => 3650,
      '#default_value' => (int) $config->get('davky_dni'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    [$queues, $error] = $this->parseQueues((string) $form_state->getValue('fronty'));
    if ($error !== NULL) {
      $form_state->setErrorByName('fronty', $error);
    }
    else {
      $form_state->set('parsed_queues', $queues);
    }

    $target = trim((string) $form_state->getValue('sftp_cil'));
    if ($target !== '' && !str_starts_with($target, '/')) {
      $form_state->setErrorByName('sftp_cil', $this->t('Cílová cesta musí začínat lomítkem.'));
    }

    $fingerprint = trim((string) $form_state->getValue('sftp_otisk'));
    if ($fingerprint !== '' && preg_match('#^(SHA256:)?[A-Za-z0-9+/=]{20,}$#', $fingerprint) !== 1) {
      $form_state->setErrorByName('sftp_otisk', $this->t('Otisk má tvar SHA256: a za ním base64, tak jak ho vypíše ssh-keygen -lf.'));
    }
    if ((bool) $form_state->getValue('sftp_overit_klic') && $fingerprint === '') {
      $form_state->setErrorByName('sftp_otisk', $this->t('Ověřovat klíč nejde proti ničemu: buď doplňte otisk, nebo ověřování vypněte.'));
    }

    $key = trim((string) $form_state->getValue('sftp_klic'));
    if ($key !== '' && !is_readable($key)) {
      $form_state->setErrorByName('sftp_klic', $this->t('Soubor s klíčem se nedá přečíst. Zkontrolujte cestu a práva (vlastník má být uživatel webserveru, práva 0400).'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('csobrec.settings')
      ->set('daktela_url', rtrim(trim((string) $form_state->getValue('daktela_url')), '/'))
      ->set('fronty', $form_state->get('parsed_queues'))
      ->set('hodina', (int) $form_state->getValue('hodina'))
      ->set('sftp_host', trim((string) $form_state->getValue('sftp_host')))
      ->set('sftp_port', (int) $form_state->getValue('sftp_port'))
      ->set('sftp_uzivatel', trim((string) $form_state->getValue('sftp_uzivatel')))
      ->set('sftp_cil', rtrim(trim((string) $form_state->getValue('sftp_cil')), '/'))
      ->set('sftp_klic', trim((string) $form_state->getValue('sftp_klic')))
      ->set('sftp_otisk', trim((string) $form_state->getValue('sftp_otisk')))
      ->set('sftp_overit_klic', (bool) $form_state->getValue('sftp_overit_klic'))
      ->set('ffmpeg', trim((string) $form_state->getValue('ffmpeg')))
      ->set('davky_dni', (int) $form_state->getValue('davky_dni'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Reads the queue list the way the operator types it.
   *
   * @return array{0: array<string, string>, 1: TranslatableMarkup|null}
   */
  private function parseQueues(string $text): array {
    $queues = [];
    foreach (preg_split('/\R/', trim($text)) ?: [] as $number => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) !== 2 || preg_match('/^\d+$/', $parts[0]) !== 1) {
        return [[], $this->t('Řádek @cislo: čeká se číslo fronty, svislá čára a režim, například 5003|VSE.', ['@cislo' => $number + 1])];
      }
      if (QueueMode::fromLabel(strtoupper($parts[1])) === NULL) {
        return [[], $this->t('Řádek @cislo: režim je VSE, nebo LOGIKA.', ['@cislo' => $number + 1])];
      }
      if (isset($queues[$parts[0]])) {
        return [[], $this->t('Fronta @fronta je v seznamu dvakrát.', ['@fronta' => $parts[0]])];
      }
      $queues[$parts[0]] = strtoupper($parts[1]);
    }
    return [$queues, NULL];
  }


}
