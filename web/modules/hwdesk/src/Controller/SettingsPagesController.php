<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lokality a štítky — dvě stránky nastavení, které jsou seznamem entit.
 */
final class SettingsPagesController extends ControllerBase {

  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * @return array<string, mixed>
   */
  public function locations(): array {
    $rows = [];
    $storage = $this->entityTypeManager()->getStorage('hwdesk_location');
    foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->sort('name')->execute()) as $location) {
      $rows[] = [
        'id' => (int) $location->id(),
        'name' => (string) $location->label(),
        'note' => (string) $location->get('note')->value,
        'assets' => (int) $this->entityTypeManager()->getStorage('hwdesk_asset')->getQuery()
          ->accessCheck(FALSE)
          ->condition('location', $location->id())
          ->count()
          ->execute(),
        'edit_url' => $location->toUrl('edit-form')->toString(),
        'delete_url' => $location->toUrl('delete-form')->toString(),
        'assets_url' => Url::fromRoute('hwdesk.assets', [], ['query' => ['location' => $location->id()]])->toString(),
      ];
    }
    return [
      '#theme' => 'hwdesk_locations',
      '#rows' => $rows,
      '#total' => count($rows),
      '#links' => ['add' => Url::fromRoute('entity.hwdesk_location.add_form')->toString()],
      '#cache' => ['tags' => ['hwdesk_location_list', 'hwdesk_asset_list'], 'contexts' => ['user.permissions']],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function tags(): array {
    $rows = [];
    $storage = $this->entityTypeManager()->getStorage('hwdesk_tag');
    foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->sort('name')->execute()) as $tag) {
      $rows[] = [
        'id' => (int) $tag->id(),
        'name' => (string) $tag->label(),
        'color' => (string) ($tag->get('color')->value ?: 'gray'),
        'assets' => (int) $this->entityTypeManager()->getStorage('hwdesk_asset')->getQuery()
          ->accessCheck(FALSE)
          ->condition('tags', $tag->id())
          ->count()
          ->execute(),
        'edit_url' => $tag->toUrl('edit-form')->toString(),
        'delete_url' => $tag->toUrl('delete-form')->toString(),
        'assets_url' => Url::fromRoute('hwdesk.assets', [], ['query' => ['tag' => $tag->id()]])->toString(),
      ];
    }
    return [
      '#theme' => 'hwdesk_tags',
      '#rows' => $rows,
      '#total' => count($rows),
      '#links' => ['add' => Url::fromRoute('entity.hwdesk_tag.add_form')->toString()],
      '#cache' => ['tags' => ['hwdesk_tag_list', 'hwdesk_asset_list'], 'contexts' => ['user.permissions']],
    ];
  }

}
