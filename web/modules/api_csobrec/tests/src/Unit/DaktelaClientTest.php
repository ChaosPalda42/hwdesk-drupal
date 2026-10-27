<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Unit;

use Drupal\Core\Site\Settings;
use Drupal\api_csobrec\Service\DaktelaClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The client to Daktela: calls of a day, a number's history, recordings.
 *
 * Every address and parameter here was measured on the live instance on
 * 27 Oct 2026, and three of them cost hours: `sort=time asc` answers 500 to
 * everything (only sort[0][field] works), a query without a queue returns
 * thousands of other clients' calls, and /api/v6/activities/<id>/record
 * answers 403 — the recording lives at /file/recording/<id>.
 *
 * The rule that must never break: the token travels in the query string, so
 * nothing may ever put a request URL into an exception or a log. Guzzle does
 * exactly that on its own unless http_errors is switched off, which is why
 * several tests below read the whole exception chain and check the token is
 * not in it.
 */
#[Group('api_csobrec')]
final class DaktelaClientTest extends UnitTestCase {

  private const TOKEN = 'TAJNY-TOKEN-DAKTELY';
  private const BASE = 'https://conectart.daktela.com/';

  /** @var list<array{request: \Psr\Http\Message\RequestInterface, response: mixed}> */
  private array $history = [];

  private string $dir;

  protected function setUp(): void {
    parent::setUp();
    $this->history = [];
    $this->dir = sys_get_temp_dir() . '/api_csobrec-daktela-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0775, TRUE);
  }

  protected function tearDown(): void {
    foreach (glob($this->dir . '/*') ?: [] as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    if (is_dir($this->dir)) {
      rmdir($this->dir);
    }
    parent::tearDown();
  }

  /**
   * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
   */
  private function client(array $responses, string $token = self::TOKEN, string $base = self::BASE): DaktelaClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return new DaktelaClient(
      new Client(['handler' => $stack]),
      $this->getConfigFactoryStub(['api_csobrec.settings' => ['daktela_url' => $base]]),
      new Settings(['api_csobrec_daktela_token' => $token]),
    );
  }

  /**
   * @param array<string, mixed> $body
   */
  private function json(array|bool $body, int $status = 200): Response {
    return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
  }

  /**
   * @param list<array<string, mixed>> $data
   */
  private function page(array $data, ?int $total = NULL): Response {
    return $this->json(['result' => ['total' => $total ?? count($data), 'data' => $data]]);
  }

  /**
   * @return array<string, string>
   */
  private function queryOf(int $i): array {
    $parsed = Query::parse($this->history[$i]['request']->getUri()->getQuery());
    return array_map(static fn ($v): string => (string) $v, $parsed);
  }

  private function pathOf(int $i): string {
    return $this->history[$i]['request']->getUri()->getPath();
  }

  private function assertNoTokenAnywhere(\Throwable $e): void {
    for ($t = $e; $t !== NULL; $t = $t->getPrevious()) {
      $this->assertStringNotContainsString(self::TOKEN, $t->getMessage(), sprintf('%s leaked the token.', $t::class));
      $this->assertStringNotContainsString(self::TOKEN, $t->getTraceAsString(), sprintf('%s leaked the token in its trace.', $t::class));
    }
  }

  // --- calls of a day ------------------------------------------------------

  public function testOneQueueIsOneRequestWithTheMeasuredParameters(): void {
    $client = $this->client([$this->page([['name' => 'a1', 'time' => '2026-10-26 08:00:00']])]);
    $calls = $client->callsOfDay(new \DateTimeImmutable('2026-10-26 00:00:00'), ['5003']);

    $this->assertCount(1, $this->history);
    $this->assertSame('/api/v6/activities.json', $this->pathOf(0));
    $this->assertSame([
      'accessToken' => self::TOKEN,
      'take' => '1000',
      'skip' => '0',
      'sort[0][field]' => 'time',
      'sort[0][dir]' => 'asc',
      'filter[logic]' => 'and',
      'filter[filters][0][field]' => 'type',
      'filter[filters][0][operator]' => 'eq',
      'filter[filters][0][value]' => 'CALL',
      'filter[filters][1][field]' => 'time',
      'filter[filters][1][operator]' => 'gte',
      'filter[filters][1][value]' => '2026-10-26 00:00:00',
      'filter[filters][2][field]' => 'time',
      'filter[filters][2][operator]' => 'lte',
      'filter[filters][2][value]' => '2026-10-26 23:59:59',
      'filter[filters][3][field]' => 'queue',
      'filter[filters][3][operator]' => 'eq',
      'filter[filters][3][value]' => '5003',
    ], $this->queryOf(0));
    $this->assertSame([['name' => 'a1', 'time' => '2026-10-26 08:00:00']], $calls);
  }

