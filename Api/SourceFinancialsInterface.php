<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Api;

interface SourceFinancialsInterface
{
    /**
     * Read persisted legacy financial values; never writes to either database.
     * @param int $orderId
     * @return \Venuno\OrderImport\Api\Data\SourceFinancialsResultInterface
     */
    public function get(int $orderId): \Venuno\OrderImport\Api\Data\SourceFinancialsResultInterface;
}
