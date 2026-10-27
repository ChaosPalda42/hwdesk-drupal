<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

/**
 * Builds the batch index XML, byte for byte as ČSOB accepts it.
 *
 * Their importer rejects anything prettier: CRLF after every line including
 * the last one, tab indentation, a fixed field order inside <dokument>.
 * DOMDocument and XMLWriter are deliberately not used — they emit LF and
 * their own formatting, which the importer refuses.
 */
final class IndexXml {

  /**
   * The fixed field order inside <dokument>.
   *
   * @var list<string>
   */
  private const FIELDS = [
    'soubor',
    'prostredi',
    'operator',
    'id_kampane',
    'telefon',
    'id_callcentra',
    'c_poj_sml',
  ];

  /**
   * The fields that must be present in every line.
   *
   * @var list<string>
   */
  private const MANDATORY = [
    'soubor',
    'prostredi',
    'operator',
    'id_kampane',
    'telefon',
    'id_callcentra',
  ];

  /**
   * Builds the whole document as bytes in a PHP string.
   *
   * @param array<array<string, string>> $lines
   *   One entry per recorded call; keys are the field names.
   * @param int $priorityCm
   *   The priority written into <prioritaCm>.
   *
   * @throws \InvalidArgumentException
   *   When a line carries an unknown key or misses a mandatory one. The
   *   message names the keys only, never the values — the operator name is
   *   personal data.
   */
  public function build(array $lines, int $priorityCm = 50): string {
    $out = '<?xml version="1.0" encoding="utf-8"?>' . "\r\n";
    $out .= "<root>\r\n";
    $out .= "\t<vlastnosti>\r\n";
    $out .= "\t\t<prioritaCm>" . $this->escape((string) $priorityCm) . "</prioritaCm>\r\n";
    $out .= "\t</vlastnosti>\r\n";
    $out .= "\t<dokumenty>\r\n";
    foreach ($lines as $line) {
      $this->validate($line);
      $out .= "\t\t<dokument>\r\n";
      foreach (self::FIELDS as $field) {
        if ($field === 'c_poj_sml' && !isset($line[$field])) {
          continue;
        }
        $out .= "\t\t\t<{$field}>" . $this->escape($line[$field]) . "</{$field}>\r\n";
      }
      $out .= "\t\t</dokument>\r\n";
    }
    $out .= "\t</dokumenty>\r\n";
    $out .= "</root>\r\n";
    return $out;
  }

  /**
   * Checks a line against the fixed field set.
   *
   * @param array<string, string> $line
   */
  private function validate(array $line): void {
    $unknown = array_diff(array_keys($line), self::FIELDS);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Unknown field(s): ' . implode(', ', $unknown));
    }
    $missing = array_diff(self::MANDATORY, array_keys($line));
    if ($missing !== []) {
      throw new \InvalidArgumentException('Missing mandatory field(s): ' . implode(', ', $missing));
    }
  }

  /**
   * Escapes the XML markup characters in a value.
   */
  private function escape(string $value): string {
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
  }

}