  public function testTheDaysBoundsComeFromTheDateAndNotFromTheClock(): void {
    $client = $this->client([$this->page([])]);
    $client->callsOfDay(new \DateTimeImmutable('2026-01-05 17:45:12'), ['5003']);
    $query = $this->queryOf(0);
    $this->assertSame('2026-01-05 00:00:00', $query['filter[filters][1][value]']);
    $this->assertSame('2026-01-05 23:59:59', $query['filter[filters][2][value]']);
  }

  public function testEachQueueGetsItsOwnRequestAndTheResultIsSortedByTime(): void {
    // A query without a queue returns thousands of other clients' calls, so
    // the client asks per queue and merges here.
    $client = $this->client([
      $this->page([['name' => 'a2', 'time' => '2026-10-26 09:00:00']]),
      $this->page([['name' => 'a1', 'time' => '2026-10-26 08:00:00'], ['name' => 'a3', 'time' => '2026-10-26 10:00:00']]),
    ]);
    $calls = $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003', '5004']);

    $this->assertCount(2, $this->history);
    $this->assertSame('5003', $this->queryOf(0)['filter[filters][3][value]']);
    $this->assertSame('5004', $this->queryOf(1)['filter[filters][3][value]']);
    $this->assertSame(['a1', 'a2', 'a3'], array_column($calls, 'name'));
  }

