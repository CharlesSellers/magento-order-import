<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Venuno\OrderImport\Api\Data\OrderImportResultInterface;

class OrderImportResult extends AbstractSimpleObject implements OrderImportResultInterface
{
    public function getAccepted(): bool
    {
        return (bool) $this->_get(self::ACCEPTED);
    }

    public function setAccepted(bool $value): OrderImportResultInterface
    {
        return $this->setData(self::ACCEPTED, $value);
    }

    public function getDuplicate(): bool
    {
        return (bool) $this->_get(self::DUPLICATE);
    }

    public function setDuplicate(bool $value): OrderImportResultInterface
    {
        return $this->setData(self::DUPLICATE, $value);
    }

    public function getReplayKey(): string
    {
        return (string) $this->_get(self::REPLAY_KEY);
    }

    public function setReplayKey(string $value): OrderImportResultInterface
    {
        return $this->setData(self::REPLAY_KEY, $value);
    }

    public function getImportStatus(): string
    {
        return (string) $this->_get(self::IMPORT_STATUS);
    }

    public function setImportStatus(string $value): OrderImportResultInterface
    {
        return $this->setData(self::IMPORT_STATUS, $value);
    }

    public function getMagentoOrderId(): int
    {
        return (int) $this->_get(self::MAGENTO_ORDER_ID);
    }

    public function setMagentoOrderId(int $value): OrderImportResultInterface
    {
        return $this->setData(self::MAGENTO_ORDER_ID, $value);
    }

    public function getMessage(): string
    {
        return (string) $this->_get(self::MESSAGE);
    }

    public function setMessage(string $value): OrderImportResultInterface
    {
        return $this->setData(self::MESSAGE, $value);
    }
}
