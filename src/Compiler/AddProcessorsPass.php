<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class AddProcessorsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('monolog.logger')) {
            return;
        }

        $processors = $this->processors($container);

        foreach ($processors as $processor) {
            foreach ($this->targetDefinitions($container, $processor['tag']) as $definitionId) {
                if (!$container->hasDefinition($definitionId)) {
                    continue;
                }

                $container->getDefinition($definitionId)
                    ->addMethodCall('pushProcessor', [$this->processorReference($processor['id'], $processor['tag'])]);
            }
        }
    }

    /** @return list<array{id: string, tag: array<string, mixed>, priority: int, order: int}> */
    private function processors(ContainerBuilder $container): array
    {
        $processors = [];
        $order = 0;

        foreach ($container->findTaggedServiceIds('monolog.processor') as $id => $tags) {
            foreach ($tags as $tag) {
                $processors[] = [
                    'id'       => $id,
                    'tag'      => $tag,
                    'priority' => is_numeric($tag['priority'] ?? null) ? (int) $tag['priority'] : 0,
                    'order'    => $order++,
                ];
            }
        }

        usort(
            $processors,
            static function (array $left, array $right): int {
                if ($left['priority'] === $right['priority']) {
                    return $right['order'] <=> $left['order'];
                }

                return $left['priority'] <=> $right['priority'];
            },
        );

        return $processors;
    }

    /**
     * @param array<string, mixed> $tag
     * @return list<string>
     */
    private function targetDefinitions(ContainerBuilder $container, array $tag): array
    {
        $handler = $this->stringTag($tag, 'handler');
        $channel = $this->stringTag($tag, 'channel');

        if ($handler !== '' && $channel !== '') {
            throw new \InvalidArgumentException('A monolog.processor tag cannot define both handler and channel.');
        }

        if ($handler !== '') {
            $handlerId = str_starts_with($handler, 'monolog.handler.')
                ? $handler
                : sprintf('monolog.handler.%s', $handler);

            return [$this->definitionId($container, $handlerId)];
        }

        if ($channel !== '') {
            return [$channel === 'app' ? 'monolog.logger' : sprintf('monolog.logger.%s', $channel)];
        }

        $loggerIds = array_keys($container->findTaggedServiceIds('monolog.channel_logger'));

        return $loggerIds !== [] ? $loggerIds : ['monolog.logger'];
    }

    /**
     * @param array<string, mixed> $tag
     * @return Reference|array{0: Reference, 1: string}
     */
    private function processorReference(string $serviceId, array $tag): Reference|array
    {
        $method = $this->stringTag($tag, 'method');
        $reference = new Reference($serviceId);

        if ($method === '') {
            return $reference;
        }

        return [$reference, $method];
    }

    /** @param array<string, mixed> $tag */
    private function stringTag(array $tag, string $key): string
    {
        $value = $tag[$key] ?? '';

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        return trim((string) $value);
    }

    private function definitionId(ContainerBuilder $container, string $serviceId): string
    {
        if ($container->hasAlias($serviceId)) {
            return (string) $container->getAlias($serviceId);
        }

        return $serviceId;
    }
}
