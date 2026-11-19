<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

use Sabre\DAV\Exception\ServiceUnavailable;

/** Internal lock/hook contracts, not just the advertised version, are pinned. */
final class Runtime {
    private static bool $verified = false;

    public static function verify(): void {
        if (self::$verified) return;
        $manifest = json_decode(file_get_contents(__DIR__ . '/../../appinfo/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $path => $expected) {
            $file = \OC::$SERVERROOT . '/' . $path;
            if (!is_file($file) || !hash_equals($expected, hash_file('sha256', $file))) {
                throw new ServiceUnavailable('Gateway runtime fingerprint mismatch; requalification is required');
            }
        }
        // Request-local only: never cache this across code/config updates.
        self::$verified = true;
    }
}
