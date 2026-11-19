<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

/** Keep session and individual chunk locks distinct inside native varchar(64). */
final class UploadKey {
    public static function session(string $uid, string $session): string {
        return self::digest('session', [$uid, $session]);
    }
    public static function part(string $uid, string $session, string $part): string {
        return self::digest('part', [$uid, $session, $part]);
    }
    private static function digest(string $domain, array $fields): string {
        $input = "ncgw-upload-{$domain}:v1\0";
        foreach ($fields as $field) $input .= strlen($field) . ':' . $field;
        return hash('sha256', $input);
    }
}
