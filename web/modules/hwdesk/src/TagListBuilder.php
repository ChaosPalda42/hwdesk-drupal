<?php

declare(strict_types=1);

namespace Drupal\hwdesk;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\hwdesk\Entity\Tag;

final class TagListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    return ['name' => $this->t('Název'), 'color' => $this->t('Barva')] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof Tag);
    return [
      'name' => $entity->getName(),
      'color' => [
        'data' => [
          '#type' => 'inline_template',
          '#template' => '<span style="display:inline-block;width:1em;height:1em;border-radius:50%;background:{{ color }};vertical-align:middle"></span> {{ color }}',
          '#context' => ['color' => $entity->getColor()],
        ],
      ],
    ] + parent::buildRow($entity);
  }

}
