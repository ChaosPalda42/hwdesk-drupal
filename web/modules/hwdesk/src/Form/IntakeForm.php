<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hwdesk\AssetCondition;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Location;
use Drupal\hwdesk\Entity\Tag;
use Drupal\hwdesk\Service\IntakeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Receiving goods: one form → N assets → serial numbers → labels.
 */
final class IntakeForm extends FormBase {

  public function __construct(protected readonly IntakeService $intake) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('hwdesk.intake'));
  }

  public function getFormId(): string {
    return 'hwdesk_intake';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $types = (array) $this->config('hwdesk.settings')->get('asset_types');
    $form['type'] = ['#type' => 'select', '#title' => $this->t('Typ'), '#options' => $types, '#required' => TRUE];
    $form['manufacturer'] = ['#type' => 'textfield', '#title' => $this->t('Výrobce'), '#maxlength' => 128];
    $form['model'] = ['#type' => 'textfield', '#title' => $this->t('Model'), '#required' => TRUE, '#maxlength' => 255];
    $form['count'] = ['#type' => 'number', '#title' => $this->t('Počet kusů'), '#min' => 1, '#max' => 500, '#default_value' => 1, '#required' => TRUE];
    $form['condition'] = ['#type' => 'select', '#title' => $this->t('Stav kusu'), '#options' => AssetCondition::options(), '#default_value' => AssetCondition::NewItem->value];
    $form['invoice'] = ['#type' => 'entity_autocomplete', '#target_type' => 'hwdesk_invoice', '#title' => $this->t('Faktura')];
    $locations = [];
    foreach (Location::loadMultiple() as $location) {
      $locations[$location->id()] = $location->getName();
    }
    $form['location'] = ['#type' => 'select', '#title' => $this->t('Lokalita'), '#options' => $locations, '#empty_option' => $this->t('– bez lokality –')];
    $tags = [];
    foreach (Tag::loadMultiple() as $tag) {
      $tags[$tag->id()] = $tag->getName();
    }
    $form['tags'] = ['#type' => 'checkboxes', '#title' => $this->t('Štítky'), '#options' => $tags];
    $form['price'] = ['#type' => 'number', '#title' => $this->t('Pořizovací cena za kus'), '#step' => '0.01', '#min' => 0];
    $form['warranty_until'] = ['#type' => 'date', '#title' => $this->t('Záruka do')];
    $form['cost_center'] = ['#type' => 'textfield', '#title' => $this->t('Nákladové středisko'), '#maxlength' => 64];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Založit a načíst sériová čísla'), '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = [
      'type' => (string) $form_state->getValue('type'),
      'manufacturer' => trim((string) $form_state->getValue('manufacturer')),
      'model' => trim((string) $form_state->getValue('model')),
      'condition' => (string) $form_state->getValue('condition'),
      'invoice' => $form_state->getValue('invoice') ? (int) $form_state->getValue('invoice') : NULL,
      'location' => $form_state->getValue('location') ? (int) $form_state->getValue('location') : NULL,
      'tags' => array_values(array_map('intval', array_filter((array) $form_state->getValue('tags')))),
      'price' => $form_state->getValue('price') !== '' && $form_state->getValue('price') !== NULL ? (string) $form_state->getValue('price') : NULL,
      'warranty_until' => $form_state->getValue('warranty_until') ?: NULL,
      'cost_center' => trim((string) $form_state->getValue('cost_center')),
    ];
    try {
      $assets = $this->intake->createBatch($values, (int) $form_state->getValue('count'));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $ids = array_map(static fn(Asset $a): int => (int) $a->id(), $assets);
    $this->messenger()->addStatus($this->formatPlural(count($assets), 'Založeno 1 zařízení (@first).', 'Založeno @count zařízení (@first – @last).', ['@first' => $assets[0]->getTag(), '@last' => end($assets)->getTag()]));
    $form_state->setRedirect('hwdesk.intake.serials', [], ['query' => ['ids' => implode(',', $ids)]]);
  }

}
