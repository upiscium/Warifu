<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

final class Budget {
    public const DEFAULTS = [
        'request_seconds' => 30,
        'max_file_bytes' => 67108864,
        'max_hash_bytes' => 268435456,
        'max_hash_files' => 1024,
        'max_entries' => 512,
        'max_parallel' => 4,
    ];

    private readonly \Closure $clock;
    private readonly float $started;
    private float $lastTime;
    private array $limits;
    private int $hashBytes = 0;
    private int $hashFiles = 0;
    private int $entries = 0;

    public function __construct(array $limits = [], ?\Closure $clock = null) {
        $this->limits = self::DEFAULTS;
        foreach ($limits as $name => $value) {
            if (!array_key_exists($name, self::DEFAULTS)) throw new \InvalidArgumentException('Unknown budget limit');
            if (!is_int($value) || $value < 1 || $value > self::DEFAULTS[$name]) throw new \InvalidArgumentException('Invalid budget limit');
            $this->limits[$name] = $value;
        }
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1_000_000_000;
        $this->started = ($this->clock)();
        $this->lastTime = $this->started;
    }

    public function limit(string $name): int {
        if (!array_key_exists($name, $this->limits)) throw new \InvalidArgumentException('Unknown budget limit');
        return $this->limits[$name];
    }

    public function check(): void {
        if ($this->time() - $this->started >= $this->limits['request_seconds']) {
            throw new BudgetExceeded(503, 'deadline');
        }
    }

    public function file(int $bytes): void {
        $this->check();
        if ($bytes < 0) throw new \InvalidArgumentException('File size cannot be negative');
        if ($bytes > $this->limits['max_file_bytes']) throw new BudgetExceeded(413, 'file_limit');
    }

    public function hash(int $bytes): void {
        $this->check();
        if ($bytes < 0) throw new \InvalidArgumentException('Hash size cannot be negative');
        if ($bytes > $this->limits['max_hash_bytes'] - $this->hashBytes) throw new BudgetExceeded(503, 'hash_budget');
        $this->hashBytes += $bytes;
    }

    public function hashedFile(): void {
        $this->check();
        if ($this->hashFiles >= $this->limits['max_hash_files']) throw new BudgetExceeded(503, 'file_budget');
        $this->hashFiles++;
    }

    public function entry(): void {
        $this->check();
        if ($this->entries >= $this->limits['max_entries']) throw new BudgetExceeded(503, 'entry_budget');
        $this->entries++;
    }

    public function elapsedSeconds(): float {
        return $this->time() - $this->started;
    }

    private function time(): float {
        $now = ($this->clock)();
        if ($now < $this->lastTime) throw new BudgetExceeded(503, 'deadline');
        $this->lastTime = $now;
        return $now;
    }
}
