<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/** Result of syncing (or planning) one order: noop | planned | applied | failed. */
final class SyncOutcome
{
    public function __construct(
        public readonly int $legacyOrderId,
        public readonly string $status,
        public readonly ?SyncPlan $plan = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
        public readonly bool $retryable = false,
        public readonly ?int $auditId = null,
        public readonly int $magentoOrderId = 0,
        public readonly string $incrementId = '',
        public readonly int $storeId = 0
    ) {
    }

    /** Report bucket: the failure reason, or the change categories, or "in_sync". */
    public function categories(): array
    {
        if ($this->status === 'failed') {
            return ['failed:' . $this->reason];
        }
        return $this->plan === null || $this->plan->isEmpty() ? ['in_sync'] : $this->plan->categories;
    }

    public function toArray(): array
    {
        return [
            'legacy_order_id' => $this->legacyOrderId,
            'magento_order_id' => $this->magentoOrderId ?: ($this->plan?->magentoOrderId ?? 0),
            'increment_id' => $this->incrementId !== '' ? $this->incrementId : ($this->plan?->incrementId ?? ''),
            'store_id' => $this->storeId ?: ($this->plan?->storeId ?? 0),
            'status' => $this->status,
            'categories' => $this->categories(),
            'reason' => $this->reason,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'audit_id' => $this->auditId,
            'plan' => $this->plan?->summary(),
        ];
    }
}
