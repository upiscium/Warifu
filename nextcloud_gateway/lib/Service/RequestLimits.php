<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

use OCA\NextcloudGateway\Dav\LimitFailure;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** Cooperative work bounds, NOT a fencing protocol or a hard wall-clock watchdog. */
final class RequestLimits {
    private static ?Budget $budget = null;
    private static ?ILockingProvider $provider = null;
    private static ?ResponseInterface $response = null;
    private static ?string $probe = null;
    private static array $held = [];
    private static bool $working = false;
    private static array $streams = [];
    private static ?int $bufferedBytes = null;
    private static ?LimitFailure $failure = null;
    private static ?ILockingProvider $uncachedProvider = null;

    /** Select native DB's uncached mode before authentication/filesystem setup.
     * A failure here is deferred until an enrolled request, never used to break
     * unrelated DAV requests on an unsupported/disabled installation.
     */
    public static function prepareProvider(): void {
        try {
            $provider = Server::get(ILockingProvider::class);
            if (get_class($provider) !== \OC\Lock\DBLockingProvider::class) return;
            Runtime::verify();
            self::$provider = $provider;
            self::uncachedDbLocks();
        } catch (\Throwable) { self::$failure = null; }
    }

    public static function begin(ResponseInterface $response, string $uid, string $path): void {
        if (self::$budget !== null) return;
        self::$response = $response;
        $config = Server::get(IConfig::class);
        try {
            $global = $config->getSystemValue('nextcloud_gateway_limits', []);
            $personal = json_decode($config->getUserValue($uid, 'nextcloud_gateway', 'limits', '{}'), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($global) || !is_array($personal)) throw new \InvalidArgumentException();
            $global = SizeLimits::serverLimits($config, $global);
            $serverBudget = new Budget($global);
            new Budget($personal); // Validate before combining: never silently ignore invalid settings.
            foreach ($personal as $key => $value) $global[$key] = min($value, $serverBudget->limit($key));
            self::$budget = new Budget($global);
        } catch (\Throwable) { self::fail(503, 'invalid_limits'); }
        self::$working = true;
        $ttl = $config->getSystemValueInt('filelocking.ttl', max(3600, (int)ini_get('max_execution_time')));
        if ($ttl < max(60, 4 * self::$budget->limit('request_seconds'))) self::fail(503, 'unsafe_lock_ttl');
        self::$provider = Scope::provider();
        if (get_class(self::$provider) === \OC\Lock\DBLockingProvider::class) self::uncachedDbLocks();
        if (self::$provider instanceof \OC\Lock\MemcacheLockingProvider) {
            if ($config->getSystemValue('redis.cluster', null) !== null) self::fail(503, 'redis_cluster_unqualified');
            $redis = $config->getSystemValue('redis', []);
            foreach (['timeout', 'read_timeout'] as $key) {
                $timeout = $redis[$key] ?? 0;
                if ((!is_int($timeout) && !is_float($timeout)) || !is_finite((float)$timeout) || $timeout <= 0 || $timeout > min(5, self::$budget->limit('request_seconds'))) self::fail(503, 'unbounded_redis_timeout');
            }
        }
        register_shutdown_function([self::class, 'cleanup']);
        // Admission limits active work, not already detached snapshot transmission.
        for ($slot = 0; $slot < self::$budget->limit('max_parallel'); $slot++) {
            $key = AdmissionKey::forSlot($uid, $slot);
            try {
                self::$provider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
                self::$held[] = $key;
                break;
            } catch (\OCP\Lock\LockedException) { continue; }
            catch (\Throwable) { self::fail(503, 'locking_unavailable'); }
        }
        if (self::$held === []) self::fail(429, 'parallel_limit');
        self::$probe = 'ncgw-witness:' . bin2hex(random_bytes(24));
        try {
            self::$provider->acquireLock(self::$probe, ILockingProvider::LOCK_EXCLUSIVE);
            self::$held[] = self::$probe;
        } catch (\Throwable) { self::fail(503, 'locking_unavailable'); }
        self::checkpoint();
        \OC_Hook::emit('NextcloudGateway', 'limits-ready', ['path' => $path, 'provider' => self::$provider, 'probe' => self::$probe]);
        self::checkpoint();
    }

