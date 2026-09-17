<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Service\IntakeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * One text field per new asset; a barcode scanner types the serial and Enter
 * moves on (the JS in hwdesk/ui). Then straight to the label sheet.
 */
final class SerialNumbersForm extends FormBase {

  public function __construct(protected readonly IntakeService $intake) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.intake'));
  }

  public function getFormId(): string {
    return 'hwdesk_serial_numbers';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?Request $request = NULL): array {
    $request ??= $this->getRequest();
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query->get('ids', '')))));
    $assets = Asset::loadMultiple($ids);
    if (!$assets) {
      $form['none'] = ['#markup' => '<p>' . $this->t('Žádná zařízení k doplnění.') . '</p>'];
      return $form;
    }
    $form_state->set('ids', $ids);
    $form['#attached']['library'][] = 'hwdesk/ui';
    $form['serials'] = ['#type' => 'table', '#header' => [$this->t('Inv. číslo'), $this->t('Zařízení'), $this->t('Sériové číslo')]];
    foreach ($assets as $asset) {
      $form['serials'][$asset->id()]['tag'] = ['#markup' => $asset->getTag()];
      $form['serials'][$asset->id()]['name'] = ['#markup' => $asset->getDisplayName()];
      $form['serials'][$asset->id()]['serial'] = [
        '#type' => 'textfield',
        '#default_value' => $asset->getSerialNumber(),
        '#maxlength' => 128,
        '#attributes' => ['class' => ['hwdesk-serial'], 'autocomplete' => 'off'],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Uložit a tisknout štítky'), '#button_type' => 'primary'];
    $form['actions']['skip'] = ['#type' => 'submit', '#value' => $this->t('Uložit bez štítků'), '#name' => 'skip'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $serials = [];
    foreach ((array) $form_state->getValue('serials') as $id => $row) {
      $serials[(int) $id] = (string) ($row['serial'] ?? '');
    }
    try {
      $this->intake->setSerialNumbers($serials);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return;
    }
    $ids = implode(',', (array) $form_state->get('ids'));
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'skip') {
      $form_state->setRedirect('view.hwdesk_assets.page');
      return;
    }
    $form_state->setRedirect('hwdesk.labels', [], ['query' => ['ids' => $ids]]);
  }

}
