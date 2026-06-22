<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api\Data;

/**
 * Result of GET /V1/venuno/capabilities. Serialises to: {"order_import":false}.
 *
 * New capabilities are added as additional boolean getters; existing keys never change meaning, so a
 * Venuno client can read this map forward-compatibly (unknown keys ignored, missing keys treated false).
 */
interface CapabilitiesResultInterface
{
    public const ORDER_IMPORT = 'order_import';

    /**
     * Whether this release accepts inbound order import. False in Release 0.1.
     *
     * @return bool
     */
    public function getOrderImport(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setOrderImport(bool $enabled): CapabilitiesResultInterface;
}
