<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Test\Unit\Materialisation;

use PHPUnit\Framework\TestCase;
use Venuno\OrderImport\Model\Materialisation\MaterialisationException;

/**
 * @covers \Venuno\OrderImport\Model\Materialisation\MaterialisationException
 */
final class MaterialisationExceptionTest extends TestCase
{
    public function testCarriesReasonAndRetryableFlag(): void
    {
        $e = new MaterialisationException('bad', MaterialisationException::REASON_UNKNOWN_SKU, false);
        self::assertSame('bad', $e->getMessage());
        self::assertSame(MaterialisationException::REASON_UNKNOWN_SKU, $e->getReason());
        self::assertFalse($e->isRetryable());
    }

    public function testRetryableAndChainsPrevious(): void
    {
        $previous = new \RuntimeException('root');
        $e = new MaterialisationException('wrap', MaterialisationException::REASON_ORDER_CREATE_FAILED, true, $previous);
        self::assertTrue($e->isRetryable());
        self::assertSame($previous, $e->getPrevious());
    }
}
