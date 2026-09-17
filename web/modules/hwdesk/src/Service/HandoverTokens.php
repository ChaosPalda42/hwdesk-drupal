<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Component\Datetime\TimeInterface;

final class HandoverTokens {
  public function __construct(
    private readonly PrivateKey $privateKey,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Issues a signed token.
   */
  public function issue(int $handoverId, int $uid, int $expires): string {
    // Build payload string.
    $data = "$handoverId:$uid:$expires";
    // Base64 URL-safe without padding.
    $payload = rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    // Signature using HMAC base64 (URL-safe alphabet already).
    $signature = Crypt::hmacBase64($payload, $this->privateKey->get());
    return $payload . '.' . $signature;
  }

  /**
   * Verifies a token and returns its components or NULL.
   */
  public function verify(string $token): ?array {
    // Token must contain exactly one dot.
    if (substr_count($token, '.') !== 1) {
      return NULL;
    }
    [$payload, $signature] = explode('.', $token, 2);
    // Recalculate expected signature.
    $expected = Crypt::hmacBase64($payload, $this->privateKey->get());
    if (!hash_equals($expected, $signature)) {
      return NULL;
    }
    // Decode payload from base64url.
    $b64 = strtr($payload, '-_', '+/');
    // Add padding.
    $padLen = 4 - (strlen($b64) % 4);
    if ($padLen < 4) {
      $b64 .= str_repeat('=', $padLen);
    }
    $decoded = base64_decode($b64, true);
    if ($decoded === false) {
      return NULL;
    }
    $parts = explode(':', $decoded);
    if (count($parts) !== 3) {
      return NULL;
    }
    [$handoverId, $uid, $expires] = $parts;
    // Ensure numeric and non‑negative.
    if (!ctype_digit($handoverId) || !ctype_digit($uid) || !ctype_digit($expires)) {
      return NULL;
    }
    $handoverId = (int) $handoverId;
    $uid = (int) $uid;
    $expires = (int) $expires;
    // Check expiration.
    if ($expires < $this->time->getRequestTime()) {
      return NULL;
    }
    return [
      'handover' => $handoverId,
      'uid' => $uid,
      'expires' => $expires,
    ];
  }
}
