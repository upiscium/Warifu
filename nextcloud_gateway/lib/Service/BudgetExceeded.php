<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

final class BudgetExceeded extends \RuntimeException {

    public function __construct(public readonly int $status, public readonly string $reason) {
        parent::__construct('Gateway work budget exceeded');
    }
}
