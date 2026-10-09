<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Venuno\OrderImport\Api\Data\OrderSyncResultInterface;

class OrderSyncResult extends AbstractSimpleObject implements OrderSyncResultInterface
{
    public function getAccepted(): bool
    {
        return (bool) $this->_get(self::ACCEPTED);
    }

    public function setAccepted(bool $value): OrderSyncResultInterface
    {
        return $this->setData(self::ACCEPTED, $value);
    }

    public function getStatus(): string
    {
        return (string) $this->_get(self::STATUS);
    }

    public function setStatus(string $value): OrderSyncResultInterface
    {
        return $this->setData(self::STATUS, $value);
    }

    public function getMagentoOrderId(): int
    {
        return (int) $this->_get(self::MAGENTO_ORDER_ID);
    }

    public function setMagentoOrderId(int $value): OrderSyncResultInterface
    {
        return $this->setData(self::MAGENTO_ORDER_ID, $value);
    }

    public function getAuditId(): int
    {
        return (int) $this->_get(self::AUDIT_ID);
    }

    public function setAuditId(int $value): OrderSyncResultInterface
    {
        return $this->setData(self::AUDIT_ID, $value);
    }

    public function getCategories(): string
    {
        return (string) $this->_get(self::CATEGORIES);
    }

    public function setCategories(string $value): OrderSyncResultInterface
    {
        return $this->setData(self::CATEGORIES, $value);
    }

    public function getMessage(): string
    {
        return (string) $this->_get(self::MESSAGE);
    }

    public function setMessage(string $value): OrderSyncResultInterface
    {
        return $this->setData(self::MESSAGE, $value);
    }
}
