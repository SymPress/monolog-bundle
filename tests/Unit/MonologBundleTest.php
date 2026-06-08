<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use SymPress\MonologBundle\MonologBundle;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class MonologBundleTest extends TestCase
{
    public function testLoggerAwareServicesReceiveDefaultLogger(): void
    {
        $builder = new ContainerBuilder();
        $builder->register('logger', Logger::class)
            ->addArgument('app')
            ->setPublic(true);
        $builder->register(LoggerAwareFixture::class, LoggerAwareFixture::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        (new MonologBundle())->build($builder);
        $builder->compile();

        $definition = $builder->getDefinition(LoggerAwareFixture::class);
        $calls = $definition->getMethodCalls();

        self::assertCount(1, $calls);
        self::assertSame('setLogger', $calls[0][0]);
        self::assertInstanceOf(Reference::class, $calls[0][1][0]);
        self::assertSame('logger', (string) $calls[0][1][0]);
    }
}

final class LoggerAwareFixture implements LoggerAwareInterface
{
    use LoggerAwareTrait;
}
