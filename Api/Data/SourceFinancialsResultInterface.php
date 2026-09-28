<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Api\Data;

interface SourceFinancialsResultInterface
{
    /** @return string */
    public function getSnapshotJson(): string;
    /**
     * @param string $value
     * @return $this
     */
    public function setSnapshotJson(string $value): SourceFinancialsResultInterface;
}