    public static function checkpoint(): void {
        if (!self::$working) return;
        if (self::$failure !== null) throw self::$failure;
        self::charge('check');
        // Memcache isLocked reads the backend. DB isLocked trusts a local cache,
        // so it MUST NOT be presented as a DB ownership/fencing assertion.
        if (self::$probe !== null && self::$provider instanceof \OC\Lock\MemcacheLockingProvider) {
            try { $alive = self::$provider->isLocked(self::$probe, ILockingProvider::LOCK_EXCLUSIVE); }
            catch (\Throwable) { self::fail(503, 'locking_unavailable'); }
            if (!$alive) self::fail(503, 'lock_state_lost');
        }
    }

    /** Pinned internal adapter: choose DB's existing uncached mode BEFORE use.
     * Never flush, decrement, or clear an already-held/cached lock. Changing mode
     * mid-lifecycle would double-release rows, so any pre-existing state rejects.
     */
    private static function uncachedDbLocks(): void {
        if (self::$uncachedProvider === self::$provider) return;
        try {
            $cached = (new \ReflectionProperty(\OC\Lock\DBLockingProvider::class, 'sharedLocks'))->getValue(self::$provider);
            $owned = (new \ReflectionProperty(\OC\Lock\AbstractLockingProvider::class, 'acquiredLocks'))->getValue(self::$provider);
            if (!is_array($cached) || !is_array($owned) || array_filter($cached) !== []
                || array_filter($owned['shared'] ?? [true]) !== [] || array_filter($owned['exclusive'] ?? [true]) !== []) self::fail(503, 'db_locks_already_used');
            (new \ReflectionProperty(\OC\Lock\DBLockingProvider::class, 'cacheSharedLocks'))->setValue(self::$provider, false);
            self::$uncachedProvider = self::$provider;
        } catch (LimitFailure $error) { throw $error; }
        catch (\Throwable) { self::fail(503, 'unsupported_db_lock_layout'); }
    }

    public static function file(int $bytes): void { self::charge('file', $bytes); }
    public static function hashedFile(): void { self::charge('hashedFile'); }
    public static function hash(int $bytes): void { self::charge('hash', $bytes); }
    public static function preflightHash(int $bytes): void {
        if (!self::$working) return;
        try { self::$budget->preflightHash($bytes); }
        catch (BudgetExceeded $error) { self::fail($error->status, $error->reason); }
    }
    public static function bufferedBytes(): ?int { return self::$bufferedBytes; }
    public static function entry(): void { self::charge('entry'); }
    public static function limit(string $name): int { return self::$budget?->limit($name) ?? Budget::DEFAULTS[$name]; }
    public static function active(): bool { return self::$working; }

    private static function charge(string $method, ?int $value = null): void {
        if (!self::$working) return;
        try { $value === null ? self::$budget->$method() : self::$budget->$method($value); }
        catch (BudgetExceeded $error) { self::fail($error->status, $error->reason); }
    }

    public static function fail(int $status, string $reason): never {
        if (in_array($status, [429, 503], true)) self::$response?->setHeader('Retry-After', '5');
        self::$failure = new LimitFailure($status, $reason);
        throw self::$failure;
    }

