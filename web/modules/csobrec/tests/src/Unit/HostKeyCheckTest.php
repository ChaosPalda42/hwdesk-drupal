<?php

declare(strict_types=1);

namespace Drupal\Tests\csobrec\Unit;

use Drupal\csobrec\Service\HostKeyCheck;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The host key of ČSOB's SFTP server: the fingerprint and when it is enough.
 *
 * This is the only thing that tells our server apart from somebody pretending
 * to be it, so it is checked BEFORE the password is sent — a wrong key means
 * the login never happens. The vector below was produced by ssh-keygen and
 * cross-checked with `ssh-keygen -lf`, so this test also proves we compute the
 * fingerprint the same way the operator reads it off their terminal.
 */
#[Group('csobrec')]
final class HostKeyCheckTest extends UnitTestCase {

  /**
   * An ed25519 public key in the format phpseclib's getServerPublicHostKey()
   * returns: '<type> <base64>'.
   */
  private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIlB5obEEd8ABcUgtW/Mn1mLKSQT1AYZ86W+jdoF4bcT';

  /**
   * What `ssh-keygen -lf` prints for that key.
   */
  private const FINGERPRINT = 'SHA256:XgG3dN59hxj1B4kyP4VSWCDUE7+9BpVDJWSMFit0/wA';

  private function check(): HostKeyCheck {
    return new HostKeyCheck();
  }

  public function testTheFingerprintIsTheOneSshKeygenPrints(): void {
    $this->assertSame(self::FINGERPRINT, $this->check()->fingerprint(self::KEY));
  }

  public function testACommentAndStrayWhitespaceDoNotChangeTheFingerprint(): void {
    $this->assertSame(self::FINGERPRINT, $this->check()->fingerprint('  ' . self::KEY . " sftp.csob.cz\n"));
  }

  public function testTheFingerprintHasNoBase64Padding(): void {
    // ssh-keygen prints it without '='; an otisk copied from the terminal
    // would never match a padded one.
    $this->assertStringStartsWith('SHA256:', $this->check()->fingerprint(self::KEY));
    $this->assertStringNotContainsString('=', $this->check()->fingerprint(self::KEY));
  }

  public function testSomethingThatIsNotAKeyIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->check()->fingerprint('tohle neni klic');
  }

  public function testAnEmptyKeyIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->check()->fingerprint('');
  }

  public function testWithNothingConfiguredAndNoEnforcingItOnlyReportsTheFingerprint(): void {
    // The state we are in until ČSOB confirms their key: we cannot verify, so
    // we return the fingerprint for the log and let the run continue.
    $this->assertSame(self::FINGERPRINT, $this->check()->verify(self::KEY, NULL, FALSE));
    $this->assertSame(self::FINGERPRINT, $this->check()->verify(self::KEY, '', FALSE));
  }

  public function testEnforcingWithoutAFingerprintRefusesToConnect(): void {
    // 'verify the key' with nothing to verify against must not silently pass.
    $this->expectException(\RuntimeException::class);
    $this->check()->verify(self::KEY, NULL, TRUE);
  }

  public function testAMatchingFingerprintPasses(): void {
    $this->assertSame(self::FINGERPRINT, $this->check()->verify(self::KEY, self::FINGERPRINT, TRUE));
  }

  public function testAFingerprintPastedWithoutThePrefixOrWithPaddingStillMatches(): void {
    $bare = substr(self::FINGERPRINT, strlen('SHA256:'));
    $this->assertSame(self::FINGERPRINT, $this->check()->verify(self::KEY, $bare, TRUE));
    $this->assertSame(self::FINGERPRINT, $this->check()->verify(self::KEY, $bare . '=', TRUE));
    $this->assertSame(self::FINGERPRINT, $this->check()->verify(self::KEY, ' ' . self::FINGERPRINT . ' ', TRUE));
  }

  public function testADifferentKeyIsRefusedAndBothFingerprintsAreNamed(): void {
    $expected = 'SHA256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    try {
      $this->check()->verify(self::KEY, $expected, TRUE);
      $this->fail('A key that does not match the configured fingerprint must be refused.');
    }
    catch (\RuntimeException $e) {
      // Fingerprints are public knowledge; naming both is what lets the
      // operator tell a key change from an attack.
      $this->assertStringContainsString($expected, $e->getMessage());
      $this->assertStringContainsString(self::FINGERPRINT, $e->getMessage());
    }
  }

  public function testAConfiguredFingerprintIsCheckedEvenWithoutEnforcing(): void {
    // Deliberately stricter than the Python version, where overit_klic = false
    // skipped the comparison: filling otisk and forgetting the switch would
    // look verified and verify nothing. Here the switch only decides whether a
    // fingerprint is REQUIRED; a configured one is always compared.
    $this->expectException(\RuntimeException::class);
    $this->check()->verify(self::KEY, 'SHA256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', FALSE);
  }

}
