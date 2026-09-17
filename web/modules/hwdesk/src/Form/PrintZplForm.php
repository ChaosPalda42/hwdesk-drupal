<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\hwdesk\Controller\LabelController;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Service\ZplLabel;
use Drupal\hwdesk\Service\ZplPrinter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Send the selected labels straight to the Zebra printer (ZPL over 9100).
 */
final class PrintZplForm extends FormBase {

  public function __construct(
    private readonly ZplLabel $zpl,
    private readonly ZplPrinter $printer,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.zpl_label'), $container->get('hwdesk.zpl_printer'), $container->get('tempstore.private'));
  }

  public function getFormId(): string {
    return 'hwdesk_print_zpl';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $ids = LabelController::idsFromRequest($this->getRequest(), $this->tempStoreFactory);
    $assets = Asset::loadMultiple($ids);
    $host = (string) $this->config('hwdesk.settings')->get('label_printer_host');
    $form_state->set('ids', array_keys($assets));
    $form['info'] = ['#markup' => '<p>' . $this->t('@count štítků na tiskárnu @host.', ['@count' => count($assets), '@host' => $host !== '' ? $host : $this->t('(není nastavena)')]) . '</p>'];
    $form['copies'] = ['#type' => 'number', '#title' => $this->t('Kopií od každého'), '#min' => 1, '#max' => 20, '#default_value' => 1];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Tisknout'), '#button_type' => 'primary', '#disabled' => $host === '' || !$assets];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $base = rtrim((string) $this->config('hwdesk.settings')->get('base_url'), '/');
    if ($base === '') {
      $base = rtrim($this->getRequest()->getSchemeAndHttpHost() . $this->getRequest()->getBasePath(), '/');
    }
    $copies = (int) $form_state->getValue('copies');
    $jobs = [];
    foreach (Asset::loadMultiple((array) $form_state->get('ids')) as $asset) {
      $jobs[] = $this->zpl->build($asset->getTag(), $asset->getDisplayName(), $base . '/a/' . $asset->getTag(), $copies);
    }
    try {
      $this->printer->send(implode('', $jobs));
      $this->messenger()->addStatus($this->formatPlural(count($jobs), 'Odesláno na tiskárnu: 1 štítek.', 'Odesláno na tiskárnu: @count štítků.'));
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
