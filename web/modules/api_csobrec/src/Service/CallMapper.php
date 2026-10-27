<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

use Drupal\api_csobrec\Call;
use Drupal\api_csobrec\Item;

/**
 * Turns Daktela's activity shape into a Call, and an Item into an XML line.
 *
 * The only place that knows Daktela's field shapes: campaign record fields
 * arrive as arrays, the phone number hides in item.clid, and the operator may
 * only be on the call record.
 */
final class CallMapper {

  /**
   * Maps a Daktela activity array to a Call.
   *
   * @param array $activity
   *   The raw activity as Daktela delivers it.
   *
   * @throws \InvalidArgumentException
   *   When queue.name or time is missing or unparsable.
   */
  public function fromActivity(array $activity): Call {
    $queue = $activity['queue']['name'] ?? NULL;
    if (!is_string($queue) || $queue === '') {
      throw new \InvalidArgumentException('queue.name is missing.');
    }

    $time = $activity['time'] ?? NULL;
    if (!is_string($time) || $time === '') {
      throw new \InvalidArgumentException('time is missing.');
    }
    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time);
    if ($parsed === FALSE) {
      throw new \InvalidArgumentException('time is not parsable as Y-m-d H:i:s.');
    }

    $disposition = $activity['statuses'][0]['title'] ?? NULL;
    if (!is_string($disposition)) {
      $disposition = NULL;
    }

    $phone = (string) ($activity['item']['clid'] ?? $activity['title'] ?? '');
    $phone = (string) preg_replace('/\D/', '', $phone);

    $operator = $activity['user']['title'] ?? NULL;
    if (!is_string($operator) || $operator === '') {
      $operator = $activity['item']['id_agent']['title'] ?? '';
    }
    if (!is_string($operator)) {
      $operator = '';
    }

    $customFields = $activity['record']['customFields'] ?? [];
    if (!is_array($customFields)) {
      $customFields = [];
    }

    $policyNumber = $this->policyNumber($customFields['csob_cislo_navrhu_ps'] ?? NULL);
    $xSell = $this->firstValue($customFields['_csobp__vyuzit_x_sell_benefit'] ?? NULL) === 'ANO';
    $xSellNumber = $this->policyNumber($customFields['_csobp__x_sell___cislo_navrhu_ps'] ?? NULL);

    return new Call(
      (string) ($activity['name'] ?? ''),
      $queue,
      $parsed,
      $disposition,
      $phone,
      $operator,
      $policyNumber,
      $xSell,
      $xSellNumber,
    );
  }

  /**
   * Maps an Item to one XML line, in the order the XML expects.
   *
   * @param \Drupal\api_csobrec\Item $item
   *   The batch line to export.
   * @param string $file
   *   The recording file name for this line.
   *
   * @return array<string, string>
   *   The line as an ordered map; c_poj_sml only when a policy number exists.
   */
  public function lineFromItem(Item $item, string $file): array {
    $line = [
      'soubor' => $file,
      'prostredi' => 'D',
      'operator' => $item->call->operator,
      'id_kampane' => $item->call->queue,
      'telefon' => $item->call->phone,
      'id_callcentra' => '159',
    ];
    if ($item->policyNumber !== NULL) {
      $line['c_poj_sml'] = $item->policyNumber;
    }
    return $line;
  }

  /**
   * Takes the first non-empty value of a campaign record field.
   *
   * Record fields arrive as arrays, but a plain scalar is accepted too.
   */
  private function firstValue(mixed $value): ?string {
    if (is_array($value)) {
      foreach ($value as $candidate) {
        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
          return trim((string) $candidate);
        }
      }
      return NULL;
    }
    if (is_scalar($value) && trim((string) $value) !== '') {
      return trim((string) $value);
    }
    return NULL;
  }

  /**
   * A contract number only when it holds seven or more digits.
   *
   * Operators type notes into these fields too; anything shorter is not a
   * contract number and must not reach ČSOB as one.
   */
  private function policyNumber(mixed $value): ?string {
    $number = $this->firstValue($value);
    if ($number === NULL) {
      return NULL;
    }
    $digits = (string) preg_replace('/\D/', '', $number);
    return strlen($digits) >= 7 ? $number : NULL;
  }

}
