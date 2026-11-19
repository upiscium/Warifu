<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

/** Legacy hook dispatch swallows ordinary Exceptions, but rethrows HintException. */
final class GuardFailure extends \OCP\HintException {
    public function __construct(public readonly int $status, string $message) {
        parent::__construct($message, $message, $status);
    }
}
