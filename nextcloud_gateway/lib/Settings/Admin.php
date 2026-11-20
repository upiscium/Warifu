<?php
// SPDX-FileCopyrightText: 2026 upiscium
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\NextcloudGateway\Settings;

use OCA\NextcloudGateway\Service\GatewayPolicy;
use OCA\NextcloudGateway\Service\SizeLimits;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OC\Security\CSRF\CsrfTokenManager;

final class Admin implements ISettings {
    public function __construct(private IConfig $config, private IURLGenerator $urlGenerator, private CsrfTokenManager $csrf) {}

    public function getSection(): string { return 'server'; }
    public function getPriority(): int { return 80; }

    public function getForm(): TemplateResponse {
        return new TemplateResponse('nextcloud_gateway', 'admin', [
            'mode' => GatewayPolicy::mode($this->config),
            'limits' => SizeLimits::configured($this->config),
            'formToken' => $this->csrf->getToken()->getEncryptedValue(),
            'action' => $this->urlGenerator->linkToRoute('nextcloud_gateway.admin.save'),
            'budgetAction' => $this->urlGenerator->linkToRoute('nextcloud_gateway.admin.budget'),
        ]);
    }
}
