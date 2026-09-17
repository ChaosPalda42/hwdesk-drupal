<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Raw ZPL to a network Zebra printer (port 9100).
 */
final class ZplPrinter {

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  public function send(string $zpl, float $timeout = 5.0): void {
    $host = trim((string) $this->configFactory->get('hwdesk.settings')->get('label_printer_host'));
    if ($host === '') {
      throw new \RuntimeException('Tiskárna štítků není nastavena (Nastavení → HW Desk).');
    }
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, 9100, $errno, $errstr, $timeout);
    if ($socket === FALSE) {
      throw new \RuntimeException(sprintf('Tiskárna %s neodpovídá: %s (%d).', $host, $errstr, $errno));
    }
    try {
      $written = fwrite($socket, $zpl);
      if ($written === FALSE || $written < strlen($zpl)) {
        throw new \RuntimeException(sprintf('Odeslání na tiskárnu %s se nezdařilo.', $host));
      }
    }
    finally {
      fclose($socket);
    }
  }

}
