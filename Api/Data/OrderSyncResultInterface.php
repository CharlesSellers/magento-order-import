<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Api\Data;

/**
 * Result of POST /V1/venuno/orders/sync, e.g.
 * {"accepted":true,"status":"applied","magento_order_id":236939,"audit_id":12,
 *  "categories":"state_status,invoices_added","message":"Order synced."}
 * status: noop (already matches) | planned (dry run) | applied.
 */
interface OrderSyncResultInterface
{
    public const ACCEPTED = 'accepted';
    public const STATUS = 'status';
    public const MAGENTO_ORDER_ID = 'magento_order_id';
    public const AUDIT_ID = 'audit_id';
    public const CATEGORIES = 'categories';
    public const MESSAGE = 'message';

    /** @return bool */
    public function getAccepted(): bool;

    /**
     * @param bool $value
     * @return $this
     */
    public function setAccepted(bool $value): OrderSyncResultInterface;

    /** @return string */
    public function getStatus(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setStatus(string $value): OrderSyncResultInterface;

    /** @return int */
    public function getMagentoOrderId(): int;

    /**
     * @param int $value
     * @return $this
     */
    public function setMagentoOrderId(int $value): OrderSyncResultInterface;

    /** @return int */
    public function getAuditId(): int;

    /**
     * @param int $value
     * @return $this
     */
    public function setAuditId(int $value): OrderSyncResultInterface;

    /** @return string */
    public function getCategories(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setCategories(string $value): OrderSyncResultInterface;

    /** @return string */
    public function getMessage(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setMessage(string $value): OrderSyncResultInterface;
}
