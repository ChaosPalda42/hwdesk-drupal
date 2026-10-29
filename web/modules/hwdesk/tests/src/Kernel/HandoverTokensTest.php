<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\Service\HandoverTokens;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Jednorázové odkazy k potvrzení: v databázi je jen kontrolní součet odkazu.
 */
#[Group('hwdesk')]
final class HandoverTokensTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  private UserInterface $jana;

  private int $serial = 0;

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    $this->jana = $this->makeUser('jana@firma.cz', 'Jana Nováková');
  }

  private function tokens(): HandoverTokens {
    return $this->container->get('hwdesk.handover_tokens');
  }

  /**
   * @param array<string, mixed> $values
   */
  private function handover(array $values = []): Handover {
    $this->serial++;
    return $this->makeHandover($this->makeAsset(sprintf('NB-%04d', $this->serial)), $this->jana, $values);
  }

  private function reload(Handover $handover): Handover {
    $storage = $this->container->get('entity_type.manager')->getStorage('hwdesk_handover');
    $storage->resetCache([$handover->id()]);
    $loaded = $storage->load($handover->id());
    assert($loaded instanceof Handover);
    return $loaded;
  }

  private function issueFor(Handover $handover): string {
    return $this->tokens()->issue((int) $handover->id(), (int) $this->jana->id(), $handover->getExpires());
  }

  private function storedHash(Handover $handover): string {
    return (string) $this->reload($handover)->get('token_hash')->value;
  }

  public function testOnlyTheChecksumOfTheLinkIsStored(): void {
    $handover = $this->handover();
    $token = $this->issueFor($handover);

    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token, 'Odkaz má být 32 náhodných bajtů v base64url bez vycpávky.');
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->storedHash($handover), 'V databázi má být sha256 v malých hexa znacích.');
    $this->assertSame(hash('sha256', $token), $this->storedHash($handover));
    $this->assertStringNotContainsString($token, serialize($this->reload($handover)->toArray()), 'Samotný odkaz se nesmí uložit do žádného pole.');
  }

  public function testEveryIssueGivesANewLinkAndRetiresThePreviousOne(): void {
    $jedno = $this->handover();
    $druhe = $this->handover();
    $this->assertNotSame($this->issueFor($jedno), $this->issueFor($druhe), 'Dvě předání nesmí dostat stejný odkaz.');

    $stary = $this->issueFor($jedno);
    $novy = $this->issueFor($jedno);
    $this->assertNotSame($stary, $novy);
    $this->assertNull($this->tokens()->verify($stary), 'Nové odeslání výzvy zneplatní starý odkaz.');
    $this->assertNotNull($this->tokens()->verify($novy));
  }

  public function testVerifyReadsTheUserAndExpiryFromTheEntity(): void {
    $handover = $this->handover(['expires' => time() + 7200]);
    // Záměrně nesmyslné argumenty: pravdu má uložené předání, ne volající.
    $token = $this->tokens()->issue((int) $handover->id(), 999999, time() + 999999);

    $this->assertSame([
      'handover' => (int) $handover->id(),
      'uid' => (int) $this->jana->id(),
      'expires' => $handover->getExpires(),
    ], $this->tokens()->verify($token));
  }

  public function testGarbageAndNeverIssuedLinksAreRejected(): void {
    $handover = $this->handover();
    $token = $this->issueFor($handover);
    $zmeneny = ($token[0] === 'a' ? 'b' : 'a') . substr($token, 1);

    $odmitnout = [
      'prázdný' => '',
      'mezera' => ' ',
      'krátký' => 'x',
      'nikdy nevydaný' => str_repeat('a', 43),
      'kontrolní součet místo odkazu' => hash('sha256', $token),
      'změněný první znak' => $zmeneny,
      'osekaný' => substr($token, 0, 42),
      'o znak delší' => $token . 'a',
      'nepovolený znak' => substr($token, 0, 42) . '+',
    ];
    foreach ($odmitnout as $popis => $bad) {
      $this->assertNull($this->tokens()->verify($bad), sprintf('Odkaz „%s“ musí být odmítnut.', $popis));
    }
    $this->assertNotNull($this->tokens()->verify($token), 'Platný odkaz musí projít i po sérii odmítnutých.');
  }

  public function testExpiredLinkIsRejected(): void {
    // Měří se stejnými hodinami, jaké má služba. getRequestTime() se zmrazí
    // při vzniku požadavku, kdežto time() běží dál: „propadlo před vteřinou“
    // podle nástěnných hodin tedy podle služby ještě nemuselo nastat a brána
    // padala podle toho, jak dlouho už běh trvá.
    $ted = $this->container->get('datetime.time')->getRequestTime();

    $propadle = $this->handover(['expires' => $ted - 3600]);
    $this->assertNull($this->tokens()->verify($this->issueFor($propadle)));

    $tesne = $this->handover(['expires' => $ted - 1]);
    $this->assertNull($this->tokens()->verify($this->issueFor($tesne)), 'Vteřina po platnosti je taky po platnosti.');

    $plati = $this->handover(['expires' => $ted + 3600]);
    $this->assertNotNull($this->tokens()->verify($this->issueFor($plati)));
  }

  public function testOnlyAPendingHandoverCanBeConfirmed(): void {
    foreach ([HandoverStatus::Confirmed, HandoverStatus::Rejected, HandoverStatus::Expired, HandoverStatus::Cancelled] as $status) {
      $handover = $this->handover();
      $token = $this->issueFor($handover);
      $this->assertNotNull($this->tokens()->verify($token));

      $handover->setStatus($status);
      $handover->save();
      $this->assertNull($this->tokens()->verify($token), sprintf('Odkaz nesmí platit ve stavu %s.', $status->value));
    }
  }

  public function testTheLinkWorksOnlyOnce(): void {
    $handover = $this->handover();
    $token = $this->issueFor($handover);
    $this->assertNotNull($this->tokens()->verify($token));

    $this->tokens()->consume($token);
    $this->assertNull($this->tokens()->verify($token), 'Použitý odkaz už nesmí platit.');
    $this->assertSame('', $this->storedHash($handover), 'Po použití se kontrolní součet z předání smaže.');

    // Druhé spotřebování a cizí odkaz jsou tiché; výjimka by rozbila stránku.
    $this->tokens()->consume($token);
    $this->tokens()->consume(str_repeat('b', 43));
    $this->tokens()->consume('');
  }

  public function testIssuingForAMissingHandoverFailsWithoutPrintingTheLink(): void {
    try {
      $this->tokens()->issue(999999, (int) $this->jana->id(), time() + 60);
      $this->fail('Neznámé předání musí skončit výjimkou.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('999999', $e->getMessage(), 'Výjimka má pojmenovat předání, kvůli kterému spadla.');
      $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9_-]{32,}/', $e->getMessage(), 'Do textu výjimky nesmí odkaz ani jeho součet.');
    }
  }

}
