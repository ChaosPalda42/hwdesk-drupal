<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * One hwdesk_audit row per insert/update/delete of any hwdesk entity,
 * with the fields that changed.
 */
final class AuditLogger {

  private const IGNORED_FIELDS = ['changed', 'revision_id', 'revision_created', 'revision_user', 'revision_log', 'revision_default', 'revision_translation_affected'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function record(EntityInterface $entity, string $action, ?string $message = NULL): void {
    if ($message === NULL) {
      $message = $action === 'update' && $entity instanceof ContentEntityInterface
        ? $this->describeChanges($entity)
        : $action;
      if ($message === '') {
        return;
      }
    }
    $this->entityTypeManager->getStorage('hwdesk_audit')->create([
      'uid' => $this->currentUser->id(),
      'target_type' => $entity->getEntityTypeId(),
      'target_id' => (int) $entity->id(),
      'target_label' => mb_substr((string) $entity->label(), 0, 255),
      'action' => $action,
      'message' => mb_substr($message, 0, 1000),
    ])->save();
  }

  /**
   * "status: in_stock → assigned; holder: – → Jana Nováková"; empty = nothing changed.
   */
  public function describeChanges(ContentEntityInterface $entity): string {
    $original = $entity->getOriginal();
    if (!$original instanceof ContentEntityInterface) {
      return 'update';
    }
    $parts = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (in_array($name, self::IGNORED_FIELDS, TRUE) || $definition->isComputed()) {
        continue;
      }
      $before = $this->render($original->get($name)->getValue());
      $after = $this->render($entity->get($name)->getValue());
      if ($before !== $after) {
        $parts[] = sprintf('%s: %s → %s', $name, $before === '' ? '–' : $before, $after === '' ? '–' : $after);
      }
    }
    return implode('; ', $parts);
  }

  /**
   * @param array<int, array<string, mixed>> $items
   */
  private function render(array $items): string {
    $values = [];
    foreach ($items as $item) {
      $value = $item['value'] ?? $item['target_id'] ?? NULL;
      if ($value !== NULL && $value !== '') {
        $values[] = is_scalar($value) ? (string) $value : json_encode($value);
      }
    }
    return implode(', ', $values);
  }

}
