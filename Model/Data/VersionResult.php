<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Venuno\OrderImport\Api\Data\VersionResultInterface;

class VersionResult extends AbstractSimpleObject implements VersionResultInterface
{
    public function getModuleVersion(): string
    {
        return (string) $this->_get(self::MODULE_VERSION);
    }

    public function setModuleVersion(string $version): VersionResultInterface
    {
        return $this->setData(self::MODULE_VERSION, $version);
    }

    public function getMagentoVersion(): string
    {
        return (string) $this->_get(self::MAGENTO_VERSION);
    }

    public function setMagentoVersion(string $version): VersionResultInterface
    {
        return $this->setData(self::MAGENTO_VERSION, $version);
    }

    public function getMagentoEdition(): string
    {
        return (string) $this->_get(self::MAGENTO_EDITION);
    }

    public function setMagentoEdition(string $edition): VersionResultInterface
    {
        return $this->setData(self::MAGENTO_EDITION, $edition);
    }
}
