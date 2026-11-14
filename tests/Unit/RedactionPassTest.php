<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use SymPress\MonologBundle\Compiler\RedactionPass;
use SymPress\MonologBundle\Support\RedactionProcessor;
use SymPress\MonologBundle\Tests\Fixtures\AdminOnlyTable;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RedactionPassTest extends TestCase
{
    public function testServicesWhoseParentClassIsNotLoadedAreSkipped(): void
    {
        $container = new ContainerBuilder();
        $container->register('admin_table', AdminOnlyTable::class);
        $container->register('logger', Logger::class)->setArguments(['app']);

        (new RedactionPass())->process($container);

        self::assertSame([], $container->getDefinition('admin_table')->getMethodCalls());
        $calls = $container->getDefinition('logger')->getMethodCalls();
        self::assertSame('pushProcessor', $calls[0][0] ?? null);
        self::assertSame(RedactionProcessor::class, (string) ($calls[0][1][0] ?? ''));
    }
}