  public function testNoQueuesMeansNoRequestAndNoCalls(): void {
    $client = $this->client([]);
    $this->assertSame([], $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), []));
    $this->assertSame([], $this->history);
  }

  public function testAFullPageIsFollowedByTheNextOne(): void {
    $first = [];
    for ($i = 0; $i < 1000; $i++) {
      $first[] = ['name' => sprintf('a%04d', $i), 'time' => '2026-10-26 08:00:00'];
    }
    $second = [['name' => 'z', 'time' => '2026-10-26 09:00:00']];
    $client = $this->client([$this->page($first, 1001), $this->page($second, 1001)]);
    $calls = $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003']);

    $this->assertCount(1001, $calls);
    $this->assertCount(2, $this->history);
    $this->assertSame('0', $this->queryOf(0)['skip']);
    $this->assertSame('1000', $this->queryOf(1)['skip']);
  }

  public function testAShortPageEndsTheQueryEvenWhenTheTotalSaysOtherwise(): void {
    // Measured: total can be larger than what the instance will hand out.
    // Asking forever would hammer their server for nothing.
    $client = $this->client([$this->page([['name' => 'a1', 'time' => '2026-10-26 08:00:00']], 9999)]);
    $this->assertCount(1, $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003']));
    $this->assertCount(1, $this->history);
  }

  public function testABaseUrlWithATrailingSlashDoesNotDoubleTheSlash(): void {
    $client = $this->client([$this->page([])], base: 'https://conectart.daktela.com/');
    $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003']);
    $this->assertSame('/api/v6/activities.json', $this->pathOf(0));
    $this->assertSame('conectart.daktela.com', $this->history[0]['request']->getUri()->getHost());
  }

  public function testAnEmptyTokenSendsNothingAtAll(): void {
    $client = $this->client([$this->page([])], token: '');
    try {
      $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003']);
      $this->fail('Without a token the client must refuse rather than ask.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('token', strtolower($e->getMessage()));
    }
    $this->assertSame([], $this->history);
  }

  // --- failures ------------------------------------------------------------

  #[DataProvider('httpFailures')]
  public function testAFailedRequestNamesThePathAndTheCodeButNeverTheToken(int $status): void {
    $client = $this->client([new Response($status, [], 'cokoliv')]);
    try {
      $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003']);
      $this->fail('An HTTP failure must be reported.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('/api/v6/activities.json', $e->getMessage());
      $this->assertStringContainsString((string) $status, $e->getMessage());
      $this->assertNoTokenAnywhere($e);
    }
  }

  /**
   * @return list<array{int}>
   */
  public static function httpFailures(): array {
    return [[401], [403], [500], [502]];
  }

  public function testAnAnswerThatIsNotTheExpectedJsonIsRefused(): void {
    $client = $this->client([new Response(200, ['content-type' => 'text/html'], '<html>Údržba</html>')]);
    $this->expectException(\RuntimeException::class);
    $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003']);
  }

  public function testJsonWithoutAResultIsRefused(): void {
    $client = $this->client([$this->json(['chyba' => 'nope'])]);
    $this->expectException(\RuntimeException::class);
    $client->callsOfDay(new \DateTimeImmutable('2026-10-26'), ['5003']);
  }

  // --- history of one number ----------------------------------------------

  public function testTheHistoryOfANumberIsAskedPerQueueOnTheLastNineDigits(): void {
    // The number is stored in two shapes (9 digits and 00420 + 9), so `eq`
    // misses one of them; item.clid is the only filterable field (clid and
    // number answer 400).
    $client = $this->client([$this->page([['name' => 'a1', 'time' => '2026-10-20 08:00:00']])]);
    $client->callsOfNumber('+420 603 123 456', '5003');

    $this->assertSame([
      'accessToken' => self::TOKEN,
      'take' => '1000',
      'skip' => '0',
      'sort[0][field]' => 'time',
      'sort[0][dir]' => 'asc',
      'filter[logic]' => 'and',
      'filter[filters][0][field]' => 'type',
      'filter[filters][0][operator]' => 'eq',
      'filter[filters][0][value]' => 'CALL',
      'filter[filters][1][field]' => 'item.clid',
      'filter[filters][1][operator]' => 'contains',
      'filter[filters][1][value]' => '603123456',
      'filter[filters][2][field]' => 'queue',
      'filter[filters][2][operator]' => 'eq',
      'filter[filters][2][value]' => '5003',
    ], $this->queryOf(0));
  }

  public function testTheHistoryComesBackSortedByTime(): void {
    $client = $this->client([$this->page([
      ['name' => 'b', 'time' => '2026-10-22 08:00:00'],
      ['name' => 'a', 'time' => '2026-10-20 08:00:00'],
    ])]);
    $calls = $client->callsOfNumber('603123456', '5003');
    $this->assertSame(['a', 'b'], array_column($calls, 'name'));
  }

  #[DataProvider('acceptedNumbers')]
  public function testTheAcceptedShapesOfANumber(string $phone, string $expected): void {
    $client = $this->client([$this->page([])]);
    $client->callsOfNumber($phone, '5003');
    $this->assertSame($expected, $this->queryOf(0)['filter[filters][1][value]']);
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function acceptedNumbers(): array {
    return [
      'nine digits' => ['603123456', '603123456'],
      'with the country code' => ['420603123456', '603123456'],
      'with a double zero prefix' => ['00420603123456', '603123456'],
      'written for humans' => ['+420 603 123 456', '603123456'],
    ];
  }

  #[DataProvider('refusedNumbers')]
  public function testANumberThatWouldHitSomebodyElseIsRefusedWithoutAsking(string $phone): void {
    // 'contains' on a short string would match other people's numbers, and we
    // would download a stranger's recording.
    $client = $this->client([$this->page([])]);
    try {
      $client->callsOfNumber($phone, '5003');
      $this->fail(sprintf('%s is not a usable number.', $phone));
    }
    catch (\InvalidArgumentException) {
      // Expected.
    }
    $this->assertSame([], $this->history);
  }

  /**
   * @return array<string, array{string}>
   */
  public static function refusedNumbers(): array {
    return [
      'too short' => ['12345'],
      'eight digits' => ['60312345'],
      'ten digits with an unknown prefix' => ['1603123456'],
      'another country' => ['49603123456'],
      'no digits at all' => ['neznamo'],
      'empty' => [''],
    ];
  }

  public function testTheHistoryWithoutAQueueIsRefusedWithoutAsking(): void {
    // Without a queue the query reaches across the whole instance.
    $client = $this->client([$this->page([])]);
    try {
      $client->callsOfNumber('603123456', '');
      $this->fail('A query without a queue must be refused.');
    }
    catch (\InvalidArgumentException) {
      // Expected.
    }
    $this->assertSame([], $this->history);
  }

  // --- does a recording exist ---------------------------------------------

  public function testAnExistingRecordingIsDescribed(): void {
    $client = $this->client([$this->json(['recording' => 'xyz.opus', 'encrypt' => FALSE, 'format' => 'opus'])]);
    $this->assertSame(
      ['recording' => 'xyz.opus', 'encrypt' => FALSE, 'format' => 'opus'],
      $client->recording('aktivita-1'),
    );
    $this->assertSame('/file/recording/aktivita-1', $this->pathOf(0));
    $this->assertSame([
      'accessToken' => self::TOKEN,
      'formats' => 'mp3,ogg,opus,wav',
      'exist' => 'true',
    ], $this->queryOf(0));
  }

  public function testABareFalseMeansThereIsNoRecording(): void {
    // Measured: 18 of 42 calls answered a naked JSON false, not an object.
    $client = $this->client([$this->json(FALSE)]);
    $this->assertNull($client->recording('aktivita-1'));
  }

  public function testAnObjectWithoutARecordingMeansThereIsNone(): void {
    $client = $this->client([$this->json(['recording' => NULL, 'format' => 'opus'])]);
    $this->assertNull($client->recording('aktivita-1'));
  }

  public function testAMissingActivityMeansThereIsNoRecording(): void {
    $client = $this->client([new Response(404, [], 'Not found')]);
    $this->assertNull($client->recording('aktivita-1'));
  }

  public function testAServerErrorOnTheRecordingIsNotMistakenForNoRecording(): void {
    // Returning NULL here would quietly drop a call out of the batch.
    $client = $this->client([new Response(500, [], 'Internal')]);
    try {
      $client->recording('aktivita-1');
      $this->fail('A server error must not look like a missing recording.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('/file/recording/aktivita-1', $e->getMessage());
      $this->assertNoTokenAnywhere($e);
    }
  }

  // --- downloading the recording ------------------------------------------

  public function testTheRecordingIsWrittenToTheGivenFile(): void {
    $client = $this->client([new Response(200, ['content-type' => 'audio/opus'], 'OggS-tohle-je-zvuk')]);
    $target = $this->dir . '/nahravka.opus';
    $this->assertSame($target, $client->download('aktivita-1', $target));
    $this->assertSame('OggS-tohle-je-zvuk', (string) file_get_contents($target));
    $this->assertSame('/file/recording/aktivita-1', $this->pathOf(0));
    $this->assertSame([
      'accessToken' => self::TOKEN,
      'formats' => 'mp3,ogg,opus,wav',
    ], $this->queryOf(0));
  }

  public function testAnAnswerThatIsNotAudioLeavesNothingBehind(): void {
    // A session that expired answers the login page with 200; writing that as
    // a recording would ship an HTML file to ČSOB as a customer call.
    $client = $this->client([new Response(200, ['content-type' => 'text/html'], '<html>Login</html>')]);
    try {
      $client->download('aktivita-1', $this->dir . '/nahravka.opus');
      $this->fail('Something that is not audio must not be stored as a recording.');
    }
    catch (\RuntimeException $e) {
      $this->assertNoTokenAnywhere($e);
    }
    $this->assertSame([], $this->leftovers());
  }

  public function testAnEmptyRecordingLeavesNothingBehind(): void {
    $client = $this->client([new Response(200, ['content-type' => 'audio/opus'], '')]);
    $this->expectException(\RuntimeException::class);
    try {
      $client->download('aktivita-1', $this->dir . '/nahravka.opus');
    }
    finally {
      $this->assertSame([], $this->leftovers());
    }
  }

  public function testAFailedDownloadLeavesNothingBehind(): void {
    $client = $this->client([new Response(500, [], 'Internal')]);
    $this->expectException(\RuntimeException::class);
    try {
      $client->download('aktivita-1', $this->dir . '/nahravka.opus');
    }
    finally {
      $this->assertSame([], $this->leftovers());
    }
  }

  public function testAnExistingFileIsReplacedAndNotAppendedTo(): void {
    file_put_contents($this->dir . '/nahravka.opus', 'stare-bajty');
    $client = $this->client([new Response(200, ['content-type' => 'audio/mpeg'], 'nove')]);
    $client->download('aktivita-1', $this->dir . '/nahravka.opus');
    $this->assertSame('nove', (string) file_get_contents($this->dir . '/nahravka.opus'));
    $this->assertSame(['nahravka.opus'], $this->leftovers());
  }

  /**
   * @return list<string>
   */
  private function leftovers(): array {
    $found = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
    sort($found);
    return $found;
  }

}
