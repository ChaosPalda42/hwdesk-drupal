<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\Service\HandoverTokens;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Signed, expiring tokens for the confirmation link in the e-mail.
 */
#[Group('hwdesk')]
final class HandoverTokensTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  private function tokens(): HandoverTokens {
    return $this->container->get('hwdesk.handover_tokens');
  }

  public function testTokenIsUrlSafeAndRoundTrips(): void {
    $expires = time() + 3600;
    $token = $this->tokens()->issue(12, 7, $expires);
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', $token);
    $this->assertSame(['handover' => 12, 'uid' => 7, 'expires' => $expires], $this->tokens()->verify($token));
  }

  public function testDifferentHandoversGetDifferentTokens(): void {
    $expires = time() + 3600;
    $this->assertNotSame($this->tokens()->issue(1, 7, $expires), $this->tokens()->issue(2, 7, $expires));
    $this->assertNotSame($this->tokens()->issue(1, 7, $expires), $this->tokens()->issue(1, 8, $expires));
  }

  public function testTamperedTokenIsRejected(): void {
    $token = $this->tokens()->issue(12, 7, time() + 3600);
    [$payload, $signature] = explode('.', $token, 2);
    $flipped = substr($payload, 0, -1) . ($payload[-1] === 'A' ? 'B' : 'A');
    $this->assertNull($this->tokens()->verify($flipped . '.' . $signature));
    $this->assertNull($this->tokens()->verify($payload . '.' . strrev($signature)));
    $this->assertNull($this->tokens()->verify($payload));
    $this->assertNull($this->tokens()->verify(''));
    $this->assertNull($this->tokens()->verify('not.a.token.at.all'));
  }

  public function testExpiredTokenIsRejected(): void {
    $this->assertNull($this->tokens()->verify($this->tokens()->issue(12, 7, time() - 1)));
    $this->assertNotNull($this->tokens()->verify($this->tokens()->issue(12, 7, time() + 60)));
  }

  public function testTokenDependsOnTheSitePrivateKey(): void {
    $token = $this->tokens()->issue(12, 7, time() + 3600);
    $this->container->get('private_key')->set('another-key');
    $this->assertNull($this->tokens()->verify($token));
  }

}
