<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * Persistence operations on the `venuno_order_import` ledger that materialisation needs. Magento-free
 * signatures so {@see OrderMaterialiser} is unit-testable; the real implementation is the
 * {@see \Venuno\OrderImport\Model\OrderImportRepository} (integration-tested).
 */
interface ImportRowStoreInterface
{
    /**
     * The recorded import row for a replay_key, or null when none exists.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $replayKey): ?array;

    /**
     * Atomically transition the row `pending|failed → materialising` (an optimistic lock). Returns true
     * only for the caller that won the claim; a concurrent caller (or an already-materialising/imported
     * row) gets false. This is what makes materialisation safe under retries and concurrency.
     */
    public function claimForMaterialisation(string $replayKey): bool;

    /** Record a successful materialisation: status=imported, magento_order_id, materialised_at. */
    public function markImported(string $replayKey, int $magentoOrderId): void;

    /** Record a failed materialisation: status=failed, error_message (so it is diagnosable + replayable). */
    public function markFailed(string $replayKey, string $error): void;
}
