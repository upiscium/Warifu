<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

/** Only selects requests eligible for native rejection, never authorizes deletion. */
final class CollectionPreconditions {
    public static function explicitMatchOnly(
        ?string $match, ?string $none, ?string $unmodified, ?string $modified, ?string $davIf,
    ): bool {
        if ($match === null || $none !== null || $unmodified !== null || $modified !== null || $davIf !== null) return false;
        try { $tags = Conditions::parse($match); }
        catch (\InvalidArgumentException) { return false; }
        return is_array($tags) && count($tags) === 1 && !$tags[0]['weak'];
    }
}
