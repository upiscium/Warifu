<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Migration;

use OCA\NextcloudGateway\Service\GatewayPolicy;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Install hook, not a post-upgrade default: never expand a previous installation. */
final class InitializeScope implements IRepairStep {
    public function __construct(private IConfig $config) {}
    public function getName(): string { return 'Initialize Warifu scope for a new installation'; }
    public function run(IOutput $output): void {
        foreach (['installed_version', 'scope_mode', 'experimental_enabled'] as $key) {
            if ($this->config->getAppValue('nextcloud_gateway', $key, '') !== '') return;
        }
        GatewayPolicy::setMode($this->config, 'all');
    }
}
