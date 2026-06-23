<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Venuno\OrderImport\Api\Data\CapabilitiesResultInterface;

class CapabilitiesResult extends AbstractSimpleObject implements CapabilitiesResultInterface
{
    public function getOrderImport(): bool
    {
        return (bool) $this->_get(self::ORDER_IMPORT);
    }

    public function setOrderImport(bool $enabled): CapabilitiesResultInterface
    {
        return $this->setData(self::ORDER_IMPORT, $enabled);
    }

    public function getOrderMaterialisation(): bool
    {
        return (bool) $this->_get(self::ORDER_MATERIALISATION);
    }

    public function setOrderMaterialisation(bool $enabled): CapabilitiesResultInterface
    {
        return $this->setData(self::ORDER_MATERIALISATION, $enabled);
    }

    public function getContractVersion(): string
    {
        return (string) $this->_get(self::CONTRACT_VERSION);
    }

    public function setContractVersion(string $version): CapabilitiesResultInterface
    {
        return $this->setData(self::CONTRACT_VERSION, $version);
    }

    /**
     * @return string[]
     */
    public function getImportIdentityFields(): array
    {
        return (array) ($this->_get(self::IMPORT_IDENTITY_FIELDS) ?? []);
    }

    /**
     * @param string[] $fields
     */
    public function setImportIdentityFields(array $fields): CapabilitiesResultInterface
    {
        return $this->setData(self::IMPORT_IDENTITY_FIELDS, $fields);
    }

    /**
     * @return string[]
     */
    public function getImportReplayFields(): array
    {
        return (array) ($this->_get(self::IMPORT_REPLAY_FIELDS) ?? []);
    }

    /**
     * @param string[] $fields
     */
    public function setImportReplayFields(array $fields): CapabilitiesResultInterface
    {
        return $this->setData(self::IMPORT_REPLAY_FIELDS, $fields);
    }
}
