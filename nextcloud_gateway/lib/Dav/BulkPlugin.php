<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Dav;

use OCA\DAV\BulkUpload\MultipartRequestParser;
use OCA\DAV\Connector\Sabre\Exception\EntityTooLarge;
use OCA\NextcloudGateway\Service\MutationGuard;
use OCA\NextcloudGateway\Service\Scope;
use OCA\NextcloudGateway\Service\Runtime;
use OCA\NextcloudGateway\Service\RequestLimits;
use OCA\DAV\Connector\Sabre\MtimeSanitizer;
use OCP\Files\DavUtil;
use OCP\Files\IRootFolder;
use OCP\Server as RuntimeServer;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** Bounded, scope-aware orchestration around Nextcloud's native multipart parser. */
final class BulkPlugin extends ServerPlugin {
    private const MAX_BYTES = 16777216;
    private const MAX_PARTS = 256;

    public function __construct(private readonly Scope $scope) {}

    public function getPluginName() { return 'nextcloud-gateway-bulk'; }

    public function initialize(Server $server) {
        $server->on('method:POST', [$this, 'httpPost'], 5);
    }

    public function httpPost(RequestInterface $request, ResponseInterface $response): bool {
        if (trim($request->getPath(), '/') !== 'bulk' || $this->scope->enrolledRoots() === []) return true;
        Runtime::verify();

        // Scope resolution and runtime validation precede reading or writing data.
        $user = RuntimeServer::get(\OCP\IUserSession::class)->getUser();
        if ($user === null) return true;
        $home = RuntimeServer::get(IRootFolder::class)->getUserFolder($user->getUID());
        $body = fopen('php://temp', 'w+b');
        if ($body === false) throw new \RuntimeException('Unable to buffer bulk request');
        try {
            $input = $request->getBodyAsStream();
            $bytes = 0;
            while (!feof($input)) {
                RequestLimits::checkpoint();
                $chunk = fread($input, min(65536, self::MAX_BYTES + 1 - $bytes));
                if ($chunk === false) throw new \RuntimeException('Unable to read bulk request');
                if ($chunk === '') break;
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) throw new EntityTooLarge('Bulk request exceeds the 16 MiB limit');
                if (fwrite($body, $chunk) !== strlen($chunk)) throw new \RuntimeException('Unable to buffer bulk request');
            }
            rewind($body);
            $bounded = clone $request;
            $bounded->setBody($body);
            $parser = new MultipartRequestParser($bounded, RuntimeServer::get(LoggerInterface::class));
            $results = [];
            $count = 0;
            while (true) {
                RequestLimits::checkpoint();
                try {
                    if ($parser->isAtLastBoundary()) break;
                    if (++$count > self::MAX_PARTS) throw new \InvalidArgumentException('Too many bulk parts');
                    $before = ftell($body);
                    $this->preflightPart($body, $bytes);
                    [$headers, $content] = $parser->parseNextPart();
                    if (ftell($body) <= $before) throw new \InvalidArgumentException('Multipart parser made no progress');
                    $length = $headers['content-length'] ?? null;
                    if (!is_string($length) || !ctype_digit($length) || (int)$length !== strlen($content) || (int)$length > self::MAX_BYTES) throw new \InvalidArgumentException('Invalid content length');
                } catch (\Throwable) {
                    // Like native bulk: earlier successful items remain committed.
                    return $this->respond($response, 400, $results);
                }
                $original = null;
                try {
                    $original = $headers['x-file-path'] ?? null;
                    if (!is_string($original) || $original === '') throw new \InvalidArgumentException('Missing file path');
                    foreach (['if-match', 'if-none-match', 'if-unmodified-since', 'if-modified-since', 'if-range', 'if'] as $condition) {
                        if (isset($headers[$condition])) throw new \InvalidArgumentException('Part conditions are not supported');
                    }
                    $path = ltrim($original, '/');
                    RequestLimits::file(strlen($content));
                    if (!$this->validPath($path)) throw new \InvalidArgumentException('Invalid file path');
                    $mtimeHeader = $headers['x-file-mtime'] ?? $headers['x-oc-mtime'] ?? null;
                    $mtime = $mtimeHeader === null ? null : MtimeSanitizer::sanitizeMtime($mtimeHeader);

                    $relative = $this->scope->relative('files/' . $user->getUID() . '/' . $path);
                    $guard = null;
                    try {
                        if ($relative !== null) $guard = new MutationGuard($this->scope->target($relative), 'PUT', null, null, null);
                        $node = $home->newFile($path, $content);
                        if ($guard !== null) $guard->verify();
                        $tag = $guard?->committedTag;
                    } finally {
                        if ($guard !== null) $guard->close();
                    }
                    $node->touch($mtime);
                    $node = $home->getFirstNodeById($node->getId());
                    $results[$original] = [
                        'error' => false,
                        'etag' => $tag === null ? $node->getETag() : trim($tag, '"'),
                        'fileid' => DavUtil::getDavFileId($node->getId()),
                        'permissions' => DavUtil::getDavPermissions($node, $node->getParent()),
                    ];
                } catch (\Throwable $error) {
                    $results[is_string($original) ? $original : ''] = ['error' => true, 'message' => 'Bulk part failed; re-read the target before retrying'];
                }
            }
            RequestLimits::checkpoint();
            return $this->respond($response, 200, $results);
        } catch (LimitFailure $error) {
            return $this->respond($response, $error->status, $results ?? []);
        } finally {
            fclose($body);
        }
    }

    private function respond(ResponseInterface $response, int $status, array $results): bool {
        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/json');
        $response->setBody(json_encode((object)$results, JSON_THROW_ON_ERROR));
        return false;
    }

    /** Bound lengths BEFORE the native parser can allocate using an untrusted integer. */
    private function preflightPart($stream, int $size): void {
        $start = ftell($stream);
        try {
            $boundary = fgets($stream, 8194);
            if ($boundary === false || !str_ends_with($boundary, "\r\n")) throw new \InvalidArgumentException('Invalid boundary line');
            $headers = [];
            for ($count = 0; ; $count++) {
                $line = fgets($stream, 8194);
                if ($line === "\r\n") break;
                if ($count >= 32 || $line === false || !str_ends_with($line, "\r\n") || !str_contains($line, ':')) throw new \InvalidArgumentException('Invalid part headers');
                [$key, $value] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                if (isset($headers[$key])) throw new \InvalidArgumentException('Duplicate part header');
                $headers[$key] = trim($value);
            }
            // Invalid path bytes cannot safely be used as JSON result keys.
            // Fail as a parse error, preserving only already committed results.
            if (isset($headers['x-file-path']) && preg_match('//u', $headers['x-file-path']) !== 1) throw new \InvalidArgumentException('Invalid path encoding');
            $length = $headers['content-length'] ?? '';
            if ($length === '' || !ctype_digit($length) || strlen($length) > 8 || (int)$length > self::MAX_BYTES) throw new \InvalidArgumentException('Invalid part length');
            $end = ftell($stream) + (int)$length;
            if ($end + 2 > $size || fseek($stream, $end) !== 0 || fread($stream, 2) !== "\r\n") throw new \InvalidArgumentException('Truncated or malformed part');
        } finally {
            fseek($stream, $start);
        }
    }

    private function validPath(string $path): bool {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) || preg_match('//u', $path) !== 1) return false;
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/\.(?:part|partial)$/i', $part)) return false;
        }
        return true;
    }
}
