<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Service\TagGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add/edit form: an empty inventory tag is issued from the type's prefix,
 * a manual one is reserved so the counter never collides with it.
 */
final class AssetForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected readonly TagGenerator $tagGenerator,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('hwdesk.tag_generator'),
    );
  }

  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::validateForm($form, $form_state);
    $tag = strtoupper(trim((string) $form_state->getValue(['tag', 0, 'value'])));
    if ($tag !== '' && !$this->tagGenerator->isValid($tag)) {
      $form_state->setErrorByName('tag', $this->t('Inventární číslo má tvar PREFIX-ČÍSLO, např. NB-0042.'));
    }
    return $entity;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $asset = $this->getEntity();
    assert($asset instanceof Asset);
    $tag = strtoupper(trim($asset->getTag()));
    if ($tag === '') {
      $asset->set('tag', $this->tagGenerator->next($asset->getType()));
    }
    else {
      $this->tagGenerator->reserve($tag);
    }
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Zařízení %tag bylo založeno.', ['%tag' => $asset->getTag()])
      : $this->t('Zařízení %tag bylo uloženo.', ['%tag' => $asset->getTag()]));
    $form_state->setRedirectUrl($asset->toUrl('canonical'));
    return $result;
  }

}
