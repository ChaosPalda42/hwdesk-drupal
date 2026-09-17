<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\Service\HandoverService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Start a handover (asset in stock → pick the employee) or a return
 * (assigned asset → the holder). The employee confirms from the e-mail.
 */
final class RequestHandoverForm extends FormBase {

  public function __construct(
    protected readonly HandoverService $handovers,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.handover'), $container->get('date.formatter'));
  }

  public function getFormId(): string {
    return 'hwdesk_request_handover';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?Asset $hwdesk_asset = NULL): array {
    if ($hwdesk_asset === NULL) {
      return $form;
    }
    $form_state->set('asset_id', $hwdesk_asset->id());
    $open = $this->handovers->openFor($hwdesk_asset);
    $form['summary'] = [
      '#markup' => '<p><strong>' . $hwdesk_asset->getTag() . '</strong> · ' . $hwdesk_asset->getDisplayName() . ' · ' . $hwdesk_asset->getStatus()->label() . '</p>',
    ];
    if ($open !== NULL) {
      $form['open'] = ['#markup' => '<p>' . $this->t('Čeká na potvrzení (@kind, do @until). Zrušit lze v seznamu předání.', ['@kind' => $open->getKind()->label(), '@until' => $this->dateFormatter->format($open->getExpires(), 'custom', 'j. n. Y H:i')]) . '</p>'];
      return $form;
    }
    $status = $hwdesk_asset->getStatus();
    if ($status === AssetStatus::InStock) {
      $form_state->set('kind', HandoverKind::Handover->value);
      $form['user'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'user',
        '#title' => $this->t('Předat zaměstnanci'),
        '#required' => TRUE,
        '#selection_settings' => ['include_anonymous' => FALSE],
      ];
      $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Odeslat výzvu k převzetí')];
    }
    elseif ($status === AssetStatus::Assigned && $hwdesk_asset->getHolder() !== NULL) {
      $form_state->set('kind', HandoverKind::ReturnItem->value);
      $form_state->set('user_id', $hwdesk_asset->getHolder()->id());
      $form['holder'] = ['#markup' => '<p>' . $this->t('Držitel: @name', ['@name' => $hwdesk_asset->getHolder()->getDisplayName()]) . '</p>'];
      $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Odeslat výzvu k vrácení')];
    }
    else {
      $form['none'] = ['#markup' => '<p>' . $this->t('V tomto stavu nelze zařízení předat ani vrátit.') . '</p>'];
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $asset = Asset::load($form_state->get('asset_id'));
    $uid = $form_state->get('user_id') ?? $form_state->getValue('user');
    $user = $uid ? User::load($uid) : NULL;
    if (!$asset instanceof Asset || $user === NULL) {
      $this->messenger()->addError($this->t('Zařízení nebo zaměstnanec nebyl nalezen.'));
      return;
    }
    try {
      $handover = $this->handovers->request($asset, $user, HandoverKind::from((string) $form_state->get('kind')), $this->currentUser());
      $this->messenger()->addStatus($this->t('Výzva odeslána na @mail, platí do @until.', ['@mail' => $user->getEmail(), '@until' => $this->dateFormatter->format($handover->getExpires(), 'custom', 'j. n. Y H:i')]));
    }
    catch (\DomainException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('entity.hwdesk_asset.canonical', ['hwdesk_asset' => $asset->id()]);
  }

}
