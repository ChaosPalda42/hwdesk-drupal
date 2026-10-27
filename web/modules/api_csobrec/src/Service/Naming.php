<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

/**
 * File names inside a batch, copied from samples ČSOB accepts today.
 *
 * The asymmetry is deliberate: the MP3 name pads month and day, the batch
 * name does not. The time is always taken from the DateTimeImmutable's own
 * timezone — never converted to UTC or to the site timezone, or a
 * late-evening call would be renamed to the day before.
 */
final class Naming {

  /**
   * Returns the MP3 file name for a call.
   *
   * @param \DateTimeImmutable $time
   *   The call time, in its own timezone.
   * @param string $activity
   *   The activity identifier.
   */
  public function mp3(\DateTimeImmutable $time, string $activity): string {
    return 'DA_' . $time->format('YmdHis') . '_' . $activity . '.mp3';
  }

  /**
   * Returns the batch file name (without extension).
   *
   * Year four digits, month and day without a leading zero, time six digits.
   *
   * @param \DateTimeImmutable $time
   *   The batch time, in its own timezone.
   */
  public function batch(\DateTimeImmutable $time): string {
    return 'DA_' . $time->format('YnjHis');
  }

  /**
   * Returns the XML file name for a batch.
   *
   * @param \DateTimeImmutable $time
   *   The batch time, in its own timezone.
   */
  public function xml(\DateTimeImmutable $time): string {
    return $this->batch($time) . '.xml';
  }

  /**
   * Returns the DON file name for a batch.
   *
   * @param \DateTimeImmutable $time
   *   The batch time, in its own timezone.
   */
  public function don(\DateTimeImmutable $time): string {
    return $this->batch($time) . '.don';
  }

}
