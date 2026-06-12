<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Compiler;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

final class LoggerChannelPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('monolog.logger')) {
            return;
        }

        $createdLoggers = ['app'];

        foreach ($container->findTaggedServiceIds('monolog.logger') as $id => $tags) {
            foreach ($tags as $tag) {
                $channel = $this->channelFromTag($container, $tag);

                if ($channel === '' || $channel === 'app') {
                    continue;
                }

                $loggerId = sprintf('monolog.logger.%s', $channel);
                $this->createLogger($channel, $loggerId, $container, $createdLoggers);
                $this->bindTaggedServiceToLogger($container, $id, $loggerId);
            }
        }

        foreach ($this->additionalChannels($container) as $channel) {
            if ($channel === 'app') {
                continue;
            }

            $loggerId = sprintf('monolog.logger.%s', $channel);
            $this->createLogger($channel, $loggerId, $container, $createdLoggers);
            $container->getDefinition($loggerId)->setPublic(true);
        }

        $handlersToChannels = $this->handlersToChannels($container);

        foreach ($handlersToChannels as $channels) {
            foreach ($this->configuredChannels($channels) as $channel) {
                if ($channel === 'app') {
                    continue;
                }

                $this->createLogger(
                    $channel,
                    sprintf('monolog.logger.%s', $channel),
                    $container,
                    $createdLoggers,
                );
            }
        }

        foreach ($handlersToChannels as $handler => $channels) {
            foreach ($this->processChannels($channels, $createdLoggers) as $channel) {
                $loggerId = $channel === 'app' ? 'monolog.logger' : sprintf('monolog.logger.%s', $channel);

                if (!$container->hasDefinition($loggerId)) {
                    throw new \InvalidArgumentException(
                        sprintf('Monolog configuration error: channel "%s" for handler "%s" does not exist.', $channel, $handler),
                    );
                }

                if (!$container->hasDefinition($handler) && !$container->hasAlias($handler)) {
                    throw new \InvalidArgumentException(
                        sprintf('Monolog configuration error: handler "%s" does not exist.', $handler),
                    );
                }

                $container->getDefinition($loggerId)
                    ->addMethodCall('pushHandler', [new Reference($handler)]);
            }
        }

        if ($container->hasParameter('monolog.additional_channels')) {
            $container->getParameterBag()->remove('monolog.additional_channels');
        }

        if ($container->hasParameter('monolog.default_additional_channels')) {
            $container->getParameterBag()->remove('monolog.default_additional_channels');
        }

        if ($container->hasParameter('monolog.handlers_to_channels')) {
            $container->getParameterBag()->remove('monolog.handlers_to_channels');
        }

        if ($container->hasParameter('monolog.default_handlers_to_channels')) {
            $container->getParameterBag()->remove('monolog.default_handlers_to_channels');
        }

        if (!$container->hasParameter('monolog.disabled_handlers')) {
            return;
        }

        $container->getParameterBag()->remove('monolog.disabled_handlers');
    }

    /** @param array<string, mixed> $tag */
    private function channelFromTag(ContainerBuilder $container, array $tag): string
    {
        $channel = $tag['channel'] ?? '';
        $resolved = $container->getParameterBag()->resolveValue($channel);

        if (!is_scalar($resolved) && !$resolved instanceof \Stringable) {
            return '';
        }

        return trim((string) $resolved);
    }

    private function bindTaggedServiceToLogger(ContainerBuilder $container, string $serviceId, string $loggerId): void
    {
        if (!$container->hasDefinition($serviceId)) {
            return;
        }

        $definition = $container->getDefinition($serviceId);

        foreach ($definition->getArguments() as $index => $argument) {
            if (!($argument instanceof Reference) || (string) $argument !== 'logger') {
                continue;
            }

            $definition->replaceArgument($index, $this->changeReference($argument, $loggerId));
        }

        $calls = $definition->getMethodCalls();

        foreach ($calls as $callIndex => $call) {
            foreach ($call[1] as $argumentIndex => $argument) {
                if (!($argument instanceof Reference) || (string) $argument !== 'logger') {
                    continue;
                }

                $calls[$callIndex][1][$argumentIndex] = $this->changeReference($argument, $loggerId);
            }
        }

        $definition->setMethodCalls($calls);

        $binding = new BoundArgument(new Reference($loggerId), false);
        $bindings = $definition->getBindings();
        $bindings[LoggerInterface::class] = $binding;
        $definition->setBindings($bindings);
    }

    /** @return list<string> */
    private function additionalChannels(ContainerBuilder $container): array
    {
        return array_values(array_unique([
            ...$this->channelListParameter($container, 'monolog.default_additional_channels'),
            ...$this->channelListParameter($container, 'monolog.additional_channels'),
        ]));
    }

    /** @return array<string, mixed> */
    private function handlersToChannels(ContainerBuilder $container): array
    {
        $normalized = $this->handlerMapParameter($container, 'monolog.default_handlers_to_channels');
        $disabled = array_flip($this->channelListParameter($container, 'monolog.disabled_handlers'));

        foreach (array_keys($disabled) as $handler) {
            unset($normalized[$handler]);
        }

        foreach ($this->handlerMapParameter($container, 'monolog.handlers_to_channels') as $handler => $channels) {
            unset($normalized[$handler]);
            $normalized[$handler] = $channels;
        }

        return $normalized;
    }

    /** @return list<string> */
    private function channelListParameter(ContainerBuilder $container, string $parameter): array
    {
        if (!$container->hasParameter($parameter)) {
            return [];
        }

        $channels = $container->getParameter($parameter);

        if (!is_array($channels)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $channel): string => is_scalar($channel) ? trim((string) $channel) : '', $channels),
            static fn (string $channel): bool => $channel !== '',
        ));
    }

    /** @return array<string, mixed> */
    private function handlerMapParameter(ContainerBuilder $container, string $parameter): array
    {
        if (!$container->hasParameter($parameter)) {
            return [];
        }

        $handlers = $container->getParameter($parameter);

        if (!is_array($handlers)) {
            return [];
        }

        $normalized = [];

        foreach ($handlers as $handler => $channels) {
            if (!is_string($handler) || $handler === '') {
                continue;
            }

            $normalized[$handler] = $channels;
        }

        return $normalized;
    }

    /** @param array<int, string> $createdLoggers */
    private function createLogger(
        string $channel,
        string $loggerId,
        ContainerBuilder $container,
        array &$createdLoggers,
    ): void {

        if (in_array($channel, $createdLoggers, true)) {
            return;
        }

        $logger = new ChildDefinition('monolog.logger_prototype');
        $logger->replaceArgument(0, $channel);
        $logger->addTag('monolog.channel_logger');
        $container->setDefinition($loggerId, $logger);
        $container->registerAliasForArgument($loggerId, LoggerInterface::class, $channel . '.logger', $channel);
        $createdLoggers[] = $channel;
    }

    /**
     * @param array<int, string> $createdLoggers
     * @return list<string>
     */
    private function processChannels(mixed $configuration, array $createdLoggers): array
    {
        if ($configuration === null) {
            return array_values($createdLoggers);
        }

        if (!is_array($configuration)) {
            return [];
        }

        if (array_is_list($configuration)) {
            return $this->configuredChannels($configuration) ?: array_values($createdLoggers);
        }

        $type = is_string($configuration['type'] ?? null) ? $configuration['type'] : 'inclusive';
        $elements = $this->configuredChannels($configuration['elements'] ?? []);

        if ($type === 'exclusive') {
            return array_values(array_diff($createdLoggers, $elements));
        }

        if ($type !== 'inclusive') {
            throw new InvalidArgumentException('Monolog handler channel configuration must be inclusive or exclusive.');
        }

        return $elements !== [] ? $elements : array_values($createdLoggers);
    }

    /** @return list<string> */
    private function configuredChannels(mixed $configuration): array
    {
        if (!is_array($configuration)) {
            return [];
        }

        $channels = array_is_list($configuration)
            ? $configuration
            : ($configuration['elements'] ?? []);

        if (!is_array($channels)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $channel): string => is_scalar($channel) ? trim((string) $channel) : '', $channels),
            static fn (string $channel): bool => $channel !== '',
        ));
    }

    private function changeReference(Reference $reference, string $serviceId): Reference
    {
        return new Reference($serviceId, $reference->getInvalidBehavior());
    }
}
