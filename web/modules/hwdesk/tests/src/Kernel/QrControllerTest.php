<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\hwdesk\Controller\QrController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\hwdesk\Traits\HwdeskFixturesTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cíl QR kódu ze štítku. Brána drží dvě věci: že správce i držitel skončí tam,
 * kde mají, a že cizí štítek se navenek neliší od štítku, který neexistuje —
 * jinak by si kdokoli přihlášený mohl inventární čísla osahat a zjistit, která
 * firma používá.
 */
#[Group('hwdesk')]
final class QrControllerTest extends KernelTestBase {

  use HwdeskFixturesTrait;

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  protected function setUp(): void {
    parent::setUp();
    $this->installHwdesk();
    // Uživatel č. 1 je v Drupalu všemocný: kdyby ho dostal držitel nebo kolega,
    // platilo by mu i „administer hwdesk“ a brána by měřila něco jiného, než
    // o čem si myslí, že měří. Proto se první místo zabere nikým.
    $this->makeUser('nikdo-vsemocny@example.test');
  }

  private function jako(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  private function go(string $tag): RedirectResponse {
    return QrController::create($this->container)->go($tag);
  }

  public function testSpravceSkonciNaDetailuZarizeni(): void {
    $asset = $this->makeAsset('NB-0001');
    Role::create(['id' => 'hwdesk_spravce', 'label' => 'Správce'])
      ->grantPermission('administer hwdesk')->save();
    $spravce = $this->makeUser('spravce@example.test');
    $spravce->addRole('hwdesk_spravce');
    $spravce->save();
    $this->jako($spravce);

    $odpoved = $this->go('NB-0001');

    $this->assertStringContainsString('/hwdesk/asset/' . $asset->id(), $odpoved->getTargetUrl());
  }

  public function testMalePismenkaVAdreseJsouTenZStitku(): void {
    $asset = $this->makeAsset('NB-0007');
    Role::create(['id' => 'hwdesk_spravce', 'label' => 'Správce'])
      ->grantPermission('administer hwdesk')->save();
    $spravce = $this->makeUser('spravce@example.test');
    $spravce->addRole('hwdesk_spravce');
    $spravce->save();
    $this->jako($spravce);

    $odpoved = $this->go('nb-0007');

    $this->assertStringContainsString('/hwdesk/asset/' . $asset->id(), $odpoved->getTargetUrl());
  }

  public function testDrzitelSkonciNaSvychZarizenich(): void {
    $drzitel = $this->makeUser('drzitel@example.test');
    $this->makeAsset('NB-0002', ['holder' => $drzitel->id()]);
    $this->jako($drzitel);

    $odpoved = $this->go('NB-0002');

    $this->assertStringContainsString('/hwdesk/my', $odpoved->getTargetUrl());
  }

  public function testCiziStitekANeexistujiciStitekOdpovidajiStejne(): void {
    $kolega = $this->makeUser('kolega@example.test');
    $this->makeAsset('NB-0003', ['holder' => $kolega->id()]);
    $kdokoli = $this->makeUser('kdokoli@example.test');
    $this->jako($kdokoli);

    $cizi = NULL;
    try {
      $this->go('NB-0003');
    }
    catch (\Throwable $e) {
      $cizi = $e;
    }
    $neexistujici = NULL;
    try {
      $this->go('NB-9999');
    }
    catch (\Throwable $e) {
      $neexistujici = $e;
    }

    $this->assertInstanceOf(NotFoundHttpException::class, $cizi, 'Cizí štítek nesmí prozradit, že existuje.');
    $this->assertInstanceOf(NotFoundHttpException::class, $neexistujici);
    $this->assertSame($neexistujici->getStatusCode(), $cizi->getStatusCode());
    $this->assertSame($neexistujici->getMessage(), $cizi->getMessage(), 'Ani text hlášky se nesmí lišit.');
  }

  public function testNepridelenyKusCizimuNepatriAniTakSeNeukaze(): void {
    $this->makeAsset('NB-0004');
    $kdokoli = $this->makeUser('nikdo@example.test');
    $this->jako($kdokoli);

    $this->expectException(NotFoundHttpException::class);
    $this->go('NB-0004');
  }

}
