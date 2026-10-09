<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Api;

use Venuno\OrderImport\Api\Data\OrderImportRequestInterface;
use Venuno\OrderImport\Api\Data\OrderSyncResultInterface;

/**
 * POST /V1/venuno/orders/sync — Venuno's "order updated" delivery for an already-imported order.
 *
 * Same request body as /orders/import (replay_key + source identity + order). The ledger row for the
 * replay_key must be imported. When a read-only legacy database is configured the module re-reads the
 * order from it (authoritative, consistent snapshot); otherwise the payload's complete order history is
 * used. Plans only unless `order_sync.api_apply` is enabled. Terminal refusals are HTTP 422, races 503.
 */
interface OrderSyncInterface
{
    /**
     * @param \Venuno\OrderImport\Api\Data\OrderImportRequestInterface $request
     * @return \Venuno\OrderImport\Api\Data\OrderSyncResultInterface
     */
    public function sync(OrderImportRequestInterface $request): OrderSyncResultInterface;
}
