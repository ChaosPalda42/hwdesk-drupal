<?php

declare(strict_types=1);

namespace Drupal\hwdesk;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\hwdesk\Entity\Location;

final class LocationListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    return ['name' => $this->t('Název'), 'note' => $this->t('Poznámka')] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof Location);
    return ['name' => $entity->getName(), 'note' => (string) $entity->get('note')->value] + parent::buildRow($entity);
  }

}
