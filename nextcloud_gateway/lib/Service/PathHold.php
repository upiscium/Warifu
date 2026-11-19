<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

use OCP\Lock\ILockingProvider;

/** One native View acquisition, including its exact ancestor keys. */
final class PathHold {
    private array $held = [];
    private function __construct(private readonly ILockingProvider $provider) {}

    public static function acquire(array $target, int $type): self {
        RequestLimits::checkpoint();
        $hold = new self($target['provider']);
        $before = RequestLimits::ownedLocks($hold->provider);
        try {
            if (!$target['view']->lockFile($target['hookPath'], $type)) {
                throw new \Sabre\DAV\Exception\ServiceUnavailable('Path was excluded from native locking');
            }
        } catch (\Throwable $error) {
            $hold->remember($before);
            try { $hold->release(); } catch (\Throwable) { /* Native final cleanup remains the fallback. */ }
            if ($error instanceof \OCP\Lock\LockedException) throw new \Sabre\DAV\Exception\Locked();
            throw $error;
        }
        $hold->remember($before);
        return $hold;
    }

    private function remember(array $before): void {
        foreach (RequestLimits::ownedLocks($this->provider) as $kind => $keys) {
            foreach ($keys as $key => $count) {
                $floor = (int)($before[$kind][$key] ?? 0);
                $delta = (int)$count - $floor;
                if ($delta > 0) $this->held[] = [$key, $kind === 'shared' ? ILockingProvider::LOCK_SHARED : ILockingProvider::LOCK_EXCLUSIVE, $delta, $floor];
            }
        }
    }

    public function release(): void {
        $held = $this->held; $this->held = [];
        $failure = null;
        foreach (array_reverse($held) as [$key, $type, $count, $floor]) {
            try {
                for ($i = 0; $i < $count; $i++) {
                    $owned = RequestLimits::ownedLocks($this->provider);
                    if ((int)($owned[$type === ILockingProvider::LOCK_SHARED ? 'shared' : 'exclusive'][$key] ?? 0) <= $floor) break;
                    RequestLimits::releaseOwned($this->provider, $key, $type);
                }
            } catch (\Throwable $error) { $failure ??= $error; }
        }
        if ($failure !== null) throw $failure;
    }
}
