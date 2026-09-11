<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use LuziApi\Pilotage\UserInterface\Admin\ErrorDetailFormatter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorDetailFormatterTest extends TestCase
{
    public function testItFormatsMessageClassFileAndLine(): void
    {
        $line = __LINE__ + 1;
        $exception = new RuntimeException('Boom');

        self::assertSame(
            sprintf('Boom (RuntimeException @ %s:%d)', basename(__FILE__), $line),
            ErrorDetailFormatter::format($exception),
        );
    }

    public function testItUsesTheShortClassNameOfNamespacedExceptions(): void
    {
        $detail = ErrorDetailFormatter::format(new \InvalidArgumentException('Nope'));

        self::assertStringStartsWith('Nope (InvalidArgumentException @ ', $detail);
        self::assertMatchesRegularExpression('/@ [^ ]+:\d+\)$/', $detail);
    }
}
