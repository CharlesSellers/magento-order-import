<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/**
 * A materialisation failure with a stable machine `reason` and a `retryable` flag. Pure PHP (no Magento
 * dependency) so the materialisation core is unit-testable without a Magento runtime.
 *
 * `retryable=false` is a terminal data problem (bad payload, unknown SKU) — the client must fix the data
 * and replay; the module maps it to HTTP 422. `retryable=true` is a transient failure (a Magento save
 * error) — the client may retry as-is; the module maps it to HTTP 5xx.
 */
class MaterialisationException extends \RuntimeException
{
    public const REASON_NO_STAGED_ROW = 'no_staged_row';
    public const REASON_BAD_PAYLOAD = 'bad_payload';
    public const REASON_MISSING_FIELD = 'missing_field';
    public const REASON_NO_ITEMS = 'no_items';
    public const REASON_UNKNOWN_SKU = 'unknown_sku';
    public const REASON_ORDER_CREATE_FAILED = 'order_create_failed';

    public function __construct(
        string $message,
        private readonly string $reason,
        private readonly bool $retryable,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
