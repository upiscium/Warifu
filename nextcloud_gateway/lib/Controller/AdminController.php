<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Controller;

use OCA\NextcloudGateway\Service\GatewayPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

final class AdminController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IURLGenerator $urlGenerator,
    ) { parent::__construct($appName, $request); }

    public function save(string $mode = ''): Response {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) return new Response(403);
        if (!in_array($mode, ['all', 'selected', 'off'], true)) return new Response(400);
        GatewayPolicy::setMode($this->config, $mode);
        return new RedirectResponse($this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'server']) . '#nextcloud-gateway-admin');
    }
}
