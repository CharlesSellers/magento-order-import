<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Sync;

/**
 * A fail-closed sync refusal. `reason` is a stable machine code used by the CLI report, the API
 * response and the audit trail. `retryable` marks races (legacy or destination changed mid-sync).
 */
class SyncException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $reason, private readonly bool $retryable = false, ?\Throwable $previous = null)
    {
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
