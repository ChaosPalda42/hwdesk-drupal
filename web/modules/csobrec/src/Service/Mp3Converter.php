<?php

declare(strict_types=1);

namespace Drupal\csobrec\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Process\Process;

/**
 * Converts one opus recording into the MP3 shape ČSOB accepts.
 *
 * The parameters are not a preference: they were measured with ffprobe on a
 * recording ČSOB already accepts (mp3, 8000 Hz, 2 channels, 32 kb/s).
 * Anything else is a different file than the one their importer has been
 * eating for years, so the command is pinned.
 */
final class Mp3Converter {

  /**
   * The configured ffmpeg binary, falling back to the one in PATH.
   *
   * Read on every call, not in the constructor: the setting may change
   * between calls (and tests swap it).
   */
  private function binary(): string {
    $configured = $this->configFactory->get('csobrec.settings')->get('ffmpeg');
    if ($configured === NULL || $configured === '') {
      return 'ffmpeg';
    }
    return (string) $configured;
  }

  /**
   * Builds the conversion command.
   *
   * @param string $source
   *   The opus file to convert.
   * @param string $target
   *   Where the MP3 goes.
   *
   * @return list<string>
   *   The exact argv for ffmpeg.
   */
  public function command(string $source, string $target): array {
    return [
      $this->binary(),
      '-y',
      '-loglevel', 'error',
      '-i', $source,
      '-codec:a', 'libmp3lame',
      '-ar', '8000',
      '-ac', '2',
      '-b:a', '32k',
      $target,
    ];
  }

  /**
   * Converts one recording.
   *
   * @param string $source
   *   The opus file to convert; it must exist.
   * @param string $target
   *   Where the MP3 goes.
   *
   * @return string
   *   The target path, on success.
   *
   * @throws \RuntimeException
   *   When the source is not an existing file, or ffmpeg exits non-zero.
   */
  public function convert(string $source, string $target): string {
    if (!is_file($source)) {
      throw new \RuntimeException('The source file ' . $source . ' does not exist.');
    }

    $command = $this->command($source, $target);
    if ($this->runner !== NULL) {
      [$code, $stderr] = ($this->runner)($command);
    }
    else {
      // No shell: the argv is passed through as-is, so no quoting worries.
      // No timeout: a long recording takes a while.
      $process = new Process($command, NULL, NULL, NULL, NULL);
      $process->run();
      $code = $process->getExitCode();
      $stderr = $process->getErrorOutput();
    }

    if ($code !== 0) {
      throw new \RuntimeException('ffmpeg exited with code ' . $code . ': ' . $stderr);
    }
    return $target;
  }

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?\Closure $runner = NULL,
  ) {
  }

}
