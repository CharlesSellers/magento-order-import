<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Venuno\OrderImport\Api\Data\OrderImportRequestInterface;

class OrderImportRequest extends AbstractSimpleObject implements OrderImportRequestInterface
{
    public function getReplayKey(): string
    {
        return (string) $this->_get(self::REPLAY_KEY);
    }

    public function setReplayKey(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::REPLAY_KEY, $value);
    }

    public function getPayloadHash(): string
    {
        return (string) $this->_get(self::PAYLOAD_HASH);
    }

    public function setPayloadHash(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::PAYLOAD_HASH, $value);
    }

    public function getSourceConnectionId(): string
    {
        return (string) $this->_get(self::SOURCE_CONNECTION_ID);
    }

    public function setSourceConnectionId(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_CONNECTION_ID, $value);
    }

    public function getSourcePlatform(): string
    {
        return (string) $this->_get(self::SOURCE_PLATFORM);
    }

    public function setSourcePlatform(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_PLATFORM, $value);
    }

    public function getSourceBaseUrl(): string
    {
        return (string) $this->_get(self::SOURCE_BASE_URL);
    }

    public function setSourceBaseUrl(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_BASE_URL, $value);
    }

    public function getSourceStoreId(): string
    {
        return (string) $this->_get(self::SOURCE_STORE_ID);
    }

    public function setSourceStoreId(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_STORE_ID, $value);
    }

    public function getSourceStoreCode(): string
    {
        return (string) $this->_get(self::SOURCE_STORE_CODE);
    }

    public function setSourceStoreCode(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_STORE_CODE, $value);
    }

    public function getSourceWebsiteId(): string
    {
        return (string) $this->_get(self::SOURCE_WEBSITE_ID);
    }

    public function setSourceWebsiteId(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_WEBSITE_ID, $value);
    }

    public function getSourceOrderEntityId(): string
    {
        return (string) $this->_get(self::SOURCE_ORDER_ENTITY_ID);
    }

    public function setSourceOrderEntityId(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_ORDER_ENTITY_ID, $value);
    }

    public function getSourceOrderIncrementId(): string
    {
        return (string) $this->_get(self::SOURCE_ORDER_INCREMENT_ID);
    }

    public function setSourceOrderIncrementId(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_ORDER_INCREMENT_ID, $value);
    }

    public function getSourceOrderDisplayNumber(): string
    {
        return (string) $this->_get(self::SOURCE_ORDER_DISPLAY_NUMBER);
    }

    public function setSourceOrderDisplayNumber(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::SOURCE_ORDER_DISPLAY_NUMBER, $value);
    }

    public function getOriginalCreatedAt(): string
    {
        return (string) $this->_get(self::ORIGINAL_CREATED_AT);
    }

    public function setOriginalCreatedAt(string $value): OrderImportRequestInterface
    {
        return $this->setData(self::ORIGINAL_CREATED_AT, $value);
    }

    /**
     * @return mixed[]
     */
    public function getOrder(): array
    {
        return (array) ($this->_get(self::ORDER) ?? []);
    }

    /**
     * @param mixed[] $order
     */
    public function setOrder(array $order): OrderImportRequestInterface
    {
        return $this->setData(self::ORDER, $order);
    }
}
