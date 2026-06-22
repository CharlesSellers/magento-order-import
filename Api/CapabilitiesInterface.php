<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api;

/**
 * Advertise which destination-side capabilities this release supports. In Release 0.1 every capability is
 * false — the contract exists, the behaviour does not yet. Venuno reads this to decide what it may attempt.
 *
 * Authenticated with the Venuno per-environment token.
 */
interface CapabilitiesInterface
{
    /**
     * @return \Venuno\OrderImport\Api\Data\CapabilitiesResultInterface
     */
    public function get(): \Venuno\OrderImport\Api\Data\CapabilitiesResultInterface;
}
