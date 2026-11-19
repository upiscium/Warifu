<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Dav;

use OCA\DAV\Connector\Sabre\File;
use OCA\NextcloudGateway\Service\Conditions;
use OCA\NextcloudGateway\Service\CollectionPreconditions;
use OCA\NextcloudGateway\Service\GuardFailure;
use OCA\NextcloudGateway\Service\MutationGuard;
use OCA\NextcloudGateway\Service\Scope;
use OCA\NextcloudGateway\Service\UploadSession;
use OCA\NextcloudGateway\Service\RequestLimits;
use OCA\NextcloudGateway\Service\PathHold;
use OCA\NextcloudGateway\Service\HomeListing;
use OCP\Lock\ILockingProvider;
use Sabre\DAV\Exception;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** Extends the existing authenticated Files DAV server; no replacement auth or router. */
final class GatewayPlugin extends ServerPlugin {
    private Server $server;
    private Scope $scope;
    private ?MutationGuard $guard = null;
    private ?PathHold $readLock = null;
    private UploadSession $uploads;
    private bool $chunkMove = false;
    private bool $uploadRequest = false;
    private ?PathHold $listingLock = null;
    private TransferPlugin $transfers;
    private bool $rejectCollectionDelete = false;

    public function getPluginName() { return 'nextcloud-gateway'; }
    public function initialize(Server $server) {
        $this->server = $server;
        $this->scope = new Scope();
        if ($this->scope->enabled()) RequestLimits::prepareProvider();
        $this->uploads = new UploadSession();
        $server->addPlugin(new BulkPlugin($this->scope));
        $this->transfers = new TransferPlugin($this->scope);
        $server->addPlugin($this->transfers);
        $server->on('beforeMethod:*', [$this, 'admit'], 40);
        // Native Auth runs first; LockPlugin gets the PUT shared hold at priority 50.
        $server->on('beforeMethod:*', [$this, 'prepare'], 60);
        $server->on('propFind', [$this, 'properties'], 90);
        $server->on('method:PUT', [$this, 'put'], 90);
        $server->on('method:DELETE', [$this, 'delete'], 90);
        // Native HTTP preconditions run before method:* dispatch. If they ever
        // pass for a deferred collection request, reject before any DELETE handler.
        $server->on('method:DELETE', [$this, 'rejectDeferredCollectionDelete'], 1);
        $server->on('method:MOVE', [$this, 'move'], 90);
        $server->on('method:GET', [$this, 'get'], 90);
        $server->on('afterMethod:*', [$this, 'finishWork'], 1000);
        $server->on('exception', [$this, 'failedWork'], 1);
        $server->on('beforeMove', [$this, 'assemble'], 80);
        $server->on('afterResponse', [$this, 'releaseRead'], 1000);
        register_shutdown_function([$this, 'releaseRead']);
        $server->on('afterResponse', [$this->uploads, 'release'], 1000);
        register_shutdown_function([$this->uploads, 'release']);
        register_shutdown_function([$this, 'releaseListing']);
        $server->on('afterResponse', [RequestLimits::class, 'cleanup'], 1100);
    }

    public function admit(RequestInterface $request, ResponseInterface $response): void {
        if ($this->scope->enrolledRoots() === []) return;
        $uid = \OCP\Server::get(\OCP\IUserSession::class)->getUser()->getUID();
        $path = $request->getPath();
        $affected = $this->scope->intersects($path) || str_starts_with($path, 'uploads/' . $uid . '/') || trim($path, '/') === 'bulk';
        if (in_array($request->getMethod(), ['MOVE', 'COPY'], true) && $request->getHeader('Destination') !== null) $affected = $affected || $this->scope->intersects($this->server->calculateUri($request->getHeader('Destination')));
        if (!$affected) return;
        RequestLimits::begin($response, $uid, $path);
        $method = $request->getMethod();
        if ($method === 'PUT') RequestLimits::buffer($request, RequestLimits::limit('max_file_bytes'));
        elseif ($method === 'POST' && trim($path, '/') === 'bulk') RequestLimits::buffer($request, 16777216);
        elseif (in_array($method, ['PROPFIND', 'PROPPATCH', 'MKCOL'], true)) RequestLimits::buffer($request, 1048576);
    }

