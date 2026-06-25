<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Outcome of {@see OrderMaterialiser::materialise()}.
 *
 * `created=true` means a native order was created on this call (a billable state change). `created=false`
 * with a positive `magentoOrderId` is an idempotent no-op (the order already existed). `status` is
 * `imported` when an order id is known, or `in_progress` when another worker holds the claim.
 */
class MaterialisationResult
{
    public function __construct(
        public readonly int $magentoOrderId,
        public readonly bool $created,
        public readonly string $status
    ) {
    }

    public static function alreadyMaterialised(int $orderId): self
    {
        return new self($orderId, false, 'imported');
    }

    public static function created(int $orderId): self
    {
        return new self($orderId, true, 'imported');
    }

    public static function inProgress(): self
    {
        return new self(0, false, 'in_progress');
    }
}
