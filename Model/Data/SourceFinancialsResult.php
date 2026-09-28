<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Venuno\OrderImport\Api\Data\SourceFinancialsResultInterface;

class SourceFinancialsResult extends AbstractSimpleObject implements SourceFinancialsResultInterface
{
    public function getSnapshotJson(): string { return (string)$this->_get('snapshot_json'); }
    public function setSnapshotJson(string $value): SourceFinancialsResultInterface
    { return $this->setData('snapshot_json', $value); }
}
