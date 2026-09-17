<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The module installs on a bare kernel.
 */
#[Group('hwdesk')]
final class SmokeTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'options', 'datetime', 'views', 'hwdesk'];

  public function testModuleInstalls(): void {
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('hwdesk'));
  }

}
