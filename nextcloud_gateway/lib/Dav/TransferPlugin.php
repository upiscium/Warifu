<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Dav;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File;
use OCA\NextcloudGateway\Service\{Conditions, DavIf, GuardFailure, RequestLimits, Scope, TransferGuard};
use Sabre\DAV\{Exception, Server, ServerPlugin};
use Sabre\HTTP\{RequestInterface, ResponseInterface};

/** Single enrolled file transfers: native Directory operations, no pre-deletion. */
final class TransferPlugin extends ServerPlugin {
    private Server $server;
    private ?TransferGuard $guard = null;
    private array $info = [];
    private ?File $source = null;
    private ?Directory $parent = null;
    public function __construct(private readonly Scope $scope) {}
    public function getPluginName() { return 'nextcloud-gateway-transfers'; }
    public function initialize(Server $server) {
        $this->server = $server;
        $server->on('method:MOVE', [$this, 'transfer'], 85);
        $server->on('method:COPY', [$this, 'transfer'], 85);
    }

    /** True means this single-file request is handled; false leaves native routing. */
    public function prepare(RequestInterface $request, ResponseInterface $response, bool $conditional): bool {
        $sourcePath = $request->getPath();
        $destinationHeader = $request->getHeader('Destination');
        $sourceAffected = $this->scope->intersects($sourcePath);
        $destinationPath = $sourceAffected || $destinationHeader === null ? null : $this->server->calculateUri($destinationHeader);
        if (!$sourceAffected && ($destinationPath === null || !$this->scope->intersects($destinationPath))) return false;
        if ($destinationHeader === null) throw new Exception\BadRequest('Destination is required');
        $destinationPath = $this->uri($destinationHeader, $request);
        $s = $this->scope->relative($sourcePath);
        $d = $this->scope->relative($destinationPath);
        if ($s === null || $d === null) {
            if ($conditional) throw new Exception\NotImplemented('Conditional transfers require two enrolled files');
            return false;
        }
        $source = $this->server->tree->getNodeForPath($sourcePath);
        $destination = $this->server->tree->nodeExists($destinationPath) ? $this->server->tree->getNodeForPath($destinationPath) : null;
        if (!$source instanceof File || ($destination !== null && !$destination instanceof File)) {
            if ($conditional) throw new Exception\NotImplemented('Conditional collection transfers are not qualified');
            return false;
        }
        $this->info = $this->server->getCopyAndMoveInfo($request);
        $parent = $this->server->tree->getNodeForPath(dirname($destinationPath));
        if (get_class($source) !== File::class || get_class($parent) !== Directory::class) throw new Exception\ServiceUnavailable('Unsupported native transfer nodes');
        if (!$source->getFileInfo()->isReadable()) throw new Exception\NotFound('Source is not readable');
        $sourceTarget = $this->scope->target($s); $destinationTarget = $this->scope->target($d);
        if ($sourceTarget['storage'] !== $destinationTarget['storage'] || $sourceTarget['provider'] !== $destinationTarget['provider']) throw new Exception\NotImplemented('Cross-storage transfers are not qualified');
        $match = $request->getHeader('If-Match'); $none = $request->getHeader('If-None-Match');
        $lists = [];
        try {
            if ($match !== null) Conditions::parse($match);
            if ($none !== null) Conditions::parse($none);
            foreach ($request->getHeader('If') === null ? [] : DavIf::parse($request->getHeader('If')) as $group) {
                $path = $group['uri'] === null ? $sourcePath : $this->uri($group['uri'], $request);
                $which = match ($path) {
                    $sourcePath => 'source', $destinationPath => 'destination',
                    default => throw new Exception\NotImplemented('DAV If resources must be the source or destination'),
                };
                $lists[$which] = array_merge($lists[$which] ?? [], $group['lists']);
            }
        } catch (\InvalidArgumentException) { throw new Exception\BadRequest('Malformed content condition'); }
        catch (\DomainException) { throw new Exception\NotImplemented('DAV state tokens are not qualified'); }
        $this->guard = new TransferGuard($sourceTarget, $destinationTarget, $request->getMethod(), $match, $none, $request->getHeader('If-Unmodified-Since'), $lists, $this->info['destinationExists'], strtoupper($request->getHeader('Overwrite') ?? 'T') !== 'F');
        $this->source = $source; $this->parent = $parent;
        // Sabre's metadata/If parser is not our atomic check. The native hook
        // evaluates all saved conditions while both path locks are exclusive.
        foreach (['If', 'If-Match', 'If-None-Match', 'If-Unmodified-Since', 'If-Modified-Since'] as $header) $request->removeHeader($header);
        $response->setHeader('X-Nextcloud-Gateway', 'experimental-1');
        return true;
    }

