<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

/** Native file_locks.key is varchar(64): hash the namespace AND the whole tuple. */
final class AdmissionKey {
    public static function forSlot(string $uid, int $slot): string {
        if ($slot < 0) throw new \InvalidArgumentException('Admission slot must be nonnegative');
        // The decimal integer has no NUL; the UID is the final, unmodified field.
        // Do not truncate a prefixed UID hash: that would discard the slot and
        // leave releaseLock addressing a different key from the inserted row.
        return hash('sha256', "ncgw-admission:v1\0" . $slot . "\0" . $uid);
    }
}
