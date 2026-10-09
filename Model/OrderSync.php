<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\Webapi\Exception as WebapiException;
use Venuno\OrderImport\Api\Data\OrderImportRequestInterface;
use Venuno\OrderImport\Api\Data\OrderSyncResultInterface;
use Venuno\OrderImport\Api\Data\OrderSyncResultInterfaceFactory;
use Venuno\OrderImport\Api\OrderSyncInterface;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\OrderDraftBuilder;
use Venuno\OrderImport\Model\Sync\OrderSyncEngine;
use Venuno\OrderImport\Model\Sync\OrderSyncService;
use Venuno\OrderImport\Model\Sync\PayloadSnapshot;
use Venuno\OrderImport\Model\Sync\SyncException;

/** POST /V1/venuno/orders/sync — see {@see OrderSyncInterface}. */
class OrderSync implements OrderSyncInterface
{
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_UNPROCESSABLE_ENTITY = 422;
    private const HTTP_SERVICE_UNAVAILABLE = 503;

    public function __construct(
        private readonly TokenAuthenticator $authenticator,
        private readonly OrderImportRepository $repository,
        private readonly OrderSyncService $service,
        private readonly OrderDraftBuilder $draftBuilder,
        private readonly OrderSyncResultInterfaceFactory $resultFactory
    ) {
    }

    public function sync(OrderImportRequestInterface $request): OrderSyncResultInterface
    {
        $this->authenticator->authenticate();
        $replayKey = $request->getReplayKey();
        if ($replayKey === '' || $request->getSourceOrderEntityId() === '' || !ctype_digit($request->getSourceOrderEntityId())) {
            throw new WebapiException(__('replay_key and a numeric source_order_entity_id are required.'), 0, self::HTTP_UNPROCESSABLE_ENTITY);
        }
        $config = $this->service->config();
        if (!$config->isEnabled()) {
            throw new WebapiException(__('Order sync is not enabled on this destination.'), 0, self::HTTP_FORBIDDEN);
        }
        $ledger = $this->repository->findByReplayKey($replayKey);
        if ($ledger === null || ($ledger['import_status'] ?? '') !== 'imported' || (int) ($ledger['magento_order_id'] ?? 0) < 1) {
            throw new WebapiException(__('No imported order exists for this replay_key; use /orders/import.'), 0, self::HTTP_NOT_FOUND);
        }
        if ((string) $ledger['source_order_entity_id'] !== $request->getSourceOrderEntityId()
            || OrderSyncEngine::normaliseUrl((string) $ledger['source_base_url']) !== OrderSyncEngine::normaliseUrl($request->getSourceBaseUrl())) {
            throw new WebapiException(__('Source identity differs from the imported order.'), 0, self::HTTP_UNPROCESSABLE_ENTITY);
        }

        $failure = null;
        try {
            $useLegacyDatabase = $config->legacyDatabase() !== null;
            $engine = $this->service->engine($useLegacyDatabase);
            $snapshot = null;
            if (!$useLegacyDatabase) {
                $draft = $this->draftBuilder->fromImportRow(array_replace($ledger, ['request_payload' => $request->getOrder()]));
                $snapshot = PayloadSnapshot::fromDraft($draft);
            }
            $outcome = $engine->sync((int) $ledger['source_order_entity_id'], $config->apiMayApply(), 'api-' . date('Ymd'), 'api', $snapshot,
                $useLegacyDatabase ? null : ($request->getPayloadHash() ?: hash('sha256', $request->getOrder())));
        } catch (MaterialisationException $e) {
            throw new WebapiException(__('Order sync refused (payload_invalid): %1', $e->getMessage()), 0, self::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SyncException $e) {
            $outcome = null;
            $failure = $e;
        }
        if ($outcome === null || $outcome->status === 'failed') {
            $reason = $outcome?->reason ?? $failure->getReason();
            $message = $outcome?->message ?? $failure->getMessage();
            $retryable = $outcome?->retryable ?? $failure->isRetryable();
            throw new WebapiException(__('Order sync refused (%1): %2', $reason, $message), 0,
                $retryable || $reason === 'legacy_unavailable' ? self::HTTP_SERVICE_UNAVAILABLE : self::HTTP_UNPROCESSABLE_ENTITY);
        }
        $messages = ['noop' => 'Order already matches the source.', 'planned' => 'Changes planned (dry run; api_apply is off).', 'applied' => 'Order synced.'];
        return $this->resultFactory->create()
            ->setAccepted(true)
            ->setStatus($outcome->status)
            ->setMagentoOrderId((int) $ledger['magento_order_id'])
            ->setAuditId((int) ($outcome->auditId ?? 0))
            ->setCategories(implode(',', $outcome->categories()))
            ->setMessage($messages[$outcome->status] ?? $outcome->status);
    }
}
