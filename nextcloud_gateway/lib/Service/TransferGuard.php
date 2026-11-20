<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

use OCP\Lock\ILockingProvider;
use OCA\NextcloudGateway\Dav\LimitFailure;

/** Validate both files while promoting the holds already owned by native View. */
final class TransferGuard {
    private int $seen = 0;
    private bool $active = true;
    public ?string $committedTag = null;

    public function __construct(
        private readonly array $source,
        private readonly array $destination,
        private readonly string $operation,
        private readonly ?string $match,
        private readonly ?string $none,
        private readonly ?string $unmodified,
        private readonly array $davLists,
        private readonly bool $expectedExists,
        private readonly bool $overwrite,
    ) {
        $hook = $operation === 'MOVE' ? 'rename' : 'copy';
        \OCP\Util::connectHook('OC_Filesystem', $hook, $this, 'check');
        \OCP\Util::connectHook('OC_Filesystem', 'post_' . $hook, $this, 'capture');
    }

    private function applies(array $params): bool {
        return $this->active && ($params['oldpath'] ?? null) === $this->source['hookPath'] && ($params['newpath'] ?? null) === $this->destination['hookPath'];
    }

    public function check(array $params): void {
        if (!$this->applies($params)) return;
        $promoted = [];
        try {
            RequestLimits::checkpoint();
            if (++$this->seen !== 1) throw new GuardFailure(503, 'Repeated transfer hook');
            $targets = [$this->source, $this->destination];
            usort($targets, fn ($a, $b) => strcmp($a['path'], $b['path']));
            foreach ($targets as $target) {
                $target['storage']->changeLock($target['path'], ILockingProvider::LOCK_EXCLUSIVE, $target['provider']);
                $promoted[] = $target;
            }
            clearstatcache();
            $s = $this->source; $d = $this->destination;
            if (!$s['storage']->is_file($s['path'])) throw new GuardFailure(412, 'Source is no longer a regular file');
            $exists = $d['storage']->file_exists($d['path']);
            if ($exists !== $this->expectedExists || ($exists && (!$this->overwrite || !$d['storage']->is_file($d['path'])))) throw new GuardFailure(412, 'Destination state changed');
            // No Sabre pre-delete: retain its overwrite permission requirement.
            // The native Directory method separately checks parent/create/move rights.
            if (!$s['view']->isReadable($s['hookPath']) || ($exists && (!$d['view']->isDeletable($d['hookPath']) || !$d['view']->isUpdatable($d['hookPath'])))) throw new GuardFailure(403, 'Transfer permission denied');
            $sourceTag = Scope::tag($s);
            $destinationTag = $exists ? Scope::tag($d) : null;
            $mtime = $s['storage']->filemtime($s['path']);
            if (!Conditions::permits($this->match, $this->none, $this->unmodified, true, $sourceTag, is_int($mtime) ? $mtime : null)) throw new GuardFailure(412, 'Source content precondition failed');
            foreach ($this->davLists as $which => $lists) {
                $tag = $which === 'source' ? $sourceTag : $destinationTag;
                $satisfied = false;
                foreach ($lists as $list) {
                    $all = true;
                    foreach ($list as $condition) {
                        $matches = Conditions::matches($condition['etag'], $tag !== null, $tag, false);
                        $all = $all && ($condition['not'] ? !$matches : $matches);
                    }
                    $satisfied = $satisfied || $all;
                }
                if (!$satisfied) throw new GuardFailure(412, 'DAV content precondition failed');
            }
            $sourceSize = $s['storage']->filesize($s['path']);
            if (!is_int($sourceSize) || $sourceSize < 0) throw new GuardFailure(503, 'Invalid transfer source size');
            RequestLimits::preflightHash($sourceSize);
            \OC_Hook::emit('NextcloudGateway', 'transfer-validated', ['path' => $s['hookPath'], 'destination' => $d['hookPath']]);
            RequestLimits::checkpoint();
        } catch (GuardFailure $error) { throw $error; }
        catch (LimitFailure $error) { throw new GuardFailure($error->status, 'Transfer work budget exceeded'); }
        catch (\Throwable $error) {
            $locked = false;
            for ($e = $error; $e !== null; $e = $e->getPrevious()) $locked = $locked || $e instanceof \OCP\Lock\LockedException;
            throw new GuardFailure($locked ? 423 : 503, 'Transfer gate failed');
        } finally {
            $failed = false;
            foreach (array_reverse($promoted) as $target) {
                try { $target['storage']->changeLock($target['path'], ILockingProvider::LOCK_SHARED, $target['provider']); }
                catch (\Throwable) { $failed = true; }
            }
            if ($failed) throw new GuardFailure(503, 'Could not restore native transfer holds');
        }
        \OC_Hook::emit('NextcloudGateway', 'transfer-handoff', ['path' => $this->source['hookPath'], 'destination' => $this->destination['hookPath']]);
        try { RequestLimits::checkpoint(); }
        catch (\Throwable) { throw new GuardFailure(503, 'Transfer deadline or lock witness failed before mutation'); }
    }

    public function capture(array $params): void {
        if (!$this->applies($params)) return;
        try { $this->committedTag = Scope::tag($this->destination); }
        catch (\Throwable) { throw new GuardFailure(503, 'Transferred content validator could not be read'); }
    }
    public function verify(): void {
        if ($this->seen !== 1 || $this->committedTag === null) throw new GuardFailure(503, 'Native transfer lifecycle could not be verified');
    }
    public function close(): void { $this->active = false; }
}
