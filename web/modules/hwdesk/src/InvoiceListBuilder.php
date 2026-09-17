<?php

declare(strict_types=1);

namespace Drupal\hwdesk;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\hwdesk\Entity\Invoice;

final class InvoiceListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    return [
      'number' => $this->t('Číslo'),
      'supplier' => $this->t('Dodavatel'),
      'issued_on' => $this->t('Vystaveno'),
      'total' => $this->t('Celkem'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof Invoice);
    $total = $entity->get('total')->value;
    return [
      'number' => Link::createFromRoute($entity->getNumber(), 'entity.hwdesk_invoice.canonical', ['hwdesk_invoice' => $entity->id()]),
      'supplier' => $entity->getSupplier(),
      'issued_on' => (string) $entity->get('issued_on')->value,
      'total' => $total === NULL ? '' : number_format((float) $total, 2, ',', ' ') . ' ' . $entity->get('currency')->value,
    ] + parent::buildRow($entity);
  }

}
