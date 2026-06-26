<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Turns a staged `venuno_order_import` row into a native Magento sales order — the v0.3 materialisation
 * step. Pure orchestration over four injected collaborators (all Magento-free interfaces or pure
 * classes), so the full state machine is unit-testable without a Magento runtime.
 *
 * Guarantees:
 *  - **Idempotent** — a row already carrying a magento_order_id returns it with no second order; a lost
 *    claim resolves to the existing order or "in progress".
 *  - **Replayable** — a `failed` (or `pending`) row can be re-materialised; because a failed attempt is
 *    rolled back, no orphan order exists, so the replay creates exactly one.
 *  - **Transactional** — the native order save and the ledger `markImported` commit atomically; either
 *    both land or neither does.
 *  - **Partial-failure safe** — a terminal data error (bad payload, unknown SKU) or a transient save
 *    error is recorded on the row (`failed` + message) and surfaced typed, never leaving a half-order.
 */
class OrderMaterialiser
{
    public function __construct(
        private readonly ImportRowStoreInterface $store,
        private readonly OrderDraftBuilder $builder,
        private readonly NativeOrderGatewayInterface $gateway,
        private readonly TransactionRunnerInterface $transaction
    ) {
    }

    /**
     * @throws MaterialisationException terminal (retryable=false) or transient (retryable=true)
     */
    public function materialise(string $replayKey): MaterialisationResult
    {
        $row = $this->store->find($replayKey);
        if ($row === null) {
            throw new MaterialisationException(
                'No staged import found for replay_key.',
                MaterialisationException::REASON_NO_STAGED_ROW,
                false
            );
        }

        // Idempotent: already materialised — return the existing order, create nothing.
        $existingOrderId = (int) ($row['magento_order_id'] ?? 0);
        if ($existingOrderId > 0) {
            return MaterialisationResult::alreadyMaterialised($existingOrderId);
        }

        // Validate + map BEFORE claiming so a terminal data error is recorded without holding the claim.
        try {
            $draft = $this->builder->fromImportRow($row);
        } catch (MaterialisationException $e) {
            $this->store->markFailed($replayKey, $e->getMessage());
            throw $e;
        }

        // Optimistic claim (pending|failed → materialising). A lost claim means a concurrent worker owns
        // it: return its order if it has finished, else report in-progress (the caller retries).
        if (!$this->store->claimForMaterialisation($replayKey)) {
            $fresh = $this->store->find($replayKey);
            $freshOrderId = (int) ($fresh['magento_order_id'] ?? 0);
            return $freshOrderId > 0
                ? MaterialisationResult::alreadyMaterialised($freshOrderId)
                : MaterialisationResult::inProgress();
        }

        try {
            $orderId = $this->transaction->run(function () use ($draft, $replayKey): int {
                $orderId = $this->gateway->place($draft);
                // Same transaction as the order save → atomic: either both commit or both roll back.
                $this->store->markImported($replayKey, $orderId);
                return $orderId;
            });
        } catch (MaterialisationException $e) {
            // Terminal data error from the gateway (e.g. unknown SKU). Order rolled back; record + rethrow.
            $this->store->markFailed($replayKey, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            // Transient failure (DB/save). Order rolled back; record + surface as retryable.
            $this->store->markFailed($replayKey, $e->getMessage());
            throw new MaterialisationException(
                'Order creation failed: ' . $e->getMessage(),
                MaterialisationException::REASON_ORDER_CREATE_FAILED,
                true,
                $e
            );
        }

        return MaterialisationResult::created((int) $orderId);
    }
}
