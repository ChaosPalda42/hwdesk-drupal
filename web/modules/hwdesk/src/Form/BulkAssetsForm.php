<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lišta nad seznamem: co se má stát s vybranými zařízeními.
 *
 * Zaškrtnutá čísla posílá JavaScript do skrytého pole; bez něj lišta zůstane
 * schovaná a seznam funguje dál.
 */
final class BulkAssetsForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'hwdesk_bulk_assets';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'bulkbar';
    $form['#attributes']['id'] = 'bulkbar';

    $form['ids'] = ['#type' => 'hidden', '#default_value' => ''];
    $form['count'] = [
      '#type' => 'inline_template',
      '#template' => '<span><b id="bulkcount">0</b> {{ "vybráno"|t }}</span>',
      '#weight' => 0,
    ];
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Akce'),
      '#title_display' => 'invisible',
      '#weight' => 1,
      '#options' => [
        (string) $this->t('Stav') => [
          'status:in_stock' => $this->t('Vrátit na sklad'),
          'status:retired' => $this->t('Vyřadit'),
          'status:lost' => $this->t('Označit jako ztracené'),
        ],
        (string) $this->t('Evidence') => [
          'tag' => $this->t('Přidat štítek'),
          'untag' => $this->t('Odebrat štítek'),
          'location' => $this->t('Nastavit lokalitu'),
          'invoice' => $this->t('Přiřadit fakturu'),
          'cost_center' => $this->t('Nastavit nákladové středisko'),
        ],
        (string) $this->t('Tisk a export') => [
          'labels' => $this->t('Štítky k tisku'),
          'zpl' => $this->t('Tisk na Zebru'),
          'export' => $this->t('Export CSV'),
        ],
      ],
    ];
    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hodnota'),
      '#title_display' => 'invisible',
      '#size' => 28,
      '#weight' => 2,
      '#placeholder' => $this->t('název štítku / lokalita / číslo faktury'),
    ];
    $form['actions'] = ['#type' => 'actions', '#weight' => 3];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Provést'),
      '#attributes' => ['class' => ['btn', 'sm']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->ids($form_state) === []) {
      $form_state->setErrorByName('ids', $this->t('Není vybrané žádné zařízení.'));
      return;
    }
    $action = (string) $form_state->getValue('action');
    $value = trim((string) $form_state->getValue('value'));
    if (in_array($action, ['tag', 'untag', 'location', 'invoice', 'cost_center'], TRUE) && $value === '') {
      $form_state->setErrorByName('value', $this->t('Tahle akce potřebuje hodnotu: @what.', [
        '@what' => match ($action) {
          'tag', 'untag' => $this->t('název štítku'),
          'location' => $this->t('název lokality'),
          'invoice' => $this->t('číslo faktury'),
          default => $this->t('nákladové středisko'),
        },
      ]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = $this->ids($form_state);
    $action = (string) $form_state->getValue('action');
    $value = trim((string) $form_state->getValue('value'));

    if (in_array($action, ['labels', 'zpl', 'export'], TRUE)) {
      $route = match ($action) {
        'labels' => 'hwdesk.labels',
        'zpl' => 'hwdesk.labels.zpl',
        default => 'hwdesk.export',
      };
      $form_state->setRedirect($route, [], ['query' => ['ids' => implode(',', $ids)]]);
      return;
    }

    $assets = $this->entityTypeManager->getStorage('hwdesk_asset')->loadMultiple($ids);
    $done = 0;
    $skipped = [];
    foreach ($assets as $asset) {
      if (!$asset instanceof Asset) {
        continue;
      }
      $problem = $this->apply($asset, $action, $value);
      if ($problem === NULL) {
        $asset->setNewRevision(TRUE);
        $asset->setRevisionLogMessage((string) $this->t('Hromadná úprava: @action', ['@action' => $action]));
        $asset->save();
        $done++;
      }
      else {
        $skipped[] = $asset->label() . ' (' . $problem . ')';
      }
    }

    $this->messenger()->addStatus($this->t('Upraveno @count zařízení.', ['@count' => $done]));
    if ($skipped !== []) {
      $this->messenger()->addWarning($this->t('Vynecháno: @list', ['@list' => implode(', ', $skipped)]));
    }
    $form_state->setRedirect('hwdesk.assets');
  }

  /**
   * Provede akci na jednom zařízení. Vrací důvod, proč se nedala provést.
   */
  private function apply(Asset $asset, string $action, string $value): ?string {
    if (str_starts_with($action, 'status:')) {
      $to = AssetStatus::tryFrom(substr($action, 7));
      $from = AssetStatus::tryFrom((string) $asset->get('status')->value);
      if ($to === NULL || $from === NULL) {
        return (string) $this->t('neznámý stav');
      }
      if ($from === $to) {
        return (string) $this->t('už v tom stavu je');
      }
      if (!$from->canTransitionTo($to)) {
        return (string) $this->t('ze stavu @from to nejde', ['@from' => $from->label()]);
      }
      $asset->set('status', $to->value);
      if ($to === AssetStatus::InStock) {
        $asset->set('holder', NULL);
      }
      return NULL;
    }

    switch ($action) {
      case 'tag':
        $tag = $this->findByName('hwdesk_tag', $value);
        if ($tag === NULL) {
          return (string) $this->t('štítek @name neexistuje', ['@name' => $value]);
        }
        $current = array_map(static fn($t): int => (int) $t->id(), $asset->get('tags')->referencedEntities());
        if (!in_array((int) $tag, $current, TRUE)) {
          $current[] = (int) $tag;
          $asset->set('tags', $current);
        }
        return NULL;

      case 'untag':
        $tag = $this->findByName('hwdesk_tag', $value);
        if ($tag === NULL) {
          return (string) $this->t('štítek @name neexistuje', ['@name' => $value]);
        }
        $kept = array_values(array_filter(
          array_map(static fn($t): int => (int) $t->id(), $asset->get('tags')->referencedEntities()),
          static fn(int $id): bool => $id !== (int) $tag,
        ));
        $asset->set('tags', $kept);
        return NULL;

      case 'location':
        $location = $this->findByName('hwdesk_location', $value);
        if ($location === NULL) {
          return (string) $this->t('lokalita @name neexistuje', ['@name' => $value]);
        }
        $asset->set('location', $location);
        return NULL;

      case 'invoice':
        $invoice = $this->findByField('hwdesk_invoice', 'number', $value);
        if ($invoice === NULL) {
          return (string) $this->t('faktura @name neexistuje', ['@name' => $value]);
        }
        $asset->set('invoice', $invoice);
        return NULL;

      case 'cost_center':
        $asset->set('cost_center', $value);
        return NULL;
    }
    return (string) $this->t('neznámá akce');
  }

  private function findByName(string $entity_type, string $value): ?int {
    return $this->findByField($entity_type, 'name', $value);
  }

  private function findByField(string $entity_type, string $field, string $value): ?int {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition($field, $value)->range(0, 1)->execute();
    if ($ids === []) {
      // Číslo jako hodnota: ber ho jako identifikátor.
      return ctype_digit($value) && $storage->load((int) $value) !== NULL ? (int) $value : NULL;
    }
    return (int) reset($ids);
  }

  /**
   * @return list<int>
   */
  private function ids(FormStateInterface $form_state): array {
    $raw = (string) $form_state->getValue('ids');
    $ids = array_filter(array_map('intval', array_filter(explode(',', $raw), static fn($v): bool => trim((string) $v) !== '')));
    return array_values(array_unique($ids));
  }

}
