<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The client to Daktela: calls of a day, a number's history, recordings.
 *
 * Every address and parameter here was measured on the live instance on
 * 27 Oct 2026, and three of them cost hours: `sort=time asc` answers 500 to
 * everything (only sort[0][field] works), a query without a queue returns
 * thousands of other clients' calls, and /api/v6/activities/<id>/record
 * answers 403 — the recording lives at /file/recording/<id>.
 *
 * The rule that must never break: the token travels in the query string
 * (in a header it answers 401), so nothing may ever put a request URL into
 * an exception or a log. Guzzle does exactly that on its own unless
 * http_errors is switched off, which is why every request here is checked
 * by hand and every failure is reported as a fresh exception naming only
 * the path and the status code.
 */
final class DaktelaClient {

  /**
   * The page size Daktela hands out; a full page is the only reason to ask
   * for the next one.
   */
  private const PAGE_SIZE = 1000;

  /**
   * The formats Daktela can serve a recording in, in the order it prefers.
   */
  private const FORMATS = 'mp3,ogg,opus,wav';

  public function __construct(
    private readonly ClientInterface $http,
    private readonly CredentialsInterface $credentials,
  ) {
  }

  /**
   * The calls of one day, merged over the given queues and sorted by time.
   *
   * A query across the whole instance returns thousands of other clients'
   * calls, so the day is asked once PER QUEUE and merged here.
   *
   * @param \DateTimeImmutable $day
   *   Any moment of the day; only its date matters.
   * @param array<int, string> $queues
   *   The queue IDs to collect. No queues means no request and no calls.
   *
   * @return array<int, array<string, mixed>>
   *   The merged calls, sorted by the 'time' field ascending.
   *
   * @throws \RuntimeException
   *   When the token is missing, the request fails, or the answer is not
   *   the expected JSON.
   */
  public function callsOfDay(\DateTimeImmutable $day, array $queues): array {
    $token = $this->token();
    if ($queues === []) {
      return [];
    }
    $date = $day->format('Y-m-d');
    $calls = [];
    foreach ($queues as $queue) {
      $calls = array_merge($calls, $this->activities($token, [
        ['field' => 'type', 'operator' => 'eq', 'value' => 'CALL'],
        ['field' => 'time', 'operator' => 'gte', 'value' => $date . ' 00:00:00'],
        ['field' => 'time', 'operator' => 'lte', 'value' => $date . ' 23:59:59'],
        ['field' => 'queue', 'operator' => 'eq', 'value' => $queue],
      ]));
    }
    usort($calls, static fn (array $a, array $b): int => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
    return $calls;
  }

  /**
   * The whole history of one number on one queue, no time filter.
   *
   * @param string $phone
   *   The number in any human shape; only the last nine digits are used.
   * @param string $queue
   *   The queue ID. Without a queue the query reaches across the whole
   *   instance, so an empty queue is refused.
   *
   * @return array<int, array<string, mixed>>
   *   The calls, sorted by the 'time' field ascending.
   *
   * @throws \InvalidArgumentException
   *   When the queue is empty, or the number is too short or carries a
   *   prefix that is not '', '420' or '00420' — 'contains' on a shorter
   *   string would match strangers' numbers and we would download a
   *   stranger's recording.
   * @throws \RuntimeException
   *   When the token is missing, the request fails, or the answer is not
   *   the expected JSON.
   */
  public function callsOfNumber(string $phone, string $queue): array {
    if ($queue === '') {
      throw new \InvalidArgumentException('The queue is required: without it the query reaches across the whole instance.');
    }
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($digits) < 9) {
      // The number itself must not travel into the message: it ends up in the
      // log and on the operator's terminal, and it is a customer's.
      throw new \InvalidArgumentException(sprintf('A number of %d digits is too short to ask about; a shorter match would hit other people.', strlen($digits)));
    }
    $prefix = substr($digits, 0, -9);
    if (!in_array($prefix, ['', '420', '00420'], TRUE)) {
      throw new \InvalidArgumentException(sprintf('A number with %d digits before the last nine does not look Czech: that part must be empty, 420 or 00420.', strlen($prefix)));
    }
    $token = $this->token();
    $calls = $this->activities($token, [
      ['field' => 'type', 'operator' => 'eq', 'value' => 'CALL'],
      ['field' => 'item.clid', 'operator' => 'contains', 'value' => substr($digits, -9)],
      ['field' => 'queue', 'operator' => 'eq', 'value' => $queue],
    ]);
    usort($calls, static fn (array $a, array $b): int => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
    return $calls;
  }

  /**
   * Whether a call has a recording, and in which format.
   *
   * @param string $activity
   *   The activity ID from the calls of a day.
   *
   * @return array<string, mixed>|null
   *   The {recording, encrypt, format} object, or NULL when there is no
   *   recording (a 404, a naked JSON false, or an object without a
   *   non-empty 'recording').
   *
   * @throws \RuntimeException
   *   When the token is missing or the request fails with any other status
   *   — returning NULL there would quietly drop a call out of the batch.
   */
  public function recording(string $activity): ?array {
    $token = $this->token();
    $path = '/file/recording/' . $activity;
    $response = $this->http->request('GET', $this->base() . $path, [
      'query' => [
        'accessToken' => $token,
        'formats' => self::FORMATS,
        'exist' => 'true',
      ],
      'http_errors' => FALSE,
    ]);
    if ($response->getStatusCode() === 404) {
      return NULL;
    }
    if ($response->getStatusCode() >= 400) {
      $this->fail($path, $response->getStatusCode());
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($body) || !is_string($body['recording'] ?? NULL) || $body['recording'] === '') {
      return NULL;
    }
    return $body;
  }

  /**
   * Downloads one recording to the given file.
   *
   * The bytes go to a temporary file next to the target and are moved into
   * place only after the answer checks out: a failing status, an empty body
   * or a content-type outside audio/* are all refused, and in every failure
   * case no file — neither the target nor the temporary one — is left
   * behind. An expired session answers the login page with HTTP 200, and
   * that HTML must never travel to ČSOB as a customer call.
   *
   * @param string $activity
   *   The activity ID from the calls of a day.
   * @param string $target
   *   Where the recording ends up, replacing whatever was there.
   *
   * @return string
   *   The target path.
   *
   * @throws \RuntimeException
   *   When the token is missing, the request fails, or the body is not
   *   audio.
   */
  public function download(string $activity, string $target): string {
    $token = $this->token();
    $path = '/file/recording/' . $activity;
    $response = $this->http->request('GET', $this->base() . $path, [
      'query' => [
        'accessToken' => $token,
        'formats' => self::FORMATS,
      ],
      'http_errors' => FALSE,
    ]);
    $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
    try {
      if ($response->getStatusCode() >= 400) {
        $this->fail($path, $response->getStatusCode());
      }
      $body = (string) $response->getBody();
      if ($body === '') {
        $this->fail($path, $response->getStatusCode());
      }
      $contentType = (string) $response->getHeaderLine('content-type');
      if (!str_starts_with($contentType, 'audio/')) {
        $this->fail($path, $response->getStatusCode());
      }
      if (file_put_contents($temporary, $body) === FALSE) {
        $this->fail($path, $response->getStatusCode());
      }
      if (!rename($temporary, $target)) {
        $this->fail($path, $response->getStatusCode());
      }
    }
    finally {
      if (is_file($temporary)) {
        @unlink($temporary);
      }
    }
    return $target;
  }

  /**
   * Pages through /api/v6/activities.json until the page is short.
   *
   * @param string $token
   *   The access token; it travels in the query string, never in a header.
   * @param array<int, array{field: string, operator: string, value: string}> $filters
   *   The filters, in the order they must appear in the query.
   *
   * @return array<int, array<string, mixed>>
   *   All the rows collected.
   *
   * @throws \RuntimeException
   *   When a request fails or the answer is not the expected JSON.
   */
  private function activities(string $token, array $filters): array {
    $path = '/api/v6/activities.json';
    $rows = [];
    $skip = 0;
    while (TRUE) {
      $query = [
        'accessToken' => $token,
        'take' => (string) self::PAGE_SIZE,
        'skip' => (string) $skip,
        'sort[0][field]' => 'time',
        'sort[0][dir]' => 'asc',
        'filter[logic]' => 'and',
      ];
      foreach ($filters as $i => $filter) {
        $query['filter[filters][' . $i . '][field]'] = $filter['field'];
        $query['filter[filters][' . $i . '][operator]'] = $filter['operator'];
        $query['filter[filters][' . $i . '][value]'] = $filter['value'];
      }
      $response = $this->http->request('GET', $this->base() . $path, [
        'query' => $query,
        'http_errors' => FALSE,
      ]);
      if ($response->getStatusCode() >= 400) {
        $this->fail($path, $response->getStatusCode());
      }
      $body = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($body) || !isset($body['result']) || !is_array($body['result']) || !isset($body['result']['data']) || !is_array($body['result']['data'])) {
        $this->fail($path, $response->getStatusCode());
      }
      $page = $body['result']['data'];
      $rows = array_merge($rows, $page);
      $total = $body['result']['total'] ?? count($rows);
      // A full page is the only reason to ask again, and only while the
      // collected rows are fewer than the total: measured, the total can be
      // larger than what the instance will hand out, and asking forever
      // would hammer their server for nothing.
      if (count($page) < self::PAGE_SIZE || count($rows) >= (int) $total) {
        break;
      }
      $skip += self::PAGE_SIZE;
    }
    return $rows;
  }

  /**
   * The base URL of the instance, without a trailing slash.
   */
  private function base(): string {
    return $this->credentials->daktelaUrl();
  }

  /**
   * The access token out of the key, refusing an empty one.
   *
   * @throws \RuntimeException
   *   When the key has no token; nothing may be sent without it. The message
   *   names the field, never what is or is not in it.
   */
  private function token(): string {
    $token = $this->credentials->daktelaToken();
    if ($token === '') {
      throw new \RuntimeException('The key has no daktela_token; refusing to send a request without it.');
    }
    return $token;
  }

  /**
   * Reports a failed request without ever naming the URL.
   *
   * Guzzle's own exceptions put the full URL — token included — into their
   * message, and that message would end up in the log and in an operator's
   * terminal. This exception names only the path and the status code, and
   * it has no previous, so nothing from the request can follow it.
   *
   * @param string $path
   *   The path that was asked, without host and query.
   * @param int $status
   *   The status code the answer carried.
   *
   * @throws \RuntimeException
   *   Always.
   */
  private function fail(string $path, int $status): never {
    throw new \RuntimeException(sprintf('Daktela answered %d on %s.', $status, $path));
  }

}
