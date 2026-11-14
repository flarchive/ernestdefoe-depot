<?php

namespace ErnestDefoe\Depot\Service;

/**
 * Release notes into Added / Changed / Fixed.
 *
 * Understands the Keep a Changelog headings people already write ("### Added",
 * "## Fixed", "Bug fixes", "Features"), and otherwise files every bullet under
 * Changed — a changelog with one group is still a changelog; one that guessed
 * wrong is worse than none.
 */
class Changelog
{
    private const GROUPS = [
        'added' => '/^(added|new|features?|enhancements?)\b/i',
        'fixed' => '/^(fixed|fix(es)?|bug ?fixes|bugs)\b/i',
        'changed' => '/^(changed|changes|improved|improvements|updated|removed|deprecated|security)\b/i',
    ];

    /** @return array{added: string[], changed: string[], fixed: string[]} */
    public static function parse(string $text): array
    {
        $out = ['added' => [], 'changed' => [], 'fixed' => []];
        $group = 'changed';

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^#{1,6}\s*(.+)$/', $line, $m) || preg_match('/^\*\*(.+)\*\*:?$/', $line, $m)) {
                foreach (self::GROUPS as $key => $re) {
                    if (preg_match($re, trim($m[1]))) {
                        $group = $key;
                        break;
                    }
                }
                continue;
            }

            if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) {
                $item = trim(preg_replace('/\s+\(?(#\d+|[0-9a-f]{7,40})\)?$/', '', $m[1]) ?? $m[1]);
                if ($item !== '' && count($out[$group]) < 30) {
                    $out[$group][] = mb_substr($item, 0, 300);
                }
            }
        }

        return $out;
    }

    /** One item per line, as an uploader types it. @return string[] */
    public static function lines(?string $text, int $max = 30, int $length = 300): array
    {
        $lines = array_filter(array_map(fn ($l) => mb_substr(trim(ltrim(trim($l), '-*•')), 0, $length), preg_split('/\R/', (string) $text) ?: []), fn ($l) => $l !== '');

        return array_slice(array_values($lines), 0, $max);
    }
}