    public function prepare(RequestInterface $request, ResponseInterface $response): ?bool {
        if ($this->scope->enrolledRoots() === []) return null;
        RequestLimits::checkpoint();
        $method = $request->getMethod();
        if ($method === 'PROPFIND' && RequestLimits::active()) $this->boundListing($request);
        $conditional = $request->getHeader('If-Match') !== null || $request->getHeader('If-None-Match') !== null
            || $request->getHeader('If-Unmodified-Since') !== null || $request->getHeader('If') !== null;
        // These checks run before Sabre's preconditions and every method handler,
        // including bulk POST priority 10 and ChunkingPlugin's beforeMove handler.
        if ($method === 'POST' && trim($request->getPath(), '/') === 'bulk' && $conditional) {
            throw new Exception\NotImplemented('Conditions on a multi-file bulk request are not qualified');
        }
        $uid = \OCP\Server::get(\OCP\IUserSession::class)->getUser()->getUID();
        $upload = str_starts_with($request->getPath(), 'uploads/' . $uid . '/');
        if ($upload) {
            $this->scope->target('/' . $this->scope->enrolledRoots()[0]);
            $this->uploadRequest = $this->uploads->prepare($request, $uid);
        }
        if (in_array($method, ['COPY', 'MOVE'], true)) {
            if (!$upload && $this->transfers->prepare($request, $response, $conditional)) return null;
            $destination = $request->getHeader('Destination');
            $destination = $destination === null ? null : $this->server->calculateUri($destination);
            $affected = $this->scope->intersects($request->getPath()) || ($destination !== null && $this->scope->intersects($destination));
            if ($affected && $conditional) throw new Exception\NotImplemented('Conditional MOVE/COPY operations are not qualified');
            if ($destination !== null && $this->scope->intersects($destination)) {
                $user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
                if (!str_starts_with($request->getPath(), 'files/' . $user->getUID() . '/')) {
                    $destinationRelative = $this->scope->relative($destination);
                    $source = $this->server->tree->getNodeForPath($request->getPath());
                    if (!$upload || $method !== 'MOVE' || !$source instanceof \OCA\DAV\Upload\FutureFile || $destinationRelative === null) {
                        throw new Exception\NotImplemented('Only native chunk finalization into a registered file is qualified');
                    }
                    $folder = $this->server->tree->getNodeForPath(dirname($request->getPath()));
                    if ($folder->childExists('.target')) throw new Exception\ServiceUnavailable('Restart this pre-existing chunking-v2 upload');
                    $target = $this->scope->target($destinationRelative);
                    RequestLimits::file($source->getSize());
                    $none = strtoupper($request->getHeader('Overwrite') ?? 'T') === 'F' ? '*' : null;
                    $this->guard = new MutationGuard($target, 'PUT', null, $none, null);
                    $this->chunkMove = true;
                }
                $destinationRelative = $this->scope->relative($destination);
                if ($destinationRelative !== null) $this->scope->target($destinationRelative);
            }
        }
        if ($conditional && $this->scope->intersects($request->getPath()) && !in_array($method, ['PUT', 'DELETE', 'GET', 'HEAD', 'PROPFIND'], true)) {
            throw new Exception\NotImplemented('Conditional collection operations are not qualified');
        }
        $relative = $this->scope->relative($request->getPath());
        if ($relative === null) {
            if ($method === 'DELETE' && $conditional && $this->scope->intersects($request->getPath())) {
                throw new Exception\NotImplemented('Conditional deletion of an enrolled ancestor is not qualified');
            }
            return null;
        }
        $target = $this->scope->target($relative);
        $response->setHeader('X-Nextcloud-Gateway', 'experimental-1');
        if ($request->getHeader('If') !== null) throw new Exception\NotImplemented('DAV If lists are not qualified');
        try {
            $node = $this->server->tree->getNodeForPath($request->getPath());
        } catch (Exception\NotFound) {
            $node = null;
        }
        if ($node instanceof File) {
            if (in_array($method, ['GET', 'HEAD'], true) && $this->readLock === null) {
                \OC_Hook::emit('NextcloudGateway', 'lookup', ['path' => $relative]);
                $this->readLock = Scope::lockRead($target);
            }
            $this->scope->installTag($node, in_array($method, ['GET', 'HEAD'], true));
            if (in_array($method, ['GET', 'HEAD'], true)) {
                \OC_Hook::emit('NextcloudGateway', 'snapshot', ['path' => $relative]);
            }
        }

        $match = $request->getHeader('If-Match');
        $none = $request->getHeader('If-None-Match');
        $unmodified = $request->getHeader('If-Unmodified-Since');
        try {
            if ($match !== null) Conditions::parse($match);
            if ($none !== null) Conditions::parse($none);
        } catch (\InvalidArgumentException) {
            throw new Exception\BadRequest('Malformed entity-tag condition');
        }
        if ($match !== null) $request->removeHeader('If-Unmodified-Since');

        if ($node instanceof File && in_array($method, ['GET', 'HEAD'], true)) {
            if (!Conditions::permits($match, null, $unmodified, true, $node->getETag(), $node->getLastModified())) {
                throw new Exception\PreconditionFailed('Read precondition failed');
            }
            // Sabre 4.x does not weak-compare If-None-Match and returns 412 for
            // HEAD. Apply HTTP read semantics here, under the snapshot lock.
            $notModified = $none !== null && Conditions::matches($none, true, $node->getETag(), false);
            $modifiedSince = $request->getHeader('If-Modified-Since');
            if ($none === null && $modifiedSince !== null) {
                $date = Conditions::httpDate($modifiedSince);
                $notModified = $date !== null && $node->getLastModified() <= $date;
            }
            if ($notModified) {
                $response->setStatus(304);
                $response->setHeader('ETag', $node->getETag());
                $response->setBody('');
                RequestLimits::checkpoint();
                $this->releaseRead();
                RequestLimits::finish();
                $this->server->sapi->sendResponse($response);
                $this->server->emit('afterResponse', [$request, $response]);
                return false;
            }
            foreach (['If-Match', 'If-None-Match', 'If-Unmodified-Since', 'If-Modified-Since'] as $header) $request->removeHeader($header);
        }

        $conditional = $match !== null || $none !== null || $unmodified !== null || $request->getHeader('If') !== null;
        if ($conditional && !in_array($method, ['PUT', 'DELETE', 'GET', 'HEAD', 'PROPFIND'], true)) {
            throw new Exception\NotImplemented('Conditional collection/MOVE/COPY operations are not yet qualified');
        }
        if (in_array($method, ['PUT', 'DELETE'], true)) {
            if ($request->getHeader('If') !== null || ($node !== null && !$node instanceof File && $conditional)) {
                if ($method === 'DELETE' && $node !== null && get_class($node) === \OCA\DAV\Connector\Sabre\Directory::class
                    && CollectionPreconditions::explicitMatchOnly($match, $none, $unmodified, $request->getHeader('If-Modified-Since'), $request->getHeader('If'))) {
                    // Keep the NORMAL beforeMethod -> native checkPreconditions
                    // order. Pinned Sabre checks explicit If-Match tags on IFile
                    // only, so native collections reject with its own 412. Do not
                    // synthesize that result, change headers/ETags, or invoke DELETE.
                    // The method-stage guard also denies if native behavior changes.
                    $this->rejectCollectionDelete = true;
                    return null;
                }
                throw new Exception\NotImplemented('DAV If lists and conditional collection mutation are not yet qualified');
            }
            if ($node === null || $node instanceof File) {
                $this->guard = new MutationGuard($target, $method, $match, $none, $unmodified);
            }
        }
        return null;
    }

