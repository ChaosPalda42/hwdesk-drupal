<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\hwdesk\Service\AssetCsv;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Upload a CSV in the export format; rows are upserted by inventory tag.
 */
final class ImportForm extends FormBase {

  public function __construct(protected readonly AssetCsv $csv) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.asset_csv'));
  }

  public function getFormId(): string {
    return 'hwdesk_import';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Sloupce (oddělené středníkem): @cols. Řádek s existujícím inventárním číslem se aktualizuje, prázdné číslo se přidělí. Vzor: <a href=":url">export</a>.', ['@cols' => implode('; ', AssetCsv::COLUMNS), ':url' => Url::fromRoute('hwdesk.export')->toString()]) . '</p>',
    ];
    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('CSV soubor'),
      '#attributes' => ['accept' => '.csv,text/csv'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Importovat'), '#button_type' => 'primary'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $files = $this->getRequest()->files->get('files', []);
    $upload = is_array($files) ? ($files['file'] ?? NULL) : NULL;
    if ($upload === NULL || !$upload->isValid()) {
      $form_state->setErrorByName('file', $this->t('Vyberte CSV soubor.'));
      return;
    }
    $form_state->set('csv', (string) file_get_contents($upload->getPathname()));
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $report = $this->csv->import((string) $form_state->get('csv'));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $this->messenger()->addStatus($this->t('Založeno @c, aktualizováno @u.', ['@c' => $report['created'], '@u' => $report['updated']]));
    foreach ($report['errors'] as $error) {
      $this->messenger()->addWarning($error);
    }
    $form_state->setRedirect('view.hwdesk_assets.page');
  }

}
