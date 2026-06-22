<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Venuno\OrderImport\Api\CapabilitiesInterface;
use Venuno\OrderImport\Api\Data\CapabilitiesResultInterface;
use Venuno\OrderImport\Api\Data\CapabilitiesResultInterfaceFactory;

class Capabilities implements CapabilitiesInterface
{
    public function __construct(
        private readonly CapabilitiesResultInterfaceFactory $resultFactory,
        private readonly TokenAuthenticator $authenticator
    ) {
    }

    public function get(): CapabilitiesResultInterface
    {
        $this->authenticator->authenticate();

        // Release 0.1: the contract exists; order import does not yet. This flag flips to true only when a
        // future release actually accepts inbound orders.
        return $this->resultFactory->create()->setOrderImport(false);
    }
}
