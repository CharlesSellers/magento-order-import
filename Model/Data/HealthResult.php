<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Venuno\OrderImport\Api\Data\HealthResultInterface;

class HealthResult extends AbstractSimpleObject implements HealthResultInterface
{
    public function getStatus(): string
    {
        return (string) $this->_get(self::STATUS);
    }

    public function setStatus(string $status): HealthResultInterface
    {
        return $this->setData(self::STATUS, $status);
    }
}
