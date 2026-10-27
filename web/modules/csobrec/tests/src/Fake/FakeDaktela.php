<?php

declare(strict_types=1);

namespace Drupal\Tests\csobrec\Fake;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A Daktela instance that answers out of a fixture instead of over the network.
 *
 * It answers by URL rather than in a fixed order, so a test says WHAT the
 * instance holds and not in which order the run is allowed to ask. The request
 * log is there for the questions that do matter: was the history of a number
 * asked once or five times, was an already-sent recording downloaded again.
 */
final class FakeDaktela {

  /**
   * Every request that arrived: ['path' => string, 'query' => array].
   *
   * @var list<array{path: string, query: array<string, string>}>
   */
  public array $log = [];

  /**
   * Activities by queue.
   *
   * @var array<string, list<array<string, mixed>>>
   */
  private array $byQueue = [];

  /**
   * Audio bytes by activity; an activity missing here has no recording.
   *
   * @var array<string, string>
   */
  private array $audio = [];

  /**
   * Activities whose download answers with this HTTP status instead of audio.
   *
   * @var array<string, int>
   */
  private array $brokenDownloads = [];

  /**
   * Adds one activity, Daktela-shaped.
   *
   * @param array<string, mixed> $extra
   *   Keys to add or override, e.g. statuses or record.customFields.
   */
  public function addCall(string $queue, string $activity, string $time, string $phone, array $extra = [], ?string $audio = 'OggS-zvuk'): void {
    $this->byQueue[$queue][] = $extra + [
      'name' => $activity,
      'queue' => ['name' => $queue],
      'time' => $time,
      'statuses' => [],
      'item' => ['clid' => $phone],
      'user' => ['title' => 'Jan Novák'],
      'record' => ['customFields' => []],
    ];
    if ($audio !== NULL) {
      $this->audio[$activity] = $audio . '-' . $activity;
    }
  }

  /**
   * Adds something the mapper cannot read — a live instance has those.
   *
   * @param array<string, mixed> $activity
   */
  public function addRawCall(string $queue, array $activity): void {
    $this->byQueue[$queue][] = $activity;
  }

  /**
   * Makes one download fail with an HTTP status.
   */
  public function breakDownload(string $activity, int $status = 500): void {
    $this->brokenDownloads[$activity] = $status;
  }

  /**
   * How many times a path was asked for.
   */
  public function asked(string $path): int {
    return count(array_filter($this->log, static fn (array $r): bool => $r['path'] === $path));
  }

  /**
   * The activities whose audio was actually fetched.
   *
   * @return list<string>
   */
  public function downloaded(): array {
    $found = [];
    foreach ($this->log as $request) {
      if (str_starts_with($request['path'], '/file/recording/') && !isset($request['query']['exist'])) {
        $found[] = substr($request['path'], strlen('/file/recording/'));
      }
    }
    sort($found);
    return $found;
  }

  /**
   * A Guzzle client that talks to this fixture.
   */
  public function client(): Client {
    return new Client(['handler' => $this->handler()]);
  }

  private function handler(): callable {
    return function (RequestInterface $request, array $options) {
      $path = $request->getUri()->getPath();
      $query = array_map(static fn ($v): string => (string) $v, Query::parse($request->getUri()->getQuery()));
      $this->log[] = ['path' => $path, 'query' => $query];

      if ($path === '/api/v6/activities.json') {
        return Create::promiseFor($this->activities($query));
      }
      if (str_starts_with($path, '/file/recording/')) {
        return Create::promiseFor($this->recording(substr($path, strlen('/file/recording/')), isset($query['exist'])));
      }
      return Create::promiseFor(new Response(404, [], 'Not found'));
    };
  }

  /**
   * @param array<string, string> $query
   */
  private function activities(array $query): Response {
    $filters = [];
    for ($i = 0; isset($query["filter[filters][{$i}][field]"]); $i++) {
      $filters[$query["filter[filters][{$i}][field]"]] = [
        'operator' => $query["filter[filters][{$i}][operator]"] ?? '',
        'value' => $query["filter[filters][{$i}][value]"] ?? '',
      ];
    }
    $queue = $filters['queue']['value'] ?? '';
    $rows = $this->byQueue[$queue] ?? [];

    if (isset($filters['item.clid'])) {
      // The history of one number: no time window.
      $digits = $filters['item.clid']['value'];
      $rows = array_values(array_filter($rows, static function (array $row) use ($digits): bool {
        $clid = preg_replace('/\D/', '', (string) ($row['item']['clid'] ?? '')) ?? '';
        return $digits !== '' && str_contains($clid, $digits);
      }));
    }
    else {
      $from = $filters['time']['value'] ?? '';
      $rows = array_values(array_filter($rows, static function (array $row) use ($from): bool {
        return $from === '' || substr((string) ($row['time'] ?? ''), 0, 10) === substr($from, 0, 10);
      }));
    }

    return new Response(200, ['content-type' => 'application/json'], (string) json_encode([
      'result' => ['total' => count($rows), 'data' => $rows],
    ]));
  }

  private function recording(string $activity, bool $existOnly): Response {
    if (!isset($this->audio[$activity])) {
      // Measured on the live instance: a naked JSON false, not a 404.
      return new Response(200, ['content-type' => 'application/json'], 'false');
    }
    if ($existOnly) {
      return new Response(200, ['content-type' => 'application/json'], (string) json_encode([
        'recording' => $activity . '.opus',
        'encrypt' => FALSE,
        'format' => 'opus',
      ]));
    }
    if (isset($this->brokenDownloads[$activity])) {
      return new Response($this->brokenDownloads[$activity], [], 'Internal');
    }
    return new Response(200, ['content-type' => 'audio/opus'], $this->audio[$activity]);
  }

}
