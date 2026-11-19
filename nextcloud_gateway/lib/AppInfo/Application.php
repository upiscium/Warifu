<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\NextcloudGateway\Listener\DavListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
    public function __construct() { parent::__construct('nextcloud_gateway'); }
    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(SabrePluginAddEvent::class, DavListener::class);
    }
    public function boot(IBootContext $context): void {}
}
