<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

use OCP\ICacheFactory;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use Sabre\DAV\Exception;
use Sabre\HTTP\RequestInterface;

/** Serializes finalization/cancel against chunk writers, without core patches. */
final class UploadSession {
    private array $held = [];
    public function prepare(RequestInterface $request, string $uid): bool {
        $prefix = 'uploads/' . $uid . '/';
        if (!str_starts_with($request->getPath(), $prefix)) return false;
        $parts = explode('/', substr($request->getPath(), strlen($prefix)));
        $session = $parts[0];
        if ($session === '' || strlen($session) > 255 || in_array($session, ['.', '..'], true) || preg_match('/[\\\\\x00-\x1f\x7f]/', $session)) throw new Exception\BadRequest('Invalid upload session');
        if (!in_array($request->getMethod(), ['MKCOL', 'PUT', 'MOVE', 'DELETE'], true)) return true;
        if (($request->getMethod() === 'MKCOL' && count($parts) !== 1)
            || ($request->getMethod() === 'MOVE' && (count($parts) !== 2 || $parts[1] !== '.file'))
            || ($request->getMethod() === 'DELETE' && (count($parts) > 2 || (count($parts) === 2 && $parts[1] !== '.file' && !ctype_digit($parts[1]))))) {
            throw new Exception\BadRequest('Unsupported upload operation');
        }
        $provider = Server::get(ILockingProvider::class);
        $key = UploadKey::session($uid, $session);
        $shared = $request->getMethod() === 'PUT';
        $this->acquire($provider, $key, $shared ? ILockingProvider::LOCK_SHARED : ILockingProvider::LOCK_EXCLUSIVE);
        if ($shared) {
            if (count($parts) !== 2 || !preg_match('/^[0-9]+$/D', $parts[1])) throw new Exception\BadRequest('Chunk names must be numeric');
            $this->acquire($provider, UploadKey::part($uid, $session, $parts[1]), ILockingProvider::LOCK_EXCLUSIVE);
        }
        if ($request->getMethod() !== 'DELETE') {
            // Previously started v2 sessions cannot be reinterpreted as v1 chunks.
            $metadata = Server::get(ICacheFactory::class)->createDistributed(\OCA\DAV\Upload\ChunkingV2Plugin::CACHE_KEY)->get($session);
            if ($metadata !== null && $metadata !== false) throw new Exception\ServiceUnavailable('Restart this pre-existing chunking-v2 upload');
            // v2 is a storage optimization, not a different client wire protocol.
            // Removing its prerequisite on staging requests selects native v1.
            if (in_array($request->getMethod(), ['MKCOL', 'PUT'], true)) $request->removeHeader('Destination');
        } else {
            $request->removeHeader('Destination');
        }
        \OC_Hook::emit('NextcloudGateway', 'upload-locked', ['path' => $request->getPath()]);
        RequestLimits::checkpoint();
        return true;
    }

    private function acquire(ILockingProvider $provider, string $key, int $type): void {
        try { $provider->acquireLock($key, $type); }
        catch (\OCP\Lock\LockedException) { throw new Exception\Locked(); }
        $this->held[] = [$provider, $key, $type];
    }

    public function release(): void {
        $held = $this->held;
        $this->held = [];
        foreach (array_reverse($held) as [$provider, $key, $type]) {
            try { RequestLimits::releaseOwned($provider, $key, $type); }
            catch (\Throwable) { /* Native request-final releaseAll is the fallback. */ }
        }
    }
}
