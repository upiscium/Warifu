<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

/** Bounded parser for the entity-tag subset of RFC 4918 If. */
final class DavIf {
    /** @return list<array{uri:?string,lists:list<list<array{not:bool,etag:string}>>}> */
    public static function parse(string $header): array {
        $length = strlen($header);
        if ($length === 0 || $length > 8192) throw new \InvalidArgumentException('Invalid DAV If header');
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($header[$i]);
            if (($ord < 0x20 && $ord !== 0x09) || $ord === 0x7f) throw new \InvalidArgumentException('Invalid DAV If header');
        }
        $i = 0;
        $groups = [];
        $listsCount = 0;
        $conditionsCount = 0;
        $mode = null;
        $unsupported = false;
        while (true) {
            self::spaces($header, $i);
            if ($i >= $length) break;
            if (count($groups) >= 8) throw new \InvalidArgumentException('Invalid DAV If header');
            if ($header[$i] === '<') {
                if ($mode === 'untagged') throw new \InvalidArgumentException('Invalid DAV If header');
                $mode = 'tagged';
                $uri = self::uri($header, $i);
                $lists = [];
                while (true) {
                    self::spaces($header, $i);
                    if ($i < $length && $header[$i] === '(') $lists[] = self::conditionList($header, $i, $listsCount, $conditionsCount, $unsupported);
                    else break;
                }
                if ($lists === []) throw new \InvalidArgumentException('Invalid DAV If header');
                $groups[] = ['uri' => $uri, 'lists' => $lists];
            } elseif ($header[$i] === '(') {
                if ($mode === 'tagged') throw new \InvalidArgumentException('Invalid DAV If header');
                $mode = 'untagged';
                $lists = [];
                while (true) {
                    self::spaces($header, $i);
                    if ($i < $length && $header[$i] === '(') $lists[] = self::conditionList($header, $i, $listsCount, $conditionsCount, $unsupported);
                    else break;
                }
                if ($lists === []) throw new \InvalidArgumentException('Invalid DAV If header');
                $groups[] = ['uri' => null, 'lists' => $lists];
            } else {
                throw new \InvalidArgumentException('Invalid DAV If header');
            }
        }
        if ($groups === []) throw new \InvalidArgumentException('Invalid DAV If header');
        if ($unsupported) throw new \DomainException('DAV If lock tokens are unsupported');
        return $groups;
    }

    private static function spaces(string $s, int &$i): void {
        $n = strlen($s);
        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t")) $i++;
    }

    private static function uri(string $s, int &$i): string {
        $n = strlen($s); $start = ++$i;
        while ($i < $n && $s[$i] !== '>') {
            $c = ord($s[$i]);
            if ($c <= 0x20 || $c === 0x7f || $s[$i] === '<') throw new \InvalidArgumentException('Invalid DAV If header');
            $i++;
        }
        if ($i >= $n || $i === $start || $i - $start > 2048) throw new \InvalidArgumentException('Invalid DAV If header');
        $uri = substr($s, $start, $i - $start); $i++;
        return $uri;
    }

    /** @return list<array{not:bool,etag:string}> */
    private static function conditionList(string $s, int &$i, int &$lists, int &$conditions, bool &$unsupported): array {
        if (++$lists > 32) throw new \InvalidArgumentException('Invalid DAV If header');
        $i++; $n = strlen($s); $result = []; $initialConditions = $conditions;
        while (true) {
            self::spaces($s, $i);
            if ($i >= $n) throw new \InvalidArgumentException('Invalid DAV If header');
            if ($s[$i] === ')') { $i++; break; }
            $not = false;
            if (substr($s, $i, 3) === 'Not' && ($i + 3 === $n || $s[$i + 3] === ' ' || $s[$i + 3] === "\t")) {
                $not = true; $i += 3;
                if ($i >= $n || ($s[$i] !== ' ' && $s[$i] !== "\t")) throw new \InvalidArgumentException('Invalid DAV If header');
                self::spaces($s, $i);
                if ($i >= $n) throw new \InvalidArgumentException('Invalid DAV If header');
            }
            if ($s[$i] === '<') {
                $start = ++$i;
                while ($i < $n && $s[$i] !== '>') {
                    $ord = ord($s[$i]);
                    if ($ord <= 0x20 || $ord === 0x7f || $s[$i] === '<') throw new \InvalidArgumentException('Invalid DAV If header');
                    $i++;
                }
                if ($i >= $n || $i === $start || $i - $start > 2048) throw new \InvalidArgumentException('Invalid DAV If header');
                $i++;
                if ($i < $n && $s[$i] !== ')' && $s[$i] !== ' ' && $s[$i] !== "\t") throw new \InvalidArgumentException('Invalid DAV If header');
                if (++$conditions > 128) throw new \InvalidArgumentException('Invalid DAV If header');
                $unsupported = true;
                continue;
            }
            if ($s[$i] !== '[') throw new \InvalidArgumentException('Invalid DAV If header');
            $i++; $start = $i; $nq = strlen($s);
            while ($i < $nq) {
                if ($s[$i] === '"') {
                    $i++;
                    while ($i < $nq) {
                        if ($s[$i] === '"') { $i++; break; }
                        $i++;
                    }
                } elseif ($s[$i] === ']') break;
                else $i++;
            }
            if ($i >= $nq || $s[$i] !== ']') throw new \InvalidArgumentException('Invalid DAV If header');
            $raw = substr($s, $start, $i - $start); $i++;
            try { $parsed = Conditions::parse($raw); } catch (\InvalidArgumentException) { throw new \InvalidArgumentException('Invalid DAV If header'); }
            if (!is_array($parsed) || count($parsed) !== 1) throw new \InvalidArgumentException('Invalid DAV If header');
            $tag = $parsed[0];
            $result[] = ['not' => $not, 'etag' => ($tag['weak'] ? 'W/' : '') . $tag['value']];
            if (++$conditions > 128) throw new \InvalidArgumentException('Invalid DAV If header');
        }
        if ($conditions === $initialConditions) throw new \InvalidArgumentException('Invalid DAV If header');
        return $result;
    }
}
