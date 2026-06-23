<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api\Data;

/**
 * Result of POST /V1/venuno/orders/import. Serialises to, e.g.:
 *
 * {
 *   "accepted": true,
 *   "duplicate": false,
 *   "replay_key": "magento:…",
 *   "import_status": "pending",
 *   "magento_order_id": 0,
 *   "message": "Import recorded."
 * }
 *
 * `duplicate=true` means the replay_key was already recorded — a first-write-wins no-op. The HTTP
 * status is 200 in both cases; the client distinguishes "created" from "duplicate" via `duplicate`.
 * `magento_order_id` is 0 until a future release materialises the staged import into a native order.
 */
interface OrderImportResultInterface
{
    public const ACCEPTED = 'accepted';
    public const DUPLICATE = 'duplicate';
    public const REPLAY_KEY = 'replay_key';
    public const IMPORT_STATUS = 'import_status';
    public const MAGENTO_ORDER_ID = 'magento_order_id';
    public const MESSAGE = 'message';

    /** @return bool */
    public function getAccepted(): bool;
    /** @param bool $value @return $this */
    public function setAccepted(bool $value): OrderImportResultInterface;

    /** @return bool */
    public function getDuplicate(): bool;
    /** @param bool $value @return $this */
    public function setDuplicate(bool $value): OrderImportResultInterface;

    /** @return string */
    public function getReplayKey(): string;
    /** @param string $value @return $this */
    public function setReplayKey(string $value): OrderImportResultInterface;

    /** @return string */
    public function getImportStatus(): string;
    /** @param string $value @return $this */
    public function setImportStatus(string $value): OrderImportResultInterface;

    /** @return int */
    public function getMagentoOrderId(): int;
    /** @param int $value @return $this */
    public function setMagentoOrderId(int $value): OrderImportResultInterface;

    /** @return string */
    public function getMessage(): string;
    /** @param string $value @return $this */
    public function setMessage(string $value): OrderImportResultInterface;
}
