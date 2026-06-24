<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Api\Data;

/**
 * Result of GET /V1/venuno/capabilities. In Release 0.1 it serialises to, e.g.:
 *
 * {
 *   "order_import": true,
 *   "order_materialisation": false,
 *   "contract_version": "0.2",
 *   "import_identity_fields": ["source_connection_id", "source_platform", ...],
 *   "import_replay_fields": ["replay_key", "payload_hash", "import_status", ...]
 * }
 *
 * `order_import` is true once the store accepts and idempotently stages inbound imports (the
 * POST /V1/venuno/orders/import endpoint). `order_materialisation` is true only when a staged import
 * is turned into a native Magento sales order — false until that (live-validated) release lands
 * (see docs/adr/ADR-0002-import-domain-contract.md and ADR-0003-order-import-intake.md). The
 * `import_*` fields advertise the import-domain contract a client must satisfy.
 *
 * Response keys are append-only: existing keys never change meaning, so a Venuno client reads this
 * map forward-compatibly (unknown keys ignored, missing keys treated as false/empty).
 */
interface CapabilitiesResultInterface
{
    public const ORDER_IMPORT = 'order_import';
    public const ORDER_MATERIALISATION = 'order_materialisation';
    public const CONTRACT_VERSION = 'contract_version';
    public const IMPORT_IDENTITY_FIELDS = 'import_identity_fields';
    public const IMPORT_REPLAY_FIELDS = 'import_replay_fields';

    /**
     * Whether this release accepts and idempotently stages inbound order import.
     *
     * @return bool
     */
    public function getOrderImport(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setOrderImport(bool $enabled): CapabilitiesResultInterface;

    /**
     * Whether a staged import is materialised into a native Magento sales order. False until the
     * live-validated materialisation release.
     *
     * @return bool
     */
    public function getOrderMaterialisation(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setOrderMaterialisation(bool $enabled): CapabilitiesResultInterface;

    /**
     * The import-domain contract version a future import endpoint will honour (e.g. "0.1").
     *
     * @return string
     */
    public function getContractVersion(): string;

    /**
     * @param string $version
     * @return $this
     */
    public function setContractVersion(string $version): CapabilitiesResultInterface;

    /**
     * The store-aware source identity fields a future import will require (never increment_id alone).
     *
     * @return string[]
     */
    public function getImportIdentityFields(): array;

    /**
     * @param string[] $fields
     * @return $this
     */
    public function setImportIdentityFields(array $fields): CapabilitiesResultInterface;

    /**
     * The replay-protection fields a future import will require for idempotency.
     *
     * @return string[]
     */
    public function getImportReplayFields(): array;

    /**
     * @param string[] $fields
     * @return $this
     */
    public function setImportReplayFields(array $fields): CapabilitiesResultInterface;
}
