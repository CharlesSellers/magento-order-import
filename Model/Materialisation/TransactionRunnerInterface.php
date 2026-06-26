<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Runs a unit of work inside a single database transaction: commit on success, roll back and rethrow on
 * any exception. Magento-free so {@see OrderMaterialiser} is unit-testable; the real implementation
 * ({@see MagentoTransactionRunner}) uses the Magento resource connection (whose nested-transaction
 * counter lets the order save and the ledger update commit atomically).
 */
interface TransactionRunnerInterface
{
    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
