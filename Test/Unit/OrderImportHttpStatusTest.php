<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Test\Unit;

use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\OrderImport;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;
use Venuno\OrderImport\Model\Materialisation\MaterialisationResult;
use Venuno\OrderImport\Model\Materialisation\OrderMaterialiser;

final class OrderImportHttpStatusTest extends TestCase
{
    private function invokeFailure(?MaterialisationException $failure): WebapiException
    {
        if (!function_exists('__')) {
            require_once dirname((string)getenv('VENUNO_MAGENTO_AUTOLOAD'), 2).'/app/functions.php';
        }
        $materialiser=$this->createMock(OrderMaterialiser::class);
        if ($failure) {
            $materialiser->method('materialise')->willThrowException($failure);
        } else {
            $materialiser->method('materialise')->willReturn(MaterialisationResult::inProgress());
        }
        // Exercise the real API exception mapping without generated factories or a database.
        $reflection=new \ReflectionClass(OrderImport::class);
        $service=$reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('materialiser')->setValue($service,$materialiser);
        try {
            $reflection->getMethod('materialiseAndResult')->invoke($service,'test-replay');
        } catch (WebapiException $e) {
            return $e;
        }
        self::fail('Expected a classified API failure');
    }

    public function testRolledBackTransientFailureUsesRetryable503(): void
    {
        $e=$this->invokeFailure(new MaterialisationException('Rolled-back deadlock',MaterialisationException::REASON_ORDER_CREATE_FAILED,true));
        self::assertSame(503,$e->getHttpCode());
    }

    public function testConcurrentClaimUsesRetryable503(): void
    {
        self::assertSame(503,$this->invokeFailure(null)->getHttpCode());
    }

    public function testMissingCustomerRemainsTerminal422(): void
    {
        $e=$this->invokeFailure(new MaterialisationException('Customer not found',MaterialisationException::REASON_CUSTOMER_NOT_FOUND,false));
        self::assertSame(422,$e->getHttpCode());
    }
}
