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
}
