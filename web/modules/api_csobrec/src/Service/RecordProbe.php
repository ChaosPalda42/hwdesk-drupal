<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * The probe of one campaign record: record → its activities → recordings.
 *
 * Step 0 of the rebuild agreed on 29 Oct 2026: before the selection is built
 * on campaign records and Record ID, measure on the live instance what the
 * documentation gets wrong. The report is printed on a terminal, so it
 * carries ONLY key paths with types, counts, status codes and the instance's
 * status dictionary — never a value out of a record or an activity, because
 * a value there is a customer's name, number or policy.
 */
final class RecordProbe {

  /**
   * The page size Daktela hands out; a full page is the only reason to ask
   * for the next one.
   */
  private const PAGE_SIZE = 1000;

  /**
   * The formats Daktela can serve a recording in, in the order it prefers.
   */
  private const FORMATS = 'mp3,ogg,opus,wav';

  /**
   * The candidate filter fields for the activities of one record, in the
   * order they are tried: the documentation says 'record', the instance may
   * know only 'record.name', and an unknown field is not refused — it is
   * ignored and the whole instance is returned.
   *
   * @var array<int, string>
   */
  private const CANDIDATES = ['record', 'record.name'];

  public function __construct(
    private readonly ClientInterface $http,
    private readonly CredentialsInterface $credentials,
  ) {
  }

  /**
   * Probes one campaign record and reports its shape.
   *
   * @param string $record
   *   The campaign record ID (the Record ID of the hand-over).
   *
   * @return array{record: array{status: int, keys: array<int, string>}, activities: array{filter: string|null, tried: array<string, string>, total: int, by_type: array<string, int>, keys: array<int, string>}, recordings: array{calls: int, with: int, without: int, failed: int}, statuses: array{status: int, items: array<int, array{name: string, title: string}>}}
   *   The report: shapes and counts only, never a value.
   *
   * @throws \InvalidArgumentException
   *   When the record ID is not a single path segment. The message never
   *   carries the given string.
   * @throws \RuntimeException
   *   When the token is missing, or a request does not answer.
   */
  public function probe(string $record): array {
    if (preg_match('/^[A-Za-z0-9_.-]+$/D', $record) !== 1) {
      // The given string must not ride along: it ends up in the log and on
      // the operator's terminal, and it may be a stranger's record.
      throw new \InvalidArgumentException('The campaign record ID is not a single path segment.');
    }
    $token = $this->credentials->daktelaToken();
    if ($token === '') {
      throw new \RuntimeException('The key has no daktela_token; refusing to send a request without it.');
    }

    $report = [
      'record' => ['status' => 0, 'keys' => []],
      'activities' => ['filter' => NULL, 'tried' => [], 'total' => 0, 'by_type' => [], 'keys' => []],
      'recordings' => ['calls' => 0, 'with' => 0, 'without' => 0, 'failed' => 0],
      'statuses' => ['status' => 0, 'items' => []],
    ];

    // a) The record itself.
    $path = '/api/v6/campaignsRecords/' . $record . '.json';
    $response = $this->get($path, ['accessToken' => $token]);
    $report['record']['status'] = $response->getStatusCode();
    if ($response->getStatusCode() < 400) {
      $body = $this->json($response);
      if (is_array($body['result'] ?? NULL)) {
        $report['record']['keys'] = $this->keyPaths($body['result']);
      }
    }

    // b) and c) The activities of the record and the recordings of its
    // calls; a missing record has nothing to filter on, so both are skipped.
    if ($report['record']['status'] < 400) {
      $rows = $this->activities($token, $record, $report['activities']);
      $this->recordings($token, $rows, $report['recordings']);
    }

    // d) The instance's status dictionary, always last.
    $response = $this->get('/api/v6/statuses.json', ['accessToken' => $token, 'take' => (string) self::PAGE_SIZE]);
    $report['statuses']['status'] = $response->getStatusCode();
    if ($response->getStatusCode() < 400) {
      $body = $this->json($response);
      $data = $body['result']['data'] ?? NULL;
      if (is_array($data)) {
        foreach ($data as $row) {
          if (is_array($row) && is_string($row['name'] ?? NULL) && is_string($row['title'] ?? NULL)) {
            $report['statuses']['items'][] = ['name' => $row['name'], 'title' => $row['title']];
          }
        }
      }
    }
    return $report;
  }

