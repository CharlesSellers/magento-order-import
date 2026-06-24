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
    /** The import-domain contract version. 0.2 adds the idempotent intake endpoint. */
    public const CONTRACT_VERSION = '0.2';

    /**
     * Store-aware source identity a future import will require. NEVER increment_id alone — it is
     * per-store and collides across storefronts; entity_id is the global key. Mirrors the source
     * connector's identity model (docs/adr/ADR-0002-import-domain-contract.md).
     */
    public const IMPORT_IDENTITY_FIELDS = [
        'source_connection_id',
        'source_platform',
        'source_base_url',
        'source_store_id',
        'source_store_code',
        'source_website_id',
        'source_order_entity_id',
        'source_order_increment_id',
        'source_order_display_number',
        'original_created_at',
    ];

    /** Replay-protection fields a future import will require for first-write-wins idempotency. */
    public const IMPORT_REPLAY_FIELDS = [
        'replay_key',
        'payload_hash',
        'import_status',
        'imported_at',
        'last_seen_at',
    ];

    public function __construct(
        private readonly CapabilitiesResultInterfaceFactory $resultFactory,
        private readonly TokenAuthenticator $authenticator
    ) {
    }

    public function get(): CapabilitiesResultInterface
    {
        $this->authenticator->authenticate();

        // 0.2: the store accepts and idempotently STAGES inbound imports (order_import = true).
        // Materialising a staged import into a native Magento sales order is a later, live-validated
        // release (order_materialisation = false) — see ADR-0003.
        return $this->resultFactory->create()
            ->setOrderImport(true)
            ->setOrderMaterialisation(false)
            ->setContractVersion(self::CONTRACT_VERSION)
            ->setImportIdentityFields(self::IMPORT_IDENTITY_FIELDS)
            ->setImportReplayFields(self::IMPORT_REPLAY_FIELDS);
    }
}
