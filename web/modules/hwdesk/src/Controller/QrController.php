<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hwdesk\Entity\Asset;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /a/<tag> from the QR on the label: admin → detail, holder → my devices.
 *
 * A tag that does not exist and a tag that belongs to somebody else (or to
 * nobody) must be indistinguishable from outside, so both answers are the
 * same 404 — a 403 would leak which inventory numbers the company uses.
 */
final class QrController extends ControllerBase {

  public function go(string $tag): RedirectResponse {
    $ids = $this->entityTypeManager()->getStorage('hwdesk_asset')->getQuery()->accessCheck(FALSE)
      ->condition('tag', strtoupper($tag))->range(0, 1)->execute();
    $asset = $ids ? Asset::load(reset($ids)) : NULL;
    if (!$asset instanceof Asset) {
      throw new NotFoundHttpException();
    }
    if ($this->currentUser()->hasPermission('administer hwdesk')) {
      return $this->redirect('entity.hwdesk_asset.canonical', ['hwdesk_asset' => $asset->id()]);
    }
    $holder = $asset->getHolder();
    if ($holder !== NULL && (int) $holder->id() === (int) $this->currentUser()->id()) {
      return $this->redirect('hwdesk.my');
    }
    throw new NotFoundHttpException();
  }

}
