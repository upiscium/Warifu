<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\NextcloudGateway\Dav\GatewayPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @implements IEventListener<SabrePluginAddEvent> */
final class DavListener implements IEventListener {
    public function handle(Event $event): void {
        if ($event instanceof SabrePluginAddEvent) {
            $event->getServer()->addPlugin(new GatewayPlugin());
        }
    }
}
