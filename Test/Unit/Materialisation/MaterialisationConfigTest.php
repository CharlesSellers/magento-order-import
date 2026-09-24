<?php
declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\MaterialisationConfig;

final class MaterialisationConfigTest extends TestCase
{
    public function testPreservationDoesNotEnableMaterialisation(): void
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(static fn ($path) => $path === MaterialisationConfig::PRESERVE_SOURCE_METADATA_PATH ? true : false);
        $config = new MaterialisationConfig($deployment);
        self::assertTrue($config->preservesSourceMetadata());
        self::assertFalse($config->isEnabled());
    }

    public function testFlagsDefaultOff(): void
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturn(null);
        $config = new MaterialisationConfig($deployment);
        self::assertFalse($config->preservesSourceMetadata());
        self::assertFalse($config->isEnabled());
    }
}
