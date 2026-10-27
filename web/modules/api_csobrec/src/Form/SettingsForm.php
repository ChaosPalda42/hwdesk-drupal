<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\api_csobrec\QueueMode;
use Drupal\api_csobrec\Service\CredentialsInterface;

/**
 * Queues, the hour of the morning run and the access to ČSOB's SFTP.
 *
 * Addresses, the login name and both secrets are deliberately missing from
 * this form: config is exported to yml and travels between machines, so all
 * of it lives in one key of the Key module and this form only points at that
 * key. What it says about the key is which FIELDS are missing from it, never
 * what is in them.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    // Protected, not private: formuláře se serializují do cache a
    // DependencySerializationTrait v FormBase private vlastnosti neobnoví.
    protected readonly CredentialsInterface $credentials,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  public function getFormId(): string {
    return 'api_csobrec_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['api_csobrec.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('api_csobrec.settings');

    $lines = [];
    foreach ((array) $config->get('fronty') as $queue => $mode) {
      $lines[] = $queue . '|' . $mode;
    }

    $missing = $this->credentials->missing();
    $form['pristupy'] = [
      '#type' => 'details',
      '#title' => $this->t('Přístupy'),
      '#open' => TRUE,
    ];
    $form['pristupy']['klic'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Klíč s přístupy'),
      '#key_filters' => ['type' => 'authentication_multivalue'],
      '#key_description' => FALSE,
      '#empty_option' => $this->t('– žádný –'),
      '#default_value' => $config->get('klic'),
      '#description' => $this->t('Jeden klíč modulu Key typu „Authentication (Multivalue)“ s poli <code>daktela_url</code>, <code>daktela_token</code>, <code>sftp_host</code>, <code>sftp_port</code>, <code>sftp_uzivatel</code> a <code>sftp_heslo</code> nebo <code>sftp_klic</code> (privátní klíč v PEM). Zakládejte ho s poskytovatelem <em>File</em> nebo <em>Environment</em>, <strong>ne <em>Configuration</em></strong>: config se exportuje do yml a cestuje po discích. Hodnoty se tady nikdy nezobrazí.'),
    ];
    $form['pristupy']['stav'] = [
      '#type' => 'item',
      '#title' => $this->t('Co v klíči chybí'),
      '#markup' => $missing === []
        ? $this->t('Nic: klíč má všechno, co předávka potřebuje.')
        : $this->t('Chybí: @pole.', ['@pole' => implode(', ', $missing)]),
    ];

    $form['daktela'] = [
      '#type' => 'details',
      '#title' => $this->t('Daktela'),
      '#open' => TRUE,
    ];
    $form['daktela']['fronty'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fronty a jejich režim'),
      '#description' => $this->t('Jedna fronta na řádek: číslo|režim. VSE = předá se každý hovor fronty. LOGIKA = jen hovor s dispozicí „[CSOB] Nový prodej“ nebo „[CSOB] Nový prodej TZ“ a k němu starší historie téhož telefonního čísla z téže fronty. Fronta, která tu není, se Daktely vůbec neptá.'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 8,
    ];
    $form['sftp'] = [
      '#type' => 'details',
      '#title' => $this->t('SFTP ČSOB'),
      '#open' => TRUE,
      '#description' => $this->t('Adresa, port a přihlašovací jméno jsou v klíči výš. Tady je jen to, co určuje ČSOB.'),
    ];
    $form['sftp']['sftp_cil'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cílová cesta na jejich serveru'),
      '#description' => $this->t('Například /upload/nahravky. Složku na jejich straně modul nezakládá: překlep by dávku poslal tam, kam se nikdo nekouká, a evidence by tvrdila, že ji ČSOB má. Když běh tu cestu jmenuje, je v ní překlep, nebo ji musí založit ČSOB.'),
      '#default_value' => $config->get('sftp_cil'),
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
      '#description' => $this->t('Jen pro přehled a pro příkaz <code>drush api-csobrec:vcera</code>; spouští to systémový cron, ne Drupal.'),
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
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('api_csobrec.settings')
      ->set('klic', trim((string) $form_state->getValue('klic')))
      ->set('fronty', $form_state->get('parsed_queues'))
      ->set('hodina', (int) $form_state->getValue('hodina'))
      ->set('sftp_cil', rtrim(trim((string) $form_state->getValue('sftp_cil')), '/'))
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
