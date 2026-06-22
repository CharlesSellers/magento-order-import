<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api;

/**
 * Liveness + authentication probe for Venuno connection verification.
 *
 * Authenticated with the Venuno per-environment token (see {@see \Venuno\OrderImport\Model\TokenAuthenticator}).
 */
interface HealthInterface
{
    /**
     * Report that the module is installed, reachable and the supplied Venuno token is valid.
     *
     * @return \Venuno\OrderImport\Api\Data\HealthResultInterface
     */
    public function get(): \Venuno\OrderImport\Api\Data\HealthResultInterface;
}
