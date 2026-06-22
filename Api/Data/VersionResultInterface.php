<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api\Data;

/**
 * Result of GET /V1/venuno/version. Serialises to:
 * {"module_version":"0.1.0","magento_version":"2.4.7","magento_edition":"Community"}.
 */
interface VersionResultInterface
{
    public const MODULE_VERSION = 'module_version';
    public const MAGENTO_VERSION = 'magento_version';
    public const MAGENTO_EDITION = 'magento_edition';

    /**
     * The Venuno module (contract) version.
     *
     * @return string
     */
    public function getModuleVersion(): string;

    /**
     * @param string $version
     * @return $this
     */
    public function setModuleVersion(string $version): VersionResultInterface;

    /**
     * The host Magento version, e.g. "2.4.7".
     *
     * @return string
     */
    public function getMagentoVersion(): string;

    /**
     * @param string $version
     * @return $this
     */
    public function setMagentoVersion(string $version): VersionResultInterface;

    /**
     * The host Magento edition, e.g. "Community" or "Enterprise".
     *
     * @return string
     */
    public function getMagentoEdition(): string;

    /**
     * @param string $edition
     * @return $this
     */
    public function setMagentoEdition(string $edition): VersionResultInterface;
}
