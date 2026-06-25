<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\App\DeploymentConfig;

/**
 * Whether the destination materialises staged imports into native Magento sales orders.
 *
 * Opt-in, per-environment, in `app/etc/env.php` (alongside the Venuno token) so staging can prove before
 * production and the default is the safe v0.2 staging behaviour:
 *
 *     'venuno' => [
 *         'order_import' => [
 *             'token'       => '…',
 *             'materialise' => true,   // create native orders (default: false = stage only)
 *         ],
 *     ],
 *
 * `capabilities.order_materialisation` reflects this flag, so a Venuno client discovers honestly whether
 * a store creates native orders.
 */
class MaterialisationConfig
{
    public const CONFIG_PATH = 'venuno/order_import/materialise';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function isEnabled(): bool
    {
        $value = $this->deploymentConfig->get(self::CONFIG_PATH);

        // Tolerate bool, "1"/"true"/"yes" string, or int from env.php.
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}
