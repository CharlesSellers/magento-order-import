<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Materialisation;

/** Validated Magento header provenance, not a claim of complete invoice/fulfilment replication. */
final class SourceOrderMetadata
{
    /**
     * Exact legacy (state, status) pairs that are not valid Magento states but have one verified Magento
     * equivalent. Only the STATE is normalised; the status is preserved verbatim.
     *
     * Jangro legacy (Magento 2, store 1): the amasty_stripe payment method writes state `pending` with
     * status `pending`. `pending` is not a Magento order state (not an Order::STATE_* constant and not in
     * the legacy or destination sales_order_status_state), and status `pending` is assigned only to state
     * `new` (is_default=1) on both legacy and destination. Every other state, and `pending` with any other
     * status, is handled exactly as before.
     */
    private const LEGACY_STATE_NORMALISATION = ['pending' => ['pending' => 'new']];

    private function __construct(
        public readonly string $incrementId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $state,
        public readonly string $status,
        public readonly string $sourceState
    ) {
    }

    public static function fromDraft(OrderDraft $draft): self
    {
        $h = $draft->sourceHeader;
        if ($draft->sourcePlatform !== 'magento' || $draft->storeId <= 0
            || preg_match('/^[1-9][0-9]*$/D', $draft->sourceEntityId) !== 1) {
            self::invalid('Metadata preservation requires a Magento source entity and an exact destination store.');
        }
        $number = self::text($h, 'increment_id', 50);
        if ($number !== $draft->sourceIncrementId) {
            self::invalid('Source order number differs between the header and import identity.');
        }
        foreach (['entity_id' => $draft->sourceEntityId, 'increment_id' => $number, 'store_id' => (string) $draft->storeId] as $key => $expected) {
            foreach ([$draft->sourcePayloadIdentity, $h] as $identity) {
                if (array_key_exists($key, $identity)
                    && (!is_scalar($identity[$key]) || (string) $identity[$key] !== $expected)) {
                    self::invalid('Source ' . $key . ' differs between the payload and import identity.');
                }
            }
        }
        $created = self::date($h, 'created_at');
        $updated = self::date($h, 'updated_at');
        if ($updated < $created) {
            self::invalid('Source updated_at precedes created_at.');
        }
        if ($draft->sourceOriginalCreatedAt !== null && $draft->sourceOriginalCreatedAt !== $created) {
            self::invalid('Source created_at differs between the header and import identity.');
        }
        $sourceState = self::text($h, 'state', 32);
        $status = self::text($h, 'status', 32);
        $state = self::normaliseState($sourceState, $status);
        if (!in_array($state, ['new', 'pending_payment', 'processing', 'complete', 'closed', 'canceled', 'holded', 'payment_review'], true)) {
            self::invalid('Source order state is not a supported Magento state.');
        }
        $currency = self::text($h, 'order_currency_code', 3);
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $currency !== $draft->currencyCode) {
            self::invalid('Source order currency is missing, malformed or inconsistent with totals.');
        }
        return new self($number, $created, $updated, $state, $status, $sourceState);
    }

    /** The destination state for a legacy (state, status) pair; unchanged unless an exact verified pair applies. */
    public static function normaliseState(string $state, string $status): string
    {
        return self::LEGACY_STATE_NORMALISATION[$state][$status] ?? $state;
    }

    public function stateWasNormalised(): bool
    {
        return $this->state !== $this->sourceState;
    }

    private static function text(array $values, string $key, int $max): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '' || trim($value) !== $value
            || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            self::invalid('Source header ' . $key . ' is missing or invalid.');
        }
        return $value;
    }

    private static function date(array $header, string $key): string
    {
        $value = self::text($header, $key, 19);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value || $value < '1970-01-01 00:00:01') {
            self::invalid('Source header ' . $key . ' must be a valid Magento UTC timestamp.');
        }
        return $value;
    }

    private static function invalid(string $message): never
    {
        throw new MaterialisationException($message, MaterialisationException::REASON_SOURCE_METADATA_INVALID, false);
    }
}
