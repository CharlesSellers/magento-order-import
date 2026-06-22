<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Venuno\OrderImport\Api\Data\HealthResultInterface;
use Venuno\OrderImport\Api\Data\HealthResultInterfaceFactory;
use Venuno\OrderImport\Api\HealthInterface;

class Health implements HealthInterface
{
    private const STATUS_OK = 'ok';

    public function __construct(
        private readonly HealthResultInterfaceFactory $resultFactory,
        private readonly TokenAuthenticator $authenticator
    ) {
    }

    public function get(): HealthResultInterface
    {
        $this->authenticator->authenticate();

        return $this->resultFactory->create()->setStatus(self::STATUS_OK);
    }
}
