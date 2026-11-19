<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Files\FilesHome;
use OCP\Constants;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager;
use Sabre\DAV\Exception;

/** Bounded name snapshot for the home root, which native View deliberately does not lock. */
final class HomeListing {
    /** Scope::target and Runtime::verify must have qualified this ordinary local home first. */
    public static function prime(Directory $node, array $target, array $rows): void {
        if (get_class($node) !== FilesHome::class || !in_array($target['hookPath'], ['', '/'], true) || $target['path'] !== 'files') {
            throw new Exception\ServiceUnavailable('Unsupported native home listing');
        }
        // Native getChildren returns its cache before checking readability. Keep
        // that check explicit before installing our bounded cache.
        if (!$node->getFileInfo()->isReadable()) throw new Exception\Forbidden('No read permissions');
        if (count($rows) >= RequestLimits::limit('max_entries')) RequestLimits::fail(503, 'entry_budget');
        $user = Server::get(IUserSession::class)->getUser();
        $sharingDisabled = Server::get(IManager::class)->sharingDisabledForUser($user?->getUID());
        $children = [];
        foreach ($rows as $row) {
            RequestLimits::checkpoint();
            $name = $row['name'] ?? null;
            if (!is_string($name) || $name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
                throw new Exception\ServiceUnavailable('Unsupported native home entry');
            }
            // Resolve only the selected names, using the same View path checks
            // as Directory::getChild. Do not enumerate the whole native folder.
            $target['view']->verifyPath($target['hookPath'], $name, true);
            $info = $target['view']->getFileInfo('/' . $name);
            if ($info === false) throw new Exception\NotFound('Home entry disappeared during bounded listing');
            if ($info->getStorage() !== $target['storage']) throw new Exception\ServiceUnavailable('Bounded home listing cannot include submounts');
            // View::getDirectoryContent applies this mask, but getFileInfo does
            // not. Preserve native listing permissions without persisting them.
            if ($sharingDisabled) {
                $data = $info instanceof \ArrayAccess ? $info : $info->getData();
                if (!$data instanceof \ArrayAccess) throw new Exception\ServiceUnavailable('Unsupported native home metadata');
                $data['permissions'] = $info->getPermissions() & ~Constants::PERMISSION_SHARE;
            }
            $children[] = $node->getChild($name, $info);
        }
        RequestLimits::checkpoint();
        // Directory and FilesHome sources are fingerprinted. Publish only after
        // every selected child resolved; never leave a partial successful cache.
        (new \ReflectionProperty(Directory::class, 'dirContent'))->setValue($node, $children);
    }
}
