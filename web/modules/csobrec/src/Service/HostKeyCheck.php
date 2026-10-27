<?php

declare(strict_types=1);

namespace Drupal\csobrec\Service;

/**
 * The fingerprint of ČSOB's SFTP host key, and when it is enough.
 *
 * The host key is the only thing that tells their server apart from somebody
 * pretending to be it, so it is checked BEFORE the password is sent. The
 * fingerprint is computed exactly the way `ssh-keygen -lf` prints it, so the
 * operator can compare the two by eye.
 */
final class HostKeyCheck {

  /**
   * Computes the OpenSSH fingerprint of a public host key.
   *
   * @param string $hostKey
   *   The public key in the format phpseclib's SSH2::getServerPublicHostKey()
   *   returns: '<type> <base64>', possibly with a trailing comment and
   *   surrounding whitespace.
   *
   * @return string
   *   The fingerprint as `ssh-keygen -lf` prints it: 'SHA256:' followed by
   *   the unpadded base64 of the SHA-256 hash of the raw key blob.
   *
   * @throws \InvalidArgumentException
   *   If the input is not two whitespace-separated words whose second word
   *   is valid base64.
   */
  public function fingerprint(string $hostKey): string {
    $words = preg_split('/\s+/', trim($hostKey)) ?: [];
    if (count($words) < 2) {
      throw new \InvalidArgumentException(sprintf(
        'Expected a host key in the form "<type> <base64>", got: %s',
        $hostKey === '' ? '(empty string)' : $hostKey
      ));
    }
    // Anything after the base64 word is a comment and is ignored.
    [$type, $encoded] = $words;
    if (!preg_match('/^(ssh-|ecdsa-|sk-)/', $type)) {
      throw new \InvalidArgumentException(sprintf(
        'The first word of the host key does not look like a key type: %s',
        $hostKey
      ));
    }
    $raw = base64_decode($encoded, TRUE);
    if ($raw === FALSE) {
      throw new \InvalidArgumentException(sprintf(
        'The second word of the host key is not valid base64: %s',
        $hostKey
      ));
    }
    return 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, TRUE)), '=');
  }

  /**
   * Verifies the host key against the configured fingerprint.
   *
   * A configured fingerprint is ALWAYS compared — the switch only decides
   * whether a fingerprint is REQUIRED. Filling the fingerprint and forgetting
   * the switch must not look verified and verify nothing.
   *
   * @param string $hostKey
   *   The public key, as in fingerprint().
   * @param string|null $expected
   *   The configured fingerprint. Compared tolerantly: surrounding
   *   whitespace, a missing 'SHA256:' prefix and base64 '=' padding all
   *   still match.
   * @param bool $enforce
   *   Whether a fingerprint is required at all.
   *
   * @return string
   *   The computed fingerprint, so the caller can log it.
   *
   * @throws \InvalidArgumentException
   *   If the host key is not in the expected form.
   * @throws \RuntimeException
   *   If the fingerprints differ, or if nothing is configured while
   *   enforcing.
   */
  public function verify(string $hostKey, ?string $expected, bool $enforce): string {
    $actual = $this->fingerprint($hostKey);
    $expected = trim((string) $expected);
    if ($expected === '') {
      if ($enforce) {
        throw new \RuntimeException(sprintf(
          'Verifying the host key is enforced but no fingerprint is configured; refusing to connect to %s (fingerprint %s).',
          $hostKey,
          $actual
        ));
      }
      return $actual;
    }
    $normalized = rtrim(str_replace('SHA256:', '', $expected), '=');
    if ($normalized !== substr($actual, strlen('SHA256:'))) {
      // Fingerprints are public knowledge; naming both is what lets the
      // operator tell a key rotation from an attack.
      throw new \RuntimeException(sprintf(
        'Host key fingerprint mismatch: the server presented %s but the configured fingerprint is %s.',
        $actual,
        $expected
      ));
    }
    return $actual;
  }

}