    public function properties(PropFind $propFind, \Sabre\DAV\INode $node): void {
        RequestLimits::entry();
        if (!$node instanceof File || !$this->scope->enabled()) return;
        $user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
        if ($user === null || $this->scope->relative('files/' . $user->getUID() . '/' . ltrim($node->getPath(), '/')) === null) return;
        $propFind->handle('{DAV:}getetag', fn () => $this->scope->fileTag($node));
    }

    public function put(RequestInterface $request, ResponseInterface $response): ?bool {
        if ($this->guard === null) return null;
        return $this->invoke('httpPut', $request, $response);
    }
    public function delete(RequestInterface $request, ResponseInterface $response): ?bool {
        if ($this->guard === null) return null;
        return $this->invoke('httpDelete', $request, $response);
    }
    public function rejectDeferredCollectionDelete(): void {
        if ($this->rejectCollectionDelete) throw new Exception\NotImplemented('Conditional collection deletion is not qualified');
    }
    public function move(RequestInterface $request, ResponseInterface $response): ?bool {
        return $this->chunkMove ? $this->invoke('httpMove', $request, $response) : null;
    }
    public function get(RequestInterface $request, ResponseInterface $response): ?bool {
        if ($this->readLock === null) return null;
        try {
            $this->server->getPlugin('core')->httpGet($request, $response);
            RequestLimits::snapshot($response);
        } finally { $this->releaseRead(); }
        // The selected bytes and headers are now immutable; network slowness no
        // longer extends the storage lock or lease-dependent active-work window.
        RequestLimits::finish();
        \OC_Hook::emit('NextcloudGateway', 'detached', ['path' => $this->scope->relative($request->getPath())]);
        return false;
    }
    public function finishWork(RequestInterface $request, ResponseInterface $response): void {
        try { if ($response->getStatus() < 400) RequestLimits::checkpoint(); }
        finally {
            $this->releaseRead();
            $this->releaseListing();
            RequestLimits::finish();
        }
    }
    public function failedWork(\Throwable $error): void {
        // Error responses do not necessarily emit afterResponse. Release before
        // core's request-final releaseAll, then leave all callbacks idempotent.
        $this->releaseRead();
        $this->releaseListing();
        $this->uploads->release();
        RequestLimits::finish();
    }

