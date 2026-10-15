<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor\Tests;

use PhpBaremetal\Conductor\Generator;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    public function testRelativePathNormalisesWindowsSeparators(): void
    {
        self::assertSame('.phpbm/packages/x', Generator::relativePath('C:\p', 'C:\p\.phpbm\packages\x'));
        self::assertSame('.phpbm/packages/x', Generator::relativePath('C:\p\\', 'C:/p/.phpbm/packages/x'));
    }

    public function testRelativePathKeepsOutsidePathsAbsolute(): void
    {
        self::assertSame('D:/other/x', Generator::relativePath('C:\p', 'D:\other\x'));
    }
}
