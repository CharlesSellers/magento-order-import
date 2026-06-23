<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api;

use Venuno\OrderImport\Api\Data\OrderImportRequestInterface;
use Venuno\OrderImport\Api\Data\OrderImportResultInterface;

/**
 * POST /V1/venuno/orders/import — the idempotent intake for replicated orders.
 *
 * Authenticated with the Venuno per-environment token. Enforces first-write-wins idempotency on
 * `replay_key`: a repeat of the same order is a no-op that returns the existing record. In this
 * release the order is validated and staged with its full store-aware identity; materialising it into
 * a native Magento sales order is a later release (see docs/adr/ADR-0003-order-import-intake.md).
 */
interface OrderImportInterface
{
    /**
     * @param OrderImportRequestInterface $request
     * @return OrderImportResultInterface
     */
    public function import(OrderImportRequestInterface $request): OrderImportResultInterface;
}
