<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api\Data;

/**
 * Result of GET /V1/venuno/health. Serialises to: {"status":"ok"}.
 */
interface HealthResultInterface
{
    public const STATUS = 'status';

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): HealthResultInterface;
}