  /**
   * Tries the candidate filter fields until one of them really filters.
   *
   * @param string $token
   *   The access token; it travels in the query string, never in a header.
   * @param string $record
   *   The campaign record ID the rows must belong to.
   * @param array{filter: string|null, tried: array<string, string>, total: int, by_type: array<string, int>, keys: array<int, string>} $activities
   *   The report part filled in here, in the order the candidates were asked.
   *
   * @return array<int, array<string, mixed>>
   *   The rows of the first successful candidate; [] when none worked.
   */
  private function activities(string $token, string $record, array &$activities): array {
    foreach (self::CANDIDATES as $candidate) {
      $rows = [];
      $skip = 0;
      $outcome = '';
      $success = FALSE;
      while (TRUE) {
        $query = [
          'accessToken' => $token,
          'take' => (string) self::PAGE_SIZE,
          'skip' => (string) $skip,
          'sort[0][field]' => 'time',
          'sort[0][dir]' => 'asc',
          'filter[logic]' => 'and',
          'filter[filters][0][field]' => $candidate,
          'filter[filters][0][operator]' => 'eq',
          'filter[filters][0][value]' => $record,
        ];
        $response = $this->get('/api/v6/activities.json', $query);
        $status = $response->getStatusCode();
        $body = $this->json($response);
        $data = $body['result']['data'] ?? NULL;
        if ($status >= 400 || !is_array($data)) {
          $outcome = 'http ' . $status;
          break;
        }
        if (count($data) === 0) {
          $outcome = '0 rows';
          break;
        }
        $foreign = 0;
        foreach ($data as $row) {
          if (!$this->belongs($row, $record)) {
            $foreign++;
          }
        }
        if ($foreign > 0) {
          // Daktela ignores an unknown filter field and returns the whole
          // instance: strangers' rows are the sign, and this is NOT a
          // success, so no further page is asked.
          $outcome = sprintf('filter ignored (%d of %d rows foreign)', $foreign, count($data));
          break;
        }
        $rows = array_merge($rows, $data);
        $total = $body['result']['total'] ?? count($rows);
        // A full page is the only reason to ask again, and only while the
        // collected rows are fewer than the total.
        if (count($data) < self::PAGE_SIZE || count($rows) >= (int) $total) {
          $outcome = count($rows) . ' rows';
          $success = TRUE;
          break;
        }
        $skip += self::PAGE_SIZE;
      }
      $activities['tried'][$candidate] = $outcome;
      if (!$success) {
        continue;
      }
      $activities['filter'] = $candidate;
      $activities['total'] = count($rows);
      $byType = [];
      $keys = [];
      foreach ($rows as $row) {
        if (is_array($row)) {
          if (is_string($row['type'] ?? NULL)) {
            $byType[$row['type']] = ($byType[$row['type']] ?? 0) + 1;
          }
          $keys = array_merge($keys, $this->keyPaths($row));
        }
      }
      ksort($byType);
      $activities['by_type'] = $byType;
      $unique = array_unique($keys, SORT_STRING);
      sort($unique, SORT_STRING);
      $activities['keys'] = $unique;
      return $rows;
    }
    return [];
  }

  /**
   * Whether one activity row belongs to the record.
   *
   * The row carries the record either as the plain ID or as an embedded
   * object with a 'name' — both shapes have been measured on the instance.
   *
   * @param mixed $row
   *   One row of result.data.
   * @param string $record
   *   The campaign record ID.
   */
  private function belongs(mixed $row, string $record): bool {
    if (!is_array($row)) {
      return FALSE;
    }
    $recordField = $row['record'] ?? NULL;
    if ($recordField === $record) {
      return TRUE;
    }
    return is_array($recordField) && ($recordField['name'] ?? NULL) === $record;
  }