    private function boundListing(RequestInterface $request): void {
        $depth = $request->getHeader('Depth') ?? '1';
        if (!in_array($depth, ['0', '1'], true)) throw new Exception\Forbidden('Only finite PROPFIND Depth 0 or 1 is supported');
        if ($depth === '0') return;
        $node = $this->server->tree->getNodeForPath($request->getPath());
        if (!$node instanceof \OCA\DAV\Connector\Sabre\Directory) return;
        // Protect membership while bounding and filling the native Directory's
        // request-local child cache. Release before per-file ancestor read locks:
        // native providers cannot acquire shared beneath our own exclusive hold.
        if (\OC\Files\Filesystem::getMountManager()->findIn($node->getFileInfo()->getPath()) !== []) throw new Exception\ServiceUnavailable('Bounded listings cannot include submounts');
        $target = $this->scope->target($node->getPath());
        $home = in_array($target['hookPath'], ['', '/'], true);
        // The home root is excluded by native View::shouldLockFile. Its bounded
        // name snapshot must not pretend to hold a native membership lock.
        if (!$home) $this->listingLock = PathHold::acquire($target, ILockingProvider::LOCK_EXCLUSIVE);
        $query = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
        $query->select('name')->from('filecache')->where($query->expr()->eq('parent', $query->createNamedParameter($node->getFileInfo()->getId())))->setMaxResults(RequestLimits::limit('max_entries'));
        $result = $query->executeQuery();
        try { $rows = $result->fetchAllAssociative(); }
        finally { $result->closeCursor(); }
        // Include the collection itself. Never emit a silently truncated 207.
        if (count($rows) >= RequestLimits::limit('max_entries')) RequestLimits::fail(503, 'entry_budget');
        if ($home) HomeListing::prime($node, $target, $rows);
        if (count($node->getChildren()) + 1 > RequestLimits::limit('max_entries')) RequestLimits::fail(503, 'entry_budget');
        RequestLimits::checkpoint();
        $this->releaseListing();
    }
    public function releaseListing(): void {
        if ($this->listingLock === null) return;
        $hold = $this->listingLock; $this->listingLock = null;
        try { $hold->release(); }
        catch (\Throwable) { /* Native request-final cleanup remains the fallback. */ }
    }
    public function assemble(string $source, string $destination): ?bool {
        if (!$this->uploadRequest || !$this->server->tree->getNodeForPath($source) instanceof \OCA\DAV\Upload\FutureFile) return null;
        // A final MOVE needs Destination, so removing that header cannot select v1.
        // Invoke the native v1 listener BEFORE v2 (priority 90) checks its metadata.
        foreach ($this->server->getPlugins() as $plugin) {
            if (get_class($plugin) === \OCA\DAV\Upload\ChunkingPlugin::class) {
                \OC_Hook::emit('NextcloudGateway', 'chunk-ready', ['path' => $this->scope->relative($destination)]);
                return $plugin->beforeMove($source, $destination);
            }
        }
        throw new Exception\ServiceUnavailable('Native chunk assembly plugin is unavailable');
    }
    private function invoke(string $method, RequestInterface $request, ResponseInterface $response): bool {
        try {
            RequestLimits::checkpoint();
            // Keep Sabre's normal HTTP implementation and all its native lifecycle events.
            $this->server->getPlugin('core')->$method($request, $response);
            $this->guard->verify();
            RequestLimits::checkpoint();
            if ($this->guard->committedTag !== null) {
                $response->setHeader('ETag', $this->guard->committedTag);
                $response->setHeader('OC-ETag', $this->guard->committedTag);
            }
            return false;
        } catch (\Throwable $error) {
            for ($e = $error; $e !== null; $e = $e->getPrevious()) {
                if ($e instanceof GuardFailure) {
                    throw match ($e->status) {
                        412 => new Exception\PreconditionFailed($e->getMessage()),
                        423 => new Exception\Locked(),
                        default => new Exception\ServiceUnavailable($e->getMessage()),
                    };
                }
            }
            throw $error;
        } finally {
            $this->guard->close();
        }
    }

    public function releaseRead(): void {
        if ($this->readLock === null) return;
        $target = $this->readLock;
        $this->readLock = null;
        try {
            Scope::unlockRead($target);
        } catch (\Throwable) {
            // Never replace a primary failure (or append an error to a sent body).
            // Core's request-final releaseAll remains responsible for leftover holds.
            try {
                \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('Gateway read-lock cleanup failed', ['app' => 'nextcloud_gateway']);
            } catch (\Throwable) { /* Shutdown cleanup must never throw, even if logging fails. */ }
        }
    }
}
