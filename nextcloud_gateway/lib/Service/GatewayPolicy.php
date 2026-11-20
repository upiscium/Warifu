<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

use OCP\IConfig;

/** Installation-wide DAV scope. Never changes Nextcloud access rights. */
final class GatewayPolicy {
    private const APP = 'nextcloud_gateway';
    public static function mode(IConfig $config): string {
        $mode = $config->getAppValue(self::APP, 'scope_mode', '');
        // Existing installations without the new key retain their exact opt-in semantics.
        if ($mode === '') return $config->getAppValue(self::APP, 'experimental_enabled', 'no') === 'yes' ? 'selected' : 'off';
        return in_array($mode, ['all', 'selected', 'off'], true) ? $mode : 'off';
    }

    public static function setMode(IConfig $config, string $mode): void {
        if (!in_array($mode, ['all', 'selected', 'off'], true)) throw new \InvalidArgumentException('Invalid Warifu scope mode');
        $config->setAppValue(self::APP, 'scope_mode', $mode);
    }
}