    /** Read a bounded body BEFORE native PUT takes storage locks. */
    public static function buffer(RequestInterface $request, int $maximum): void {
        $length = $request->getHeader('Content-Length');
        if ($length !== null && (!ctype_digit($length) || strlen($length) > strlen((string)Budget::MAX_BYTES))) self::fail(400, 'invalid_length');
        if ($length !== null && (int)$length > $maximum) self::fail(413, 'body_limit');
        $input = $request->getBodyAsStream();
        $output = self::temporary();
        $bytes = 0;
        while (!feof($input)) {
            self::checkpoint();
            $part = fread($input, min(65536, $maximum + 1 - $bytes));
            if ($part === false || ($part === '' && !feof($input))) self::fail(503, 'body_read_failed');
            $bytes += strlen($part);
            if ($bytes > $maximum) self::fail(413, 'body_limit');
            if (fwrite($output, $part) !== strlen($part)) self::fail(503, 'buffer_write_failed');
        }
        self::checkpoint();
        if ($length !== null && $bytes !== (int)$length) self::fail(400, 'truncated_body');
        self::$bufferedBytes = $bytes;
        rewind($output);
        $request->setBody($output);
    }

    /** Copy precisely the native GET/Range selection while its source lock is held. */
    public static function snapshot(ResponseInterface $response): void {
        $source = $response->getBody();
        if (is_string($source)) { self::file(strlen($source)); self::checkpoint(); return; }
        if (!is_resource($source)) self::fail(503, 'unsupported_response_body');
        $lengthHeader = $response->getHeader('Content-Length');
        $length = $lengthHeader === null ? null : (int)$lengthHeader;
        $maximum = self::limit('max_file_bytes');
        if ($length !== null) self::file($length);
        $output = self::temporary();
        $bytes = 0;
        try {
            while (($length === null || $bytes < $length) && !feof($source)) {
                self::checkpoint();
                $part = fread($source, min(65536, ($length ?? ($maximum + 1)) - $bytes));
                if ($part === false || ($part === '' && !feof($source))) self::fail(503, 'snapshot_read_failed');
                $bytes += strlen($part);
                self::file($bytes);
                if (fwrite($output, $part) !== strlen($part)) self::fail(503, 'snapshot_write_failed');
            }
            self::checkpoint();
            if ($length !== null && $bytes !== $length) self::fail(503, 'snapshot_length_changed');
            rewind($output);
            $response->setBody($output);
            $response->setHeader('Content-Length', (string)$bytes);
        } finally { fclose($source); }
    }

    private static function temporary() {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) self::fail(503, 'temporary_storage_unavailable');
        self::$streams[] = $stream;
        return $stream;
    }

    public static function finish(): void {
        self::$working = false;
        $held = self::$held; self::$held = [];
        foreach (array_reverse($held) as $key) {
            try { self::releaseOwned(self::$provider, $key, ILockingProvider::LOCK_EXCLUSIVE); }
            catch (\Throwable) { /* No forced unlock or blind retry after backend failure. */ }
        }
    }
    /** DB's uncached releaseLock does not check ownership after releaseAll.
     * Shutdown may already have released it; never decrement another request's
     * hold by releasing the same remembered application token a second time.
     */
    public static function releaseOwned(ILockingProvider $provider, string $key, int $type): void {
        $owned = self::ownedLocks($provider);
        if (empty($owned[$type === ILockingProvider::LOCK_SHARED ? 'shared' : 'exclusive'][$key])) return;
        $provider->releaseLock($key, $type);
    }
    public static function ownedLocks(ILockingProvider $provider): array {
        if (!in_array(get_class($provider), [\OC\Lock\DBLockingProvider::class, \OC\Lock\MemcacheLockingProvider::class], true)) throw new \LogicException('Unsupported locking provider');
        $owned = (new \ReflectionProperty(\OC\Lock\AbstractLockingProvider::class, 'acquiredLocks'))->getValue($provider);
        if (!is_array($owned) || !is_array($owned['shared'] ?? null) || !is_array($owned['exclusive'] ?? null)) throw new \LogicException('Unsupported locking ownership layout');
        return $owned;
    }
    public static function cleanup(): void {
        self::finish();
        foreach (self::$streams as $stream) if (is_resource($stream)) fclose($stream);
        self::$streams = [];
    }
}
