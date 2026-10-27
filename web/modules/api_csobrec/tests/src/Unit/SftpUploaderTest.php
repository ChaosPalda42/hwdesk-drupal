<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Unit;

use Drupal\api_csobrec\Service\SftpUploader;
use Drupal\api_csobrec\Sftp\SftpSessionInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Uploading one finished batch to ČSOB.
 *
 * ČSOB collects the folder every morning and reads the .don file as 'the batch
 * is complete'. Everything here serves that one rule: the marker goes up last,
 * and when anything fails it must not go up at all — a batch without its
 * marker is retried, a marker without its recordings is a silent loss.
 */
#[Group('api_csobrec')]
final class SftpUploaderTest extends UnitTestCase {

  private string $dir;

  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/api_csobrec-upload-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0775, TRUE);
  }

  protected function tearDown(): void {
    foreach (glob($this->dir . '/*') ?: [] as $path) {
      if (is_file($path)) {
        unlink($path);
      }
      elseif (is_dir($path)) {
        array_map('unlink', glob($path . '/*') ?: []);
        rmdir($path);
      }
    }
    if (is_dir($this->dir)) {
      rmdir($this->dir);
    }
    parent::tearDown();
  }

  private function file(string $name, string $bytes = 'x'): void {
    file_put_contents($this->dir . '/' . $name, $bytes);
  }

  /**
   * A complete batch: three recordings, the index and the marker.
   */
  private function completeBatch(): void {
    $this->file('DA_20261026080903_aktivita-3.mp3');
    $this->file('DA_20261026070101_aktivita-1.mp3');
    $this->file('DA_20261026075500_aktivita-2.mp3');
    $this->file('DA_202610268080903.xml', '<davka/>');
    $this->file('DA_202610268080903.don', '');
  }

  public function testTheMarkerGoesUpLastAndRecordingsInNameOrder(): void {
    $this->completeBatch();
    $session = new RecordingSession();
    $uploaded = (new SftpUploader())->upload($session, $this->dir, '/upload/nahravky');

    $this->assertSame([
      '/upload/nahravky/DA_20261026070101_aktivita-1.mp3',
      '/upload/nahravky/DA_20261026075500_aktivita-2.mp3',
      '/upload/nahravky/DA_20261026080903_aktivita-3.mp3',
      '/upload/nahravky/DA_202610268080903.xml',
      '/upload/nahravky/DA_202610268080903.don',
    ], $uploaded);
    $this->assertSame($uploaded, $session->uploaded);
  }

  public function testTheLocalFileIsTheOneHandedToTheSession(): void {
    $this->completeBatch();
    $session = new RecordingSession();
    (new SftpUploader())->upload($session, $this->dir, '/upload/nahravky');
    $this->assertSame($this->dir . '/DA_20261026070101_aktivita-1.mp3', $session->local['/upload/nahravky/DA_20261026070101_aktivita-1.mp3']);
    $this->assertSame($this->dir . '/DA_202610268080903.don', $session->local['/upload/nahravky/DA_202610268080903.don']);
  }

  public function testTheRemoteDirectoryIsListedBeforeAnythingIsUploaded(): void {
    $this->completeBatch();
    $session = new RecordingSession();
    (new SftpUploader())->upload($session, $this->dir, '/upload/nahravky');
    $this->assertSame(['/upload/nahravky'], $session->listed);
  }

  public function testATrailingSlashOnTheRemotePathDoesNotDoubleUp(): void {
    $this->completeBatch();
    $session = new RecordingSession();
    $uploaded = (new SftpUploader())->upload($session, $this->dir, '/upload/nahravky/');
    foreach ($uploaded as $remote) {
      $this->assertStringStartsWith('/upload/nahravky/DA_', $remote);
    }
  }

  public function testAMissingRemoteDirectoryUploadsNothing(): void {
    // The module never creates the folder on their side: a typo would make a
    // stray directory, the batch would 'arrive' where nobody looks, and the
    // ledger would claim ČSOB has it.
    $this->completeBatch();
    $session = new RecordingSession(listFails: TRUE);
    try {
      (new SftpUploader())->upload($session, $this->dir, '/upload/preklep');
      $this->fail('A missing remote directory must stop the upload.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('/upload/preklep', $e->getMessage());
    }
    $this->assertSame([], $session->uploaded);
  }

  /**
   * @param array<string, string> $files
   */
  #[DataProvider('incompleteBatches')]
  public function testAnIncompleteBatchUploadsNothing(array $files): void {
    foreach ($files as $name => $bytes) {
      $this->file($name, $bytes);
    }
    $session = new RecordingSession();
    try {
      (new SftpUploader())->upload($session, $this->dir, '/upload/nahravky');
      $this->fail('An incomplete batch must be refused before anything is uploaded.');
    }
    catch (\InvalidArgumentException $e) {
      // Counts, not names: file names carry the activity ids.
      $this->assertStringNotContainsString('.mp3', $e->getMessage());
    }
    $this->assertSame([], $session->uploaded);
    $this->assertSame([], $session->listed);
  }

  /**
   * @return array<string, array{array<string, string>}>
   */
  public static function incompleteBatches(): array {
    return [
      'nothing at all' => [[]],
      'no recording' => [['DA_1.xml' => '<davka/>', 'DA_1.don' => '']],
      'no index' => [['a.mp3' => 'x', 'DA_1.don' => '']],
      'no marker' => [['a.mp3' => 'x', 'DA_1.xml' => '<davka/>']],
      'two indexes' => [['a.mp3' => 'x', 'DA_1.xml' => '<davka/>', 'DA_2.xml' => '<davka/>', 'DA_1.don' => '']],
      'two markers' => [['a.mp3' => 'x', 'DA_1.xml' => '<davka/>', 'DA_1.don' => '', 'DA_2.don' => '']],
    ];
  }

  public function testAFileThatIsNotPartOfABatchIsNotUploaded(): void {
    $this->completeBatch();
    $this->file('poznamky.txt', 'neco');
    mkdir($this->dir . '/puvodni');
    file_put_contents($this->dir . '/puvodni/zdroj.opus', 'x');
    $session = new RecordingSession();
    $uploaded = (new SftpUploader())->upload($session, $this->dir, '/upload/nahravky');
    $this->assertCount(5, $uploaded);
    foreach ($uploaded as $remote) {
      $this->assertStringNotContainsString('poznamky', $remote);
      $this->assertStringNotContainsString('zdroj', $remote);
    }
  }

  public function testWhenATransferFailsTheMarkerNeverGoesUp(): void {
    $this->completeBatch();
    // The third put fails: two recordings are already there, the index and the
    // marker are not. ČSOB sees an unfinished folder and ignores it.
    $session = new RecordingSession(failOnPut: 3);
    $this->expectException(\RuntimeException::class);
    try {
      (new SftpUploader())->upload($session, $this->dir, '/upload/nahravky');
    }
    finally {
      $this->assertCount(2, $session->uploaded);
      foreach ($session->uploaded as $remote) {
        $this->assertStringEndsWith('.mp3', $remote);
      }
    }
  }

  public function testAnUnreadableLocalDirectoryIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new SftpUploader())->upload(new RecordingSession(), $this->dir . '/neexistuje', '/upload/nahravky');
  }

}
