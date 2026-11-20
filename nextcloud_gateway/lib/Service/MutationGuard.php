<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

use OCP\Lock\ILockingProvider;

/** Request-local gate. Adapted from ObsidianCore-Sync's AGPL-3.0-or-later Bridge. */
final class MutationGuard {
    private int $seen = 0;
    private bool $active = true;
    public ?string $committedTag = null;
    public function __construct(
        private readonly array $target,
        private readonly string $operation,
        private readonly ?string $match,
        private readonly ?string $noneMatch,
        private readonly ?string $unmodified,
        private readonly ?int $postWriteBytes = null,
    ) {
        \OCP\Util::connectHook('OC_Filesystem', $operation === 'PUT' ? 'write' : 'delete', $this, 'check');
        if ($operation === 'PUT') \OCP\Util::connectHook('OC_Filesystem', 'post_write', $this, 'capture');
    }

    public function check(array $params): void {
        if (!$this->active || ($params['path'] ?? null) !== $this->target['hookPath']) return;
        $promoted = false;
        try {
            RequestLimits::checkpoint();
            if (++$this->seen !== 1) throw new GuardFailure(503, 'Repeated target mutation hook');
            $t = $this->target;
            // Promote exactly the shared lock owned by the native DAV/View operation.
            // Keep that same shared hold across handoff; never unlock/reacquire it.
            $t['storage']->changeLock($t['path'], ILockingProvider::LOCK_EXCLUSIVE, $t['provider']);
            $promoted = true;
            $exists = $t['storage']->file_exists($t['path']);
            if ($exists && !$t['storage']->is_file($t['path'])) throw new GuardFailure(412, 'Target is no longer a regular file');
            $etag = $exists ? Scope::tag($t) : null;
            $mtime = $exists ? $t['storage']->filemtime($t['path']) : null;
            if (!Conditions::permits($this->match, $this->noneMatch, $this->unmodified, $exists, $etag, is_int($mtime) ? $mtime : null)) {
                throw new GuardFailure(412, 'Content precondition failed');
            }
            if ($this->operation === 'PUT') {
                $size = $this->postWriteBytes ?? RequestLimits::bufferedBytes();
                if ($size === null) throw new GuardFailure(503, 'Missing measured write size');
                RequestLimits::preflightHash($size);
            }
            // No HTTP-accessible pause/fault injection. Dedicated proof app may observe this.
            \OC_Hook::emit('NextcloudGateway', 'validated', ['path' => $t['hookPath']]);
            RequestLimits::checkpoint();
        } catch (GuardFailure $error) {
            throw $error;
        } catch (\Throwable $error) {
            $locked = false;
            for ($e = $error; $e !== null; $e = $e->getPrevious()) $locked = $locked || $e instanceof \OCP\Lock\LockedException;
            throw new GuardFailure($locked ? 423 : 503, $locked ? 'Target is locked' : 'Conditional mutation gate failed');
        } finally {
            if ($promoted) {
                try {
                    $t['storage']->changeLock($t['path'], ILockingProvider::LOCK_SHARED, $t['provider']);
                } catch (\Throwable) {
                    throw new GuardFailure(503, 'Could not restore the native shared lock');
                }
            }
        }
        \OC_Hook::emit('NextcloudGateway', 'handoff', ['path' => $this->target['hookPath']]);
        try { RequestLimits::checkpoint(); }
        catch (\Throwable) { throw new GuardFailure(503, 'Mutation deadline or lock witness failed before write'); }
    }

    public function capture(array $params): void {
        if (!$this->active || ($params['path'] ?? null) !== $this->target['hookPath']) return;
        try {
            // Native File::finalizeUpload holds the same shared lock during post_write.
            $this->committedTag = Scope::tag($this->target);
        } catch (\Throwable) {
            throw new GuardFailure(503, 'Committed content validator could not be read');
        }
    }

    public function verify(): void {
        if ($this->seen !== 1 || ($this->operation === 'PUT' && $this->committedTag === null)) {
            // This is an INDETERMINATE server failure, not a no-mutation assertion.
            throw new GuardFailure(503, 'Native mutation lifecycle could not be verified');
        }
    }

    /** A bulk request may mutate several paths (or the same path repeatedly). */
    public function close(): void { $this->active = false; }
}
