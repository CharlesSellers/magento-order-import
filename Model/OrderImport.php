<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Webapi\Exception as WebapiException;
use Venuno\OrderImport\Api\Data\OrderImportRequestInterface;
use Venuno\OrderImport\Api\Data\OrderImportResultInterface;
use Venuno\OrderImport\Api\Data\OrderImportResultInterfaceFactory;
use Venuno\OrderImport\Api\OrderImportInterface;

/**
 * POST /V1/venuno/orders/import — idempotent intake for replicated orders.
 *
 * Flow: authenticate (Venuno token) → validate the contract → enforce first-write-wins idempotency on
 * `replay_key` → stage the import with its full store-aware identity. A repeat of the same order finds
 * the recorded row and returns a no-op (`duplicate=true`) without writing again, so repeated source
 * pulls can NEVER create a duplicate.
 *
 * This release **stages** the import (status `pending`) and does NOT yet create a native Magento sales
 * order — `magento_order_id` stays 0. Materialisation is a later release and requires live-Magento
 * validation (see docs/adr/ADR-0003-order-import-intake.md). `capabilities.order_materialisation`
 * advertises this honestly.
 */
class OrderImport implements OrderImportInterface
{
    /** Validation errors surface as HTTP 422 (non-retryable), matching the client's expectations. */
    private const HTTP_UNPROCESSABLE_ENTITY = 422;

    public function __construct(
        private readonly OrderImportResultInterfaceFactory $resultFactory,
        private readonly TokenAuthenticator $authenticator,
        private readonly OrderImportRepository $repository,
        private readonly Json $json
    ) {
    }

    public function import(OrderImportRequestInterface $request): OrderImportResultInterface
    {
        $this->authenticator->authenticate();
        $this->validate($request);

        $replayKey = $request->getReplayKey();

        $existing = $this->repository->findByReplayKey($replayKey);
        if ($existing !== null) {
            // First-write-wins: the order is already recorded — a safe, idempotent no-op.
            return $this->result(
                true,
                true,
                $replayKey,
                (string) ($existing['import_status'] ?? 'pending'),
                (int) ($existing['magento_order_id'] ?? 0),
                'Already imported (idempotent no-op).'
            );
        }

        $this->repository->insert([
            'replay_key' => $replayKey,
            'payload_hash' => $request->getPayloadHash(),
            'source_connection_id' => $request->getSourceConnectionId(),
            'source_platform' => $request->getSourcePlatform(),
            'source_base_url' => $request->getSourceBaseUrl(),
            'source_store_id' => $request->getSourceStoreId(),
            'source_store_code' => $request->getSourceStoreCode(),
            'source_website_id' => $request->getSourceWebsiteId(),
            'source_order_entity_id' => $request->getSourceOrderEntityId(),
            'source_order_increment_id' => $request->getSourceOrderIncrementId(),
            'source_order_display_number' => $request->getSourceOrderDisplayNumber(),
            'original_created_at' => $request->getOriginalCreatedAt(),
            'import_status' => 'pending',
            'request_payload' => $this->json->serialize($request->getOrder()),
        ]);

        return $this->result(true, false, $replayKey, 'pending', 0, 'Import recorded.');
    }

    /**
     * @throws WebapiException when a required contract field is missing (HTTP 422).
     */
    private function validate(OrderImportRequestInterface $request): void
    {
        $missing = [];
        if ($request->getReplayKey() === '') {
            $missing[] = 'replay_key';
        }
        if ($request->getSourcePlatform() === '') {
            $missing[] = 'source_platform';
        }
        if ($request->getSourceBaseUrl() === '') {
            $missing[] = 'source_base_url';
        }
        if ($request->getSourceOrderEntityId() === '') {
            $missing[] = 'source_order_entity_id';
        }

        if ($missing !== []) {
            throw new WebapiException(
                __('Missing required import fields: %1', implode(', ', $missing)),
                0,
                self::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    private function result(
        bool $accepted,
        bool $duplicate,
        string $replayKey,
        string $importStatus,
        int $magentoOrderId,
        string $message
    ): OrderImportResultInterface {
        return $this->resultFactory->create()
            ->setAccepted($accepted)
            ->setDuplicate($duplicate)
            ->setReplayKey($replayKey)
            ->setImportStatus($importStatus)
            ->setMagentoOrderId($magentoOrderId)
            ->setMessage($message);
    }
}
