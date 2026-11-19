<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Service;

use OC\Files\Filesystem;
use OCA\DAV\Connector\Sabre\File;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\Files\Storage\ILockingStorage;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\ServiceUnavailable;

final class Scope {
    private ?array $roots = null;

    public function enabled(): bool {
        return Server::get(IConfig::class)->getAppValue('nextcloud_gateway', 'experimental_enabled', 'no') === 'yes';
    }

    /** Return a default-view-relative path only for this user's enrolled roots. */
    public function relative(string $davPath): ?string {
        if (!$this->enabled()) return null;
        $user = Server::get(IUserSession::class)->getUser();
        if ($user === null) return null;
        $prefix = 'files/' . $user->getUID() . '/';
        $davPath = trim($davPath, '/');
        if (!str_starts_with($davPath, $prefix)) return null;
        $relative = substr($davPath, strlen($prefix));
        foreach ($this->enrolledRoots() as $root) {
            if ($relative === $root || str_starts_with($relative, $root . '/')) {
                if (!$this->validPath($relative)) throw new Forbidden('Path is outside the supported Gateway namespace');
                return '/' . $relative;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function enrolledRoots(): array {
        if (!$this->enabled()) return [];
        $user = Server::get(IUserSession::class)->getUser();
        if ($user === null) return [];
        if ($this->roots === null) {
            $raw = Server::get(IConfig::class)->getUserValue($user->getUID(), 'nextcloud_gateway', 'allowed_roots', '[]');
            $roots = json_decode($raw, true);
            if (!is_array($roots) || !array_is_list($roots)) throw new ServiceUnavailable('Invalid Gateway root configuration');
            foreach ($roots as $root) {
                if (!is_string($root) || !$this->validPath($root)) throw new ServiceUnavailable('Invalid Gateway root configuration');
            }
            $this->roots = $roots;
        }
        return $this->roots;
    }

    /** Collection operations on an ancestor also intersect enrolled content. */
    public function intersects(string $davPath): bool {
        if ($this->relative($davPath) !== null) return true;
        $user = Server::get(IUserSession::class)->getUser();
        if ($user === null) return false;
        $path = trim($davPath, '/');
        foreach ($this->enrolledRoots() as $root) {
            if (str_starts_with('files/' . $user->getUID() . '/' . $root, $path . '/')) return true;
        }
        return false;
    }

    private function validPath(string $path): bool {
        if ($path === '' || strlen($path) > 4096 || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) || preg_match('//u', $path) !== 1) return false;
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/\.(?:part|partial)$/i', $part)) return false;
        }
        return true;
    }

    /** @return array{storage:ILockingStorage,path:string,hookPath:string,provider:ILockingProvider,view:\OC\Files\View} */
    public function target(string $relative): array {
        $provider = self::provider();
        RequestLimits::checkpoint();
        $user = Server::get(IUserSession::class)->getUser();
        if ($user === null) throw new ServiceUnavailable('Authenticated filesystem required');
        // Native cookie DAV authentication may precede lazy filesystem setup.
        // Initialize only the authenticated user; never reset an existing view.
        if (!Filesystem::$loaded) {
            Server::get(ISetupManager::class)->setupForUser($user);
            RequestLimits::checkpoint();
            if (!Filesystem::$loaded) throw new ServiceUnavailable('Authenticated filesystem required');
        }
        $home = Server::get(IRootFolder::class)->getUserFolder($user->getUID());
        $view = Filesystem::getView();
        if ($view === null || rtrim($view->getRoot(), '/') !== rtrim($home->getPath(), '/')) throw new ServiceUnavailable('Unsupported filesystem view');
        [$storage, $internal] = $view->resolvePath($relative);
        if (!$storage instanceof ILockingStorage || $storage !== $home->getStorage() || !$storage->instanceOfStorage(\OC\Files\Storage\Home::class) || $storage->instanceOfStorage(\OC\Files\Storage\Wrapper\Encryption::class)) {
            throw new ServiceUnavailable('Gateway supports ordinary unencrypted local home storage only');
        }
        // Reject symlink aliases before any raw storage read, including intermediate components.
        $base = realpath($storage->getLocalFile(''));
        $local = $storage->getLocalFile($internal);
        if (!is_string($base) || !is_string($local) || $local !== rtrim($base, '/') . '/' . $internal) throw new ServiceUnavailable('Unsupported local storage mapping');
        $walk = rtrim($base, '/');
        foreach (explode('/', $internal) as $component) {
            if ($component === '' || $component === '.' || $component === '..') throw new Forbidden('Invalid storage path');
            $walk .= '/' . $component;
            if (is_link($walk)) throw new Forbidden('Symlinks are outside the Gateway support matrix');
        }
        return ['storage' => $storage, 'path' => $internal, 'hookPath' => $relative, 'provider' => $provider, 'view' => $view];
    }

    public static function provider(): ILockingProvider {
        Runtime::verify();
        $config = Server::get(IConfig::class);
        if (implode('.', array_slice(\OCP\Util::getVersion(), 0, 3)) !== '35.0.3' || $config->getSystemValue('filelocking.enabled', true) !== true) {
            throw new ServiceUnavailable('Gateway requires the tested Nextcloud 35.0.3 runtime and core file locking');
        }
        $provider = Server::get(ILockingProvider::class);
        $redis = get_class($provider) === \OC\Lock\MemcacheLockingProvider::class
            && ltrim((string)$config->getSystemValue('memcache.locking', ''), '\\') === \OC\Memcache\Redis::class;
        if (get_class($provider) !== \OC\Lock\DBLockingProvider::class && !$redis) {
            throw new ServiceUnavailable('Gateway requires the qualified DB or Redis locking provider');
        }
        return $provider;
    }

    /** Must be called with the exact core storage lock held. Never uses metadata ETags. */
    public static function tag(array $target): ?string {
        RequestLimits::checkpoint();
        clearstatcache();
        if (!$target['storage']->file_exists($target['path'])) return null;
        if (!$target['storage']->is_file($target['path'])) throw new ServiceUnavailable('A regular file is required for a content validator');
        $size = $target['storage']->filesize($target['path']);
        if (!is_int($size) || $size < 0) throw new ServiceUnavailable('Invalid file size');
        RequestLimits::file($size);
        RequestLimits::hashedFile();
        $input = $target['storage']->fopen($target['path'], 'rb');
        if (!is_resource($input)) throw new ServiceUnavailable('Could not read content validator');
        $context = hash_init('sha256'); $bytes = 0;
        try {
            while (!feof($input)) {
                RequestLimits::checkpoint();
                $chunk = fread($input, 65536);
                if ($chunk === false || ($chunk === '' && !feof($input))) throw new ServiceUnavailable('Could not read content validator');
                $bytes += strlen($chunk);
                RequestLimits::file($bytes);
                RequestLimits::hash(strlen($chunk));
                hash_update($context, $chunk);
            }
            RequestLimits::checkpoint();
            if ($bytes !== $size) throw new ServiceUnavailable('Content size changed while locked');
            $hash = hash_final($context);
        } finally { fclose($input); }
        return '"ncgw-sha256-' . $hash . '"';
    }

    public function fileTag(File $node): string {
        if (!$node->getFileInfo()->isReadable()) throw new \Sabre\DAV\Exception\NotFound('File is not readable');
        $target = $this->target($node->getPath());
        $hold = self::lockRead($target);
        try {
            $etag = self::tag($target);
            if ($etag === null) throw new ServiceUnavailable('File disappeared during validator lookup');
            return $etag;
        } finally {
            self::unlockRead($hold);
        }
    }

    /** In-request metadata only: do NOT persist our DAV validator into Nextcloud's cache. */
    public function installTag(File $node, bool $readMetadata = false): string {
        $target = $this->target($node->getPath());
        $hold = self::lockRead($target);
        try {
            $etag = $this->fileTag($node);
            $info = $node->getFileInfo();
            $data = $info instanceof \ArrayAccess ? $info : $info->getData();
            if (!$data instanceof \ArrayAccess) throw new ServiceUnavailable('Unsupported DAV metadata representation');
            $data['etag'] = trim($etag, '"');
            if ($readMetadata) {
                // The tree may have cached metadata BEFORE our read lock was acquired.
                // Refresh size/mtime from the same locked representation as the hash.
                $size = $target['storage']->filesize($target['path']);
                $mtime = $target['storage']->filemtime($target['path']);
                if (!is_int($size) || !is_int($mtime)) throw new ServiceUnavailable('Could not read snapshot metadata');
                $data['size'] = $size;
                $data['mtime'] = $mtime;
            }
            if ($node->getETag() !== $etag) throw new ServiceUnavailable('Unsupported in-request DAV metadata representation');
            return $etag;
        } finally {
            self::unlockRead($hold);
        }
    }

    public static function lockRead(array $target): PathHold {
        return PathHold::acquire($target, ILockingProvider::LOCK_SHARED);
    }

    public static function unlockRead(PathHold $hold): void {
        $hold->release();
    }
}
