<?php
declare(strict_types=1);

namespace Drupal\hwdesk\Service;

final class ZplLabel {
    /**
     * Build a ZPL label.
     */
    public function build(string $tag, string $model, string $url, int $copies = 1): string {
        if ($copies < 1) {
            throw new \InvalidArgumentException('Copies must be at least 1');
        }
        // Sanitize tag and model.
        $sanitize = static function(string $text, bool $truncateModel = false): string {
            // Remove ZPL control characters '^' and '~' and ASCII control chars (0x00-0x1F, 0x7F).
            $clean = preg_replace('/[\^~\x00-\x1F\x7F]/u', '', $text);
            if ($clean === null) {
                $clean = '';
            }
            if ($truncateModel) {
                // Truncate to 30 characters, multibyte safe.
                $clean = mb_substr($clean, 0, 30);
            }
            return $clean;
        };
        $tagSafe = $sanitize($tag, false);
        $modelSafe = $sanitize($model, true);

        // Build ZPL lines.
        $lines = [];
        $lines[] = '^XA';
        $lines[] = '^CI28';
        $lines[] = '^PW400';
        $lines[] = '^LL200';
        $lines[] = "^FO16,16^BQN,2,5^FDQA,{$url}^FS";
        $lines[] = "^FO170,30^A0N,40,40^FD{$tagSafe}^FS";
        $lines[] = "^FO170,90^A0N,26,26^FD{$modelSafe}^FS";
        $lines[] = "^PQ{$copies}";
        $lines[] = '^XZ';

        return implode("\n", $lines) . "\n";
    }
}
