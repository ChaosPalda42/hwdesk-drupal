<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings → E-mail (SMTP): who the mails come from and how they are sent.
 *
 * The password is never stored here. It lives in a Key entity, so it stays out
 * of the config export and out of settings.php.
 */
final class MailSettingsForm extends ConfigFormBase {

  private const SECURITY = [
    'none' => 'Bez šifrování',
    'tls' => 'STARTTLS (obvykle port 587)',
    'ssl' => 'SSL/TLS (obvykle port 465)',
  ];

  /**
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  private $mailManager;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->mailManager = $container->get('plugin.manager.mail');
    return $instance;
  }

  public function getFormId(): string {
    return 'hwdesk_mail_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['hwdesk.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('hwdesk.settings');

    $form['sender'] = [
      '#type' => 'details',
      '#title' => $this->t('Odesílatel'),
      '#open' => TRUE,
    ];
    $form['sender']['mail_from'] = [
      '#type' => 'email',
      '#title' => $this->t('Adresa odesílatele'),
      '#description' => $this->t('Z téhle adresy chodí výzvy k převzetí i protokoly, například asistentka@firma.cz. Prázdné = adresa webu.'),
      '#default_value' => $config->get('mail_from'),
    ];
    $form['sender']['mail_from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Jméno odesílatele'),
      '#description' => $this->t('Co uvidí příjemce před adresou, například „HW Desk“.'),
      '#default_value' => $config->get('mail_from_name'),
    ];
    $form['sender']['mail_reply_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Odpovědi směrovat na'),
      '#description' => $this->t('Prázdné = odpovědi jdou na adresu odesílatele.'),
      '#default_value' => $config->get('mail_reply_to'),
    ];

    $form['smtp'] = [
      '#type' => 'details',
      '#title' => $this->t('Odesílání přes SMTP'),
      '#open' => TRUE,
    ];
    $form['smtp']['smtp_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Posílat pošta HW Desku přes vlastní SMTP server'),
      '#description' => $this->t('Vypnuté = pošta jde tak, jak ji posílá zbytek webu.'),
      '#default_value' => (bool) $config->get('smtp_enabled'),
    ];
    $visible = ['visible' => [':input[name="smtp_enabled"]' => ['checked' => TRUE]]];
    $form['smtp']['smtp_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server'),
      '#default_value' => $config->get('smtp_host'),
      '#states' => $visible,
    ];
    $form['smtp']['smtp_port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#min' => 1,
      '#max' => 65535,
      '#default_value' => $config->get('smtp_port'),
      '#states' => $visible,
    ];
    $form['smtp']['smtp_security'] = [
      '#type' => 'select',
      '#title' => $this->t('Šifrování'),
      '#options' => array_map(fn (string $label): string => (string) $this->t($label), self::SECURITY),
      '#default_value' => $config->get('smtp_security') ?: 'tls',
      '#states' => $visible,
    ];
    $form['smtp']['smtp_user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Přihlašovací jméno'),
      '#description' => $this->t('Prázdné = server nic nevyžaduje.'),
      '#default_value' => $config->get('smtp_user'),
      '#states' => $visible,
    ];
    $form['smtp']['smtp_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Heslo'),
      '#key_description' => FALSE,
      '#key_filters' => ['type' => 'authentication'],
      '#empty_option' => $this->t('– bez hesla –'),
      '#description' => $this->t('Heslo se tady nezadává ani neukládá. Vybere se <a href="@url">klíč</a> typu „Authentication“, který heslo drží mimo konfiguraci (soubor nebo proměnná prostředí).', [
        '@url' => Url::fromRoute('entity.key.collection')->toString(),
      ]),
      '#default_value' => $config->get('smtp_key'),
      '#states' => $visible,
    ];

    // Zkouška je pod tlačítkem „Uložit nastavení“ schválně, ze dvou důvodů:
    // posílá se tím, co je uložené, a Enter v políčku spouští první tlačítko
    // ve formuláři — má to být uložení, ne odeslání zkušební pošty.
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Zkouška'),
      // Víc než 100, což je váha řádku s tlačítky (render element 'actions').
      '#weight' => 110,
      '#description' => $this->t('Pošle zkušební e-mail na @mail s právě uloženým nastavením. Když se odeslání nepovede, řekne to — konkrétní důvod i místo v kódu najdete v <a href="@log">záznamu chyb webu</a> (kanál hwdesk).', [
        '@mail' => $this->currentUser()->getEmail() ?: '(váš účet nemá e-mail)',
        '@log' => '/admin/reports/dblog',
      ]),
    ];
    $form['test']['send'] = [
      '#type' => 'submit',
      '#value' => $this->t('Poslat zkušební e-mail'),
      '#submit' => ['::sendTest'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ((bool) $form_state->getValue('smtp_enabled') && trim((string) $form_state->getValue('smtp_host')) === '') {
      $form_state->setErrorByName('smtp_host', $this->t('Bez adresy serveru nelze přes SMTP posílat.'));
    }
    if (trim((string) $form_state->getValue('smtp_user')) !== '' && (string) $form_state->getValue('smtp_key') === '') {
      $form_state->setErrorByName('smtp_key', $this->t('Je zadané přihlašovací jméno, ale žádný klíč s heslem.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('hwdesk.settings')
      ->set('mail_from', strtolower(trim((string) $form_state->getValue('mail_from'))))
      ->set('mail_from_name', trim((string) $form_state->getValue('mail_from_name')))
      ->set('mail_reply_to', strtolower(trim((string) $form_state->getValue('mail_reply_to'))))
      ->set('smtp_enabled', (bool) $form_state->getValue('smtp_enabled'))
      ->set('smtp_host', trim((string) $form_state->getValue('smtp_host')))
      ->set('smtp_port', (int) $form_state->getValue('smtp_port'))
      ->set('smtp_security', (string) $form_state->getValue('smtp_security'))
      ->set('smtp_user', trim((string) $form_state->getValue('smtp_user')))
      ->set('smtp_key', (string) $form_state->getValue('smtp_key'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Sends a test message and says plainly what happened.
   */
  public function sendTest(array &$form, FormStateInterface $form_state): void {
    $to = (string) $this->currentUser()->getEmail();
    if ($to === '') {
      $this->messenger()->addError($this->t('Váš účet nemá e-mailovou adresu, není kam zkušební zprávu poslat.'));
      return;
    }
    try {
      $result = $this->mailManager->mail('hwdesk', 'test', $to, 'cs', [
        'subject' => (string) $this->t('HW Desk: zkušební zpráva'),
        'body' => [(string) $this->t('Tenhle e-mail potvrzuje, že odesílání z HW Desku funguje.')],
      ]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Odeslání se nepovedlo: @reason (@where)', [
        '@reason' => $e->getMessage(),
        '@where' => $e->getFile() . ':' . $e->getLine(),
      ]));
      return;
    }
    if (empty($result['result'])) {
      $this->messenger()->addError($this->t('Odeslání se nepovedlo. Konkrétní důvod zapsal HW Desk do záznamu chyb webu (kanál hwdesk).'));
      return;
    }
    $this->messenger()->addStatus($this->t('Zkušební zpráva odešla na @mail.', ['@mail' => $to]));
  }

}
