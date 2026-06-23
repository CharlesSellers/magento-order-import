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
 *   "order_import": false,
 *   "contract_version": "0.1",
 *   "import_identity_fields": ["source_connection_id", "source_platform", ...],
 *   "import_replay_fields": ["replay_key", "payload_hash", "import_status", ...]
 * }
 *
 * `order_import` stays false until a future release genuinely accepts inbound orders. The
 * `import_*` fields advertise the **import-domain contract** a client must satisfy when that day
 * comes — so the contract is discoverable now, before any endpoint exists (see
 * docs/adr/ADR-0002-import-domain-contract.md).
 *
 * Response keys are append-only: existing keys never change meaning, so a Venuno client reads this
 * map forward-compatibly (unknown keys ignored, missing keys treated as false/empty).
 */
interface CapabilitiesResultInterface
{
    public const ORDER_IMPORT = 'order_import';
    public const CONTRACT_VERSION = 'contract_version';
    public const IMPORT_IDENTITY_FIELDS = 'import_identity_fields';
    public const IMPORT_REPLAY_FIELDS = 'import_replay_fields';

    /**
     * Whether this release accepts inbound order import. False in Release 0.1.
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
