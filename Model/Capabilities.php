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
    /** The import-domain contract version advertised in Release 0.1. */
    public const CONTRACT_VERSION = '0.1';

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

        // Release 0.1: the contract exists and is discoverable; order import does not yet. order_import
        // flips to true only when a future release actually accepts inbound orders.
        return $this->resultFactory->create()
            ->setOrderImport(false)
            ->setContractVersion(self::CONTRACT_VERSION)
            ->setImportIdentityFields(self::IMPORT_IDENTITY_FIELDS)
            ->setImportReplayFields(self::IMPORT_REPLAY_FIELDS);
    }
}
