<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\Webapi\Exception as WebapiException;
use Venuno\OrderImport\Api\Data\OrderImportRequestInterface;
use Venuno\OrderImport\Api\Data\OrderImportResultInterface;
use Venuno\OrderImport\Api\Data\OrderImportResultInterfaceFactory;
use Venuno\OrderImport\Api\OrderImportInterface;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderMaterialiser;

/**
 * POST /V1/venuno/orders/import — idempotent intake for replicated orders.
 *
 * Flow: authenticate (Venuno token) → validate the contract → enforce first-write-wins idempotency on
 * `replay_key` → stage the import. When materialisation is enabled
 * ({@see MaterialisationConfig}, `capabilities.order_materialisation`), the staged row is then turned
 * into a **native Magento sales order** in the same call ({@see OrderMaterialiser}); when it is disabled
 * the import is staged only (the v0.2 behaviour) and `magento_order_id` stays 0.
 *
 * Idempotency holds across both modes: a repeat of an already-materialised order is a no-op returning the
 * existing order id; a repeat of a staged-but-not-yet-materialised order materialises it exactly once
 * (a failed attempt is rolled back, so no orphan order exists).
 */
class OrderImport implements OrderImportInterface
{
    /** Validation / terminal data errors surface as HTTP 422 (non-retryable). */
    private const HTTP_UNPROCESSABLE_ENTITY = 422;
    /** Transient failures surface as HTTP 500 (the client retries). */
    private const HTTP_INTERNAL_ERROR = 500;

    public function __construct(
        private readonly OrderImportResultInterfaceFactory $resultFactory,
        private readonly TokenAuthenticator $authenticator,
        private readonly OrderImportRepository $repository,
        private readonly MaterialisationConfig $materialisationConfig,
        private readonly OrderMaterialiser $materialiser
    ) {
    }

    public function import(OrderImportRequestInterface $request): OrderImportResultInterface
    {
        $this->authenticator->authenticate();
        $this->validate($request);

        $replayKey = $request->getReplayKey();
        $materialise = $this->materialisationConfig->isEnabled();

        $existing = $this->repository->findByReplayKey($replayKey);
        if ($existing !== null) {
            $existingOrderId = (int) ($existing['magento_order_id'] ?? 0);
            // Already materialised → safe, idempotent no-op (never a second order).
            if ($existingOrderId > 0) {
                return $this->result(
                    true,
                    true,
                    $replayKey,
                    'imported',
                    $existingOrderId,
                    'Already imported (idempotent no-op).'
                );
            }
            // Staged on a previous call but not yet materialised — replay materialisation if enabled.
            if ($materialise) {
                return $this->materialiseAndResult($replayKey);
            }
            return $this->result(
                true,
                true,
                $replayKey,
                (string) ($existing['import_status'] ?? 'pending'),
                0,
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
            'request_payload' => $request->getOrder(),
        ]);

        if ($materialise) {
            return $this->materialiseAndResult($replayKey);
        }

        return $this->result(true, false, $replayKey, 'pending', 0, 'Import recorded.');
    }

    /**
     * Materialise the staged row into a native order and shape the response. `duplicate` reflects whether
     * a NEW order was created: a freshly created order is billable (`duplicate=false`); an
     * already-materialised one is an idempotent no-op (`duplicate=true`). A terminal data error maps to
     * 422, a transient failure (incl. a concurrent materialisation in progress) to 500 so the client retries.
     *
     * @throws WebapiException
     */
    private function materialiseAndResult(string $replayKey): OrderImportResultInterface
    {
        try {
            $result = $this->materialiser->materialise($replayKey);
        } catch (MaterialisationException $e) {
            $httpCode = $e->isRetryable() ? self::HTTP_INTERNAL_ERROR : self::HTTP_UNPROCESSABLE_ENTITY;
            throw new WebapiException(
                __('Order materialisation failed (%1): %2', $e->getReason(), $e->getMessage()),
                0,
                $httpCode
            );
        }

        if ($result->status === 'in_progress') {
            // A concurrent worker holds the claim; ask the client to retry (idempotent on the next call).
            throw new WebapiException(
                __('Order materialisation is already in progress; retry shortly.'),
                0,
                self::HTTP_INTERNAL_ERROR
            );
        }

        $duplicate = !$result->created;
        return $this->result(
            true,
            $duplicate,
            $replayKey,
            'imported',
            $result->magentoOrderId,
            $result->created ? 'Order created.' : 'Already imported (idempotent no-op).'
        );
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
