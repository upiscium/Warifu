<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\NextcloudGateway\Service;

use OCP\IConfig;

final class SizeLimits {
    private const APP = 'nextcloud_gateway';
    private const KEY = 'size_limits_gib';

    private static function parse(string $input): int {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $input) || (int)$input > Budget::MAX_GIB) {
            throw new \InvalidArgumentException('Invalid whole-GiB limit');
        }
        return (int)$input;
    }

    public static function save(IConfig $config, string $fileGiB, string $hashGiB): void {
        $pair = ['fileGiB' => self::parse($fileGiB), 'hashGiB' => self::parse($hashGiB)];
        $config->setAppValue(self::APP, self::KEY, json_encode($pair, JSON_THROW_ON_ERROR));
    }

    public static function configured(IConfig $config): array {
        $raw = $config->getAppValue(self::APP, self::KEY, '');
        if ($raw === '') return ['fileGiB' => intdiv(Budget::DEFAULTS['max_file_bytes'], Budget::GIB),
                                'hashGiB' => intdiv(Budget::DEFAULTS['max_hash_bytes'], Budget::GIB)];
        $pair = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($pair) || count($pair) !== 2) throw new \InvalidArgumentException('Invalid size limits');
        foreach (['fileGiB', 'hashGiB'] as $key) {
            if (!is_int($pair[$key] ?? null) || $pair[$key] < 1 || $pair[$key] > Budget::MAX_GIB) throw new \InvalidArgumentException('Invalid size limits');
        }
        return $pair;
    }

    /** Enforce config.php (bytes) as a ceiling; never round legacy byte-sized caps up. */
    public static function serverLimits(IConfig $config, array $global): array {
        new Budget($global); // Validate before clamping; malformed config must not silently pass.
        $pair = self::configured($config);
        foreach (['max_file_bytes'=>'fileGiB', 'max_hash_bytes'=>'hashGiB'] as $bytes=>$key) {
            $global[$bytes] = min($global[$bytes] ?? Budget::MAX_BYTES, $pair[$key] * Budget::GIB);
        }
        return $global;
    }
}
