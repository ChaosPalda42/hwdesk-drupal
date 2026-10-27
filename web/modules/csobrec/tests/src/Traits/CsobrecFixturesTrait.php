<?php

declare(strict_types=1);

namespace Drupal\Tests\csobrec\Traits;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PrivateStream;

/**
 * Schema and private:// setup shared by the csobrec kernel tests.
 *
 * Recordings are customer data, so everything this module writes lives under
 * private://csobrec — which kernel tests do not have unless we register it.
 */
trait CsobrecFixturesTrait {

  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  protected function setUpFilesystem() {
    parent::setUpFilesystem();
    $private = $this->siteDirectory . '/private';
    if (!is_dir($private)) {
      mkdir($private, 0775, TRUE);
    }
    $this->setSetting('file_private_path', $private);
  }

  protected function installCsobrec(): void {
    $this->installSchema('csobrec', ['csobrec_odeslano', 'csobrec_davka']);
    $this->installConfig(['system', 'csobrec']);
  }

  /**
   * A directory under private:// that a test may fill.
   */
  protected function privateDir(string $relative): string {
    $uri = 'private://' . ltrim($relative, '/');
    $this->container->get('file_system')->prepareDirectory(
      $uri,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    return $uri;
  }

}
