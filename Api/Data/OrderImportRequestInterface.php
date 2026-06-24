<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api\Data;

/**
 * The body of POST /V1/venuno/orders/import — the import-domain contract (see
 * docs/adr/ADR-0002-import-domain-contract.md). Carries the store-aware composite identity, the replay
 * protection (`replay_key` + `payload_hash`) and the normalised order payload.
 *
 * Identity is NEVER keyed on increment_id alone — it is per-store and collides across storefronts. The
 * idempotency anchor is `replay_key` (derived from the globally-unique source entity_id).
 */
interface OrderImportRequestInterface
{
    public const REPLAY_KEY = 'replay_key';
    public const PAYLOAD_HASH = 'payload_hash';
    public const SOURCE_CONNECTION_ID = 'source_connection_id';
    public const SOURCE_PLATFORM = 'source_platform';
    public const SOURCE_BASE_URL = 'source_base_url';
    public const SOURCE_STORE_ID = 'source_store_id';
    public const SOURCE_STORE_CODE = 'source_store_code';
    public const SOURCE_WEBSITE_ID = 'source_website_id';
    public const SOURCE_ORDER_ENTITY_ID = 'source_order_entity_id';
    public const SOURCE_ORDER_INCREMENT_ID = 'source_order_increment_id';
    public const SOURCE_ORDER_DISPLAY_NUMBER = 'source_order_display_number';
    public const ORIGINAL_CREATED_AT = 'original_created_at';
    public const ORDER = 'order';

    /** @return string */
    public function getReplayKey(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setReplayKey(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getPayloadHash(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setPayloadHash(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceConnectionId(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceConnectionId(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourcePlatform(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourcePlatform(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceBaseUrl(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceBaseUrl(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceStoreId(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceStoreId(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceStoreCode(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceStoreCode(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceWebsiteId(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceWebsiteId(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceOrderEntityId(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceOrderEntityId(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceOrderIncrementId(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceOrderIncrementId(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getSourceOrderDisplayNumber(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSourceOrderDisplayNumber(string $value): OrderImportRequestInterface;

    /** @return string */
    public function getOriginalCreatedAt(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setOriginalCreatedAt(string $value): OrderImportRequestInterface;

    /**
     * The normalised order payload as a JSON string (header, addresses, items, totals, payment metadata).
     * Carried opaquely at this staging release; the client owns the structure.
     *
     * @return string
     */
    public function getOrder(): string;

    /**
     * @param string $order
     * @return $this
     */
    public function setOrder(string $order): OrderImportRequestInterface;
}
