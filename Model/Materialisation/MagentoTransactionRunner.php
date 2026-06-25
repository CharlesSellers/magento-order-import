<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

use Magento\Framework\App\ResourceConnection;

/**
 * {@see TransactionRunnerInterface} on the Magento default connection. Magento's PDO adapter counts
 * nested transactions, so the native order save (which begins its own transaction internally) and the
 * ledger `markImported` run inside this outer transaction and commit together — the atomicity
 * materialisation depends on. Integration-tested (needs a live Magento connection).
 */
class MagentoTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function run(callable $work): mixed
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $connection->commit();
            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
