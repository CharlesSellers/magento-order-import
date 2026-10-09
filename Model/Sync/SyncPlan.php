<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/**
 * The exact, reviewable change set for one already-imported order. Produced by {@see SyncPlanner}
 * (pure) and executed by {@see SyncApplier}. Empty updates + inserts means the order already matches.
 */
final class SyncPlan
{
    /**
     * @param list<array{table:string,key:string,id:int,fields:array<string,array{from:mixed,to:mixed}>}> $updates
     * @param list<array{kind:string,parent:int|null,source:array}> $inserts
     * @param array<string,int> $itemMap legacy item_id => destination item_id
     * @param array<string,int> $invoiceMap legacy invoice entity_id => destination invoice entity_id (existing)
     * @param list<string> $categories
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly int $legacyOrderId,
        public readonly int $magentoOrderId,
        public readonly string $incrementId,
        public readonly int $storeId,
        public readonly string $legacyUpdatedAt,
        public readonly string $destinationFingerprint,
        public readonly string $targetState,
        public readonly string $targetStatus,
        public readonly array $updates,
        public readonly array $inserts,
        public readonly array $itemMap,
        public readonly array $invoiceMap,
        public readonly array $categories,
        public readonly array $warnings
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->updates === [] && $this->inserts === [];
    }

    /** Serialisable summary for reports, the API and the audit trail. */
    public function summary(): array
    {
        $inserts = [];
        foreach ($this->inserts as $insert) {
            $source = $insert['source'];
            $inserts[] = ['kind' => $insert['kind'], 'increment_id' => $source['increment_id'] ?? null,
                'track_number' => $source['track_number'] ?? null, 'created_at' => $source['created_at'] ?? null,
                'items' => isset($source['items']) ? count($source['items']) : null,
                'tracks' => isset($source['tracks']) ? count($source['tracks']) : null];
        }
        return [
            'legacy_order_id' => $this->legacyOrderId,
            'magento_order_id' => $this->magentoOrderId,
            'increment_id' => $this->incrementId,
            'store_id' => $this->storeId,
            'legacy_updated_at' => $this->legacyUpdatedAt,
            'target' => $this->targetState . '/' . $this->targetStatus,
            'categories' => $this->categories,
            'warnings' => $this->warnings,
            'updates' => $this->updates,
            'inserts' => $inserts,
        ];
    }
}