    private function uri(string $uri, RequestInterface $request): string {
        if ($uri === '' || strlen($uri) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $uri) || preg_match('/%(?![0-9a-f]{2})/i', $uri)) throw new Exception\BadRequest('Invalid transfer URI');
        $parts = parse_url($uri);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new Exception\BadRequest('Invalid transfer URI');
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $origin = parse_url($request->getAbsoluteUrl());
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || $scheme !== strtolower($origin['scheme'] ?? '') || strtolower($parts['host'] ?? '') !== strtolower($origin['host'] ?? '') || ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($origin['port'] ?? ($scheme === 'https' ? 443 : 80))) throw new Exception\BadRequest('Transfer URI must have the request origin');
        }
        $path = $parts['path'] ?? '';
        if (!str_starts_with($path, '/') || str_starts_with($uri, '//') || preg_match('/%(?:2f|5c)/i', $path)) throw new Exception\BadRequest('Invalid transfer path');
        $decoded = rawurldecode($path);
        if (preg_match('//u', $decoded) !== 1 || preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded)) throw new Exception\BadRequest('Invalid transfer path');
        foreach (explode('/', substr($decoded, 1)) as $component) if ($component === '' || $component === '.' || $component === '..') throw new Exception\BadRequest('Noncanonical transfer path');
        return $this->server->calculateUri($uri);
    }

    public function transfer(RequestInterface $request, ResponseInterface $response): ?bool {
        if ($this->guard === null) return null;
        $source = $request->getPath(); $destination = $this->info['destination']; $move = $request->getMethod() === 'MOVE';
        try {
            RequestLimits::checkpoint();
            $events = $move
                ? array_merge($this->info['destinationExists'] ? [['beforeUnbind', [$destination]]] : [], [['beforeUnbind', [$source]], ['beforeBind', [$destination]], ['beforeMove', [$source, $destination]]])
                : array_merge([['beforeBind', [$destination]], ['beforeCopy', [$source, $destination]]], $this->info['destinationExists'] ? [['beforeUnbind', [$destination]]] : []);
            foreach ($events as [$event, $args]) if (!$this->server->emit($event, $args)) throw new Exception\ServiceUnavailable('Native transfer listener cancelled the operation');
            \OC_Hook::emit('NextcloudGateway', 'transfer-ready', ['path' => $this->source->getPath(), 'destination' => '/' . ltrim($this->parent->getPath() . '/' . basename($destination), '/')]);
            $method = $move ? 'moveInto' : 'copyInto';
            if (!$this->parent->$method(basename($destination), $source, $this->source)) throw new Exception\ServiceUnavailable('Native transfer handler declined');
            $this->guard->verify();
            $this->server->tree->markDirty(dirname($source));
            $this->server->tree->markDirty(dirname($destination));
            // No destination delete lifecycle is fabricated: overwrite happened
            // in native rename/copy, preserving its file identity/bookkeeping.
            $this->server->emit($move ? 'afterMove' : 'afterCopy', [$source, $destination]);
            if ($move) $this->server->emit('afterUnbind', [$source]);
            $this->server->emit('afterBind', [$destination]);
            RequestLimits::checkpoint();
            $response->setStatus($this->info['destinationExists'] ? 204 : 201);
            $response->setHeader('Content-Length', '0'); $response->setBody('');
            $response->setHeader('ETag', $this->guard->committedTag); $response->setHeader('OC-ETag', $this->guard->committedTag);
            return false;
        } catch (\Throwable $error) {
            for ($e = $error; $e !== null; $e = $e->getPrevious()) if ($e instanceof GuardFailure) throw match ($e->status) {
                412 => new Exception\PreconditionFailed($e->getMessage()), 423 => new Exception\Locked(),
                403 => new Exception\Forbidden($e->getMessage()), 413 => new LimitFailure(413, 'file_limit'),
                default => new Exception\ServiceUnavailable($e->getMessage()),
            };
            throw $error;
        } finally { $this->guard->close(); }
    }
}
