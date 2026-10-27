<?php

declare(strict_types=1);

namespace Drupal\Tests\csobrec\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\csobrec\Service\Mp3Converter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * opus → MP3 in the shape ČSOB gets today.
 *
 * The parameters are not a preference: they were measured with ffprobe on a
 * recording ČSOB already accepts (DA_20240603080040_….mp3 — mp3, 8000 Hz, 2
 * channels, 32 kb/s). Anything else is a different file than the one their
 * importer has been eating for years, so the command is pinned here.
 */
#[Group('csobrec')]
final class Mp3ConverterTest extends UnitTestCase {

  private string $dir;

  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/csobrec-prevod-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0775, TRUE);
  }

  protected function tearDown(): void {
    array_map('unlink', glob($this->dir . '/*') ?: []);
    if (is_dir($this->dir)) {
      rmdir($this->dir);
    }
    parent::tearDown();
  }

  private function config(?string $ffmpeg): ConfigFactoryInterface {
    return $this->getConfigFactoryStub(['csobrec.settings' => ['ffmpeg' => $ffmpeg]]);
  }

  private function converter(?string $ffmpeg = 'ffmpeg', ?\Closure $runner = NULL): Mp3Converter {
    return new Mp3Converter($this->config($ffmpeg), $runner);
  }

  public function testTheCommandIsTheMeasuredOne(): void {
    $converter = $this->converter();
    $this->assertSame([
      'ffmpeg',
      '-y',
      '-loglevel', 'error',
      '-i', '/tmp/zdroj.opus',
      '-codec:a', 'libmp3lame',
      '-ar', '8000',
      '-ac', '2',
      '-b:a', '32k',
      '/tmp/cil.mp3',
    ], $converter->command('/tmp/zdroj.opus', '/tmp/cil.mp3'));
  }

  public function testAConfiguredBinaryIsUsedInsteadOfPlainFfmpeg(): void {
    // The Drupal machine may have it outside PATH; the setting is a full path.
    $converter = $this->converter('/opt/homebrew/bin/ffmpeg');
    $this->assertSame('/opt/homebrew/bin/ffmpeg', $converter->command('a.opus', 'a.mp3')[0]);
  }

  public function testAnEmptySettingFallsBackToFfmpegInThePath(): void {
    foreach ([NULL, ''] as $nothing) {
      $this->assertSame('ffmpeg', $this->converter($nothing)->command('a.opus', 'a.mp3')[0]);
    }
  }

  public function testConvertRunsThatCommandOnceAndReturnsTheTarget(): void {
    file_put_contents($this->dir . '/zdroj.opus', 'x');
    $run = [];
    $converter = $this->converter(runner: function (array $command) use (&$run): array {
      $run[] = $command;
      return [0, ''];
    });
    $target = $this->dir . '/cil.mp3';
    $this->assertSame($target, $converter->convert($this->dir . '/zdroj.opus', $target));
    $this->assertCount(1, $run);
    $this->assertSame($converter->command($this->dir . '/zdroj.opus', $target), $run[0]);
  }

  public function testAMissingSourceIsRefusedWithoutRunningAnything(): void {
    $ran = FALSE;
    $converter = $this->converter(runner: function () use (&$ran): array {
      $ran = TRUE;
      return [0, ''];
    });
    try {
      $converter->convert($this->dir . '/neni.opus', $this->dir . '/cil.mp3');
      $this->fail('A missing source must be refused.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('neni.opus', $e->getMessage());
    }
    $this->assertFalse($ran);
  }

  public function testADirectoryGivenAsTheSourceIsRefused(): void {
    $converter = $this->converter(runner: fn (array $command): array => [0, '']);
    $this->expectException(\RuntimeException::class);
    $converter->convert($this->dir, $this->dir . '/cil.mp3');
  }

  public function testANonZeroExitCarriesTheCodeAndWhatFfmpegSaid(): void {
    file_put_contents($this->dir . '/zdroj.opus', 'x');
    $converter = $this->converter(runner: fn (array $command): array => [1, 'Invalid data found when processing input']);
    try {
      $converter->convert($this->dir . '/zdroj.opus', $this->dir . '/cil.mp3');
      $this->fail('A failed conversion must not be reported as done.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('1', $e->getMessage());
      $this->assertStringContainsString('Invalid data found', $e->getMessage());
    }
  }

  /**
   * The default runner against the real binary.
   *
   * Everything above tests our rules; this one tests that the pinned command
   * is a command ffmpeg actually accepts, and that the result has the measured
   * parameters. It is skipped where ffmpeg is missing (the gate runs on a Mac,
   * the module runs on the Drupal server).
   */
  public function testTheDefaultRunnerReallyProducesAnMp3WithThoseParameters(): void {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
    $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));
    if ($ffmpeg === '' || $ffprobe === '') {
      $this->markTestSkipped('ffmpeg/ffprobe not installed here.');
    }
    $source = $this->dir . '/zdroj.opus';
    exec(sprintf('%s -y -loglevel error -f lavfi -i sine=frequency=440:duration=1 -c:a libopus %s', escapeshellarg($ffmpeg), escapeshellarg($source)), $out, $code);
    $this->assertSame(0, $code, 'Could not synthesise the input.');

    $target = $this->converter($ffmpeg)->convert($source, $this->dir . '/cil.mp3');
    $this->assertFileExists($target);

    $probe = (string) shell_exec(sprintf(
      '%s -v error -select_streams a:0 -show_entries stream=codec_name,sample_rate,channels -of csv=p=0 %s',
      escapeshellarg($ffprobe),
      escapeshellarg($target),
    ));
    $this->assertSame('mp3,8000,2', trim($probe));
  }

}
