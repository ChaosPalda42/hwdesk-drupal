<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverStatus;

/**
 * Single-use confirmation links; only the checksum is stored.
 */
final class HandoverTokens {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Issues a fresh link for a handover.
   *
   * The returned token is the only copy of the secret; the database keeps
   * only its sha256 checksum. Issuing again retires the previous link.
   *
   * @param int $handoverId
   *   The handover to issue the link for.
   * @param int $handoverId
   *   The handover to issue the link for.
   * @param int $_uid
   *   Legacy argument, ignored; the employee is read from the handover.
   * @param int $_expires
   *   Legacy argument, ignored; the expiry is read from the handover.
   *
   * @throws \InvalidArgumentException
   *   When the handover does not exist.
   */
  public function issue(int $handoverId, int $_uid, int $_expires): string {
    $storage = $this->entityTypeManager->getStorage('hwdesk_handover');
    $handover = $storage->load($handoverId);
    if (!$handover instanceof Handover) {
      throw new \InvalidArgumentException(sprintf('Handover %d does not exist.', $handoverId));
    }

    $raw = random_bytes(32);
    $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $handover->set('token_hash', hash('sha256', $token));
    $handover->save();
    return $token;
  }

  /**
   * Verifies a link and returns the handover it belongs to, or NULL.
   *
   * @return array{handover: int, uid: int, expires: int}|NULL
   *   The stored handover id, the employee uid and the expiry timestamp,
   *   or NULL when the token is malformed, unknown, not pending or expired.
   */
  public function verify(string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
      return NULL;
    }
    $handover = $this->findByToken($token);
    if ($handover === NULL) {
      return NULL;
    }
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      return NULL;
    }
    if ($handover->getExpires() < $this->time->getRequestTime()) {
      return NULL;
    }
    return [
      'handover' => (int) $handover->id(),
      'uid' => (int) $handover->getUser()->id(),
      'expires' => $handover->getExpires(),
    ];
  }

  /**
   * Consumes a link: the stored checksum is cleared, so it works only once.
   *
   * Unknown, malformed or empty tokens are ignored in silence; this runs
   * while a page is being answered and must never throw.
   */
  public function consume(string $token): void {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
      return;
    }
    $handover = $this->findByToken($token);
    if ($handover === NULL) {
      return;
    }
    $handover->set('token_hash', '');
    $handover->save();
  }

  /**
   * Finds the handover that stores the checksum of the given token.
   */
  private function findByToken(string $token): ?Handover {
    $hash = hash('sha256', $token);
    $ids = $this->entityTypeManager->getStorage('hwdesk_handover')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('token_hash', $hash)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $handover = $this->entityTypeManager->getStorage('hwdesk_handover')->load(reset($ids));
    return $handover instanceof Handover ? $handover : NULL;
  }

}
