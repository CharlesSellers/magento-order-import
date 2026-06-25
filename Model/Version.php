<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Venuno\OrderImport\Api\Data\VersionResultInterface;
use Venuno\OrderImport\Api\Data\VersionResultInterfaceFactory;
use Venuno\OrderImport\Api\VersionInterface;

class Version implements VersionInterface
{
    /**
     * Canonical Venuno contract / module version. Mirrors composer.json "version" — bump both together on
     * a contract change (see docs/adr/ADR-0001).
     */
    public const MODULE_VERSION = '0.3.0';

    public function __construct(
        private readonly VersionResultInterfaceFactory $resultFactory,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly TokenAuthenticator $authenticator
    ) {
    }

    public function get(): VersionResultInterface
    {
        $this->authenticator->authenticate();

        return $this->resultFactory->create()
            ->setModuleVersion(self::MODULE_VERSION)
            ->setMagentoVersion($this->productMetadata->getVersion())
            ->setMagentoEdition($this->productMetadata->getEdition());
    }
}
