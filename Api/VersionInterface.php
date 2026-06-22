<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api;

/**
 * Report the module and host Magento versions so Venuno can assert compatibility.
 *
 * Authenticated with the Venuno per-environment token.
 */
interface VersionInterface
{
    /**
     * @return \Venuno\OrderImport\Api\Data\VersionResultInterface
     */
    public function get(): \Venuno\OrderImport\Api\Data\VersionResultInterface;
}
