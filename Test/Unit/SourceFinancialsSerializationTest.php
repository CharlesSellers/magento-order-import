<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Test\Unit;

use Laminas\Code\Reflection\ClassReflection;
use Magento\Framework\Reflection\TypeProcessor;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Api\Data\SourceFinancialsResultInterface;

final class SourceFinancialsSerializationTest extends TestCase
{
    public function testEveryDtoMethodHasMagentoReadableReturnMetadata(): void
    {
        $processor = new TypeProcessor();
        $methods = (new ClassReflection(SourceFinancialsResultInterface::class))->getMethods();
        $mapped = [];
        foreach ($methods as $method) {
            $mapped[$method->getName()] = $processor->getGetterReturnType($method);
        }
        self::assertSame('string', $mapped['getSnapshotJson']['type']);
        self::assertSame('this', $mapped['setSnapshotJson']['type']);
        self::assertSame(1, $mapped['setSnapshotJson']['parameterCount']);
    }

    public function testMagentoSerializerEmitsTheConnectorSnapshotJsonField(): void
    {
        $processor = new TypeProcessor();
        $mapped = [];
        foreach ((new ClassReflection(SourceFinancialsResultInterface::class))->getMethods() as $method) {
            $mapped[$method->getName()] = $processor->getGetterReturnType($method);
        }
        $fieldNamer = new \Magento\Framework\Reflection\FieldNamer();
        // Replace only the persistent metadata cache; reflection and output conversion are real Magento code.
        $map = $this->getMockBuilder(\Magento\Framework\Reflection\MethodsMap::class)
            ->setConstructorArgs([
                $this->createMock(\Magento\Framework\Cache\FrontendInterface::class), $processor,
                $this->createMock(\Magento\Framework\Api\AttributeTypeResolverInterface::class), $fieldNamer
            ])->onlyMethods(['getMethodsMap'])->getMock();
        $map->method('getMethodsMap')->willReturn($mapped);
        $serializer = new \Magento\Framework\Reflection\DataObjectProcessor(
            $map, new \Magento\Framework\Reflection\TypeCaster(new \Magento\Framework\Serialize\Serializer\Json()), $fieldNamer,
            $this->createMock(\Magento\Framework\Reflection\CustomAttributesProcessor::class),
            $this->createMock(\Magento\Framework\Reflection\ExtensionAttributesProcessor::class)
        );
        $dto = (new \Venuno\OrderImport\Model\Data\SourceFinancialsResult())->setSnapshotJson('{"version":1}');
        self::assertSame(['snapshot_json'=>'{"version":1}'], $serializer->buildOutputDataArray($dto, SourceFinancialsResultInterface::class));
    }
}