  /**
   * Asks for the recording of every CALL row, in row order.
   *
   * @param string $token
   *   The access token.
   * @param array<int, array<string, mixed>> $rows
   *   The rows of the successful candidate.
   * @param array{calls: int, with: int, without: int, failed: int} $recordings
   *   The report part filled in here.
   */
  private function recordings(string $token, array $rows, array &$recordings): void {
    foreach ($rows as $row) {
      if (!is_array($row) || ($row['type'] ?? NULL) !== 'CALL') {
        continue;
      }
      $recordings['calls']++;
      $name = $row['name'];
      if (!is_string($name) || preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1) {
        // A name that could not be a path segment is never sent: it would
        // escape the /file/recording/ prefix.
        $recordings['failed']++;
        continue;
      }
      $path = '/file/recording/' . $name;
      $response = $this->get($path, [
        'accessToken' => $token,
        'formats' => self::FORMATS,
        'exist' => 'true',
      ]);
      $status = $response->getStatusCode();
      if ($status === 404) {
        $recordings['without']++;
        continue;
      }
      if ($status >= 400) {
        $recordings['failed']++;
        continue;
      }
      $body = $this->json($response);
      if (is_string($body['recording'] ?? NULL) && $body['recording'] !== '') {
        $recordings['with']++;
        continue;
      }
      // A naked false, non-JSON or the login page as HTML: no recording.
      $recordings['without']++;
    }
  }

  /**
   * Sends one GET and replaces any transport failure with an exception that
   * names only the path.
   *
   * Guzzle's own exceptions put the full URL — token included — into their
   * message, and that message would end up in the log and in an operator's
   * terminal. This exception names only the path and it has no previous, so
   * nothing from the request can follow it.
   *
   * @param string $path
   *   The path that is asked, without host and query.
   * @param array<string, string> $query
   *   The query parameters, token included.
   *
   * @throws \RuntimeException
   *   When the request does not answer at all.
   */
  private function get(string $path, array $query): ResponseInterface {
    try {
      return $this->http->request('GET', $this->credentials->daktelaUrl() . $path, [
        'query' => $query,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException) {
      throw new \RuntimeException('Daktela did not answer on ' . $path . '.');
    }
  }

  /**
   * Decodes a JSON body; [] when the body is not a JSON object.
   */
  private function json(ResponseInterface $response): array {
    $body = json_decode((string) $response->getBody(), TRUE);
    return is_array($body) ? $body : [];
  }

  /**
   * The sorted, unique list of '<path>:<type>' shapes of one value.
   *
   * Arrays are walked: an associative key adds '.<key>', a list adds '[]'
   * and every element is merged. Leaf types are string, int, float, bool,
   * null; an empty array is ':empty'. The depth counts only the dot-
   * separated key segments ('[]' does not count): a non-empty array whose
   * path already has four key segments is not walked but reported as
   * ':deep'. The values themselves never appear in the output.
   *
   * @param mixed $value
   *   The value to walk.
   *
   * @return array<int, string>
   *   The sorted, unique key paths with types.
   */
  private function keyPaths(mixed $value): array {
    $paths = [];
    $this->walk($value, '', $paths);
    $paths = array_unique($paths, SORT_STRING);
    sort($paths, SORT_STRING);
    return $paths;
  }

  /**
   * Walks one value and collects its key paths.
   *
   * @param mixed $value
   *   The value to walk.
   * @param string $path
   *   The path so far, '' at the top level.
   * @param array<int, string> $paths
   *   The collected paths, filled in here.
   */
  private function walk(mixed $value, string $path, array &$paths): void {
    if (is_array($value)) {
      if ($value === []) {
        $paths[] = $path . ':empty';
        return;
      }
      $keySegments = $path === '' ? 0 : count(explode('.', $path));
      if ($keySegments >= 4) {
        $paths[] = $path . ':deep';
        return;
      }
      if (array_is_list($value)) {
        foreach ($value as $element) {
          $this->walk($element, $path . '[]', $paths);
        }
        return;
      }
      foreach ($value as $key => $element) {
        $this->walk($element, $path === '' ? (string) $key : $path . '.' . $key, $paths);
      }
      return;
    }
    $type = match (TRUE) {
      is_string($value) => 'string',
      is_int($value) => 'int',
      is_float($value) => 'float',
      is_bool($value) => 'bool',
      $value === NULL => 'null',
      default => 'other',
    };
    $paths[] = $path . ':' . $type;
  }

}
