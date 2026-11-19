<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

/** HTTP entity-tag conditions. Hashes identify bytes, not edit generations. */
final class Conditions {
    /** @return list<array{weak:bool,value:string}>|string */
    public static function parse(string $value): array|string {
        $value = trim($value);
        if ($value === '*') return '*';
        if ($value === '' || strlen($value) > 8192) throw new \InvalidArgumentException('Invalid entity-tag condition');
        $tags = [];
        $offset = 0;
        while ($offset < strlen($value)) {
            if (!preg_match('/\G[ \t]*(W\/)?"([\x21\x23-\x7e\x80-\xff]*)"[ \t]*(,|$)/', $value, $match, 0, $offset)) {
                throw new \InvalidArgumentException('Invalid entity-tag condition');
            }
            $tags[] = ['weak' => $match[1] !== '', 'value' => '"' . $match[2] . '"'];
            $offset += strlen($match[0]);
            if ($match[3] === ',' && $offset === strlen($value)) throw new \InvalidArgumentException('Invalid entity-tag list');
            if (count($tags) > 128) throw new \InvalidArgumentException('Too many entity tags');
        }
        return $tags;
    }

    public static function matches(string $header, bool $exists, ?string $etag, bool $strong): bool {
        $parsed = self::parse($header);
        if ($parsed === '*') return $exists;
        if (!$exists || $etag === null) return false;
        foreach ($parsed as $tag) {
            if ((!$strong || !$tag['weak']) && hash_equals($tag['value'], $etag)) return true;
        }
        return false;
    }

    /** RFC precedence: If-Match suppresses If-Unmodified-Since. */
    public static function permits(?string $match, ?string $noneMatch, ?string $unmodified, bool $exists, ?string $etag, ?int $mtime): bool {
        if ($match !== null && !self::matches($match, $exists, $etag, true)) return false;
        if ($match === null && $unmodified !== null && $exists && $mtime !== null) {
            $date = self::httpDate($unmodified);
            if ($date !== null && $mtime > $date) return false;
        }
        return $noneMatch === null || !self::matches($noneMatch, $exists, $etag, false);
    }

    /** Accept HTTP-date forms, not PHP's relative-date language (e.g. "tomorrow"). */
    public static function httpDate(string $value): ?int {
        if (!preg_match('/^(?:[A-Z][a-z]{2}, [0-9]{2} [A-Z][a-z]{2} [0-9]{4} [0-9:]{8} GMT|[A-Z][a-z]+, [0-9]{2}-[A-Z][a-z]{2}-[0-9]{2} [0-9:]{8} GMT|[A-Z][a-z]{2} [A-Z][a-z]{2} [ 0-9][0-9] [0-9:]{8} [0-9]{4})$/D', $value)) return null;
        $date = strtotime($value . (str_ends_with($value, 'GMT') ? '' : ' GMT'));
        return $date === false ? null : $date;
    }
}
