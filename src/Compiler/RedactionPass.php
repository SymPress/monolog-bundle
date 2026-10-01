<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Compiler;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger;
use SymPress\MonologBundle\Handler\RedactingHandler;
use SymPress\MonologBundle\Support\ContextSanitizer;
use SymPress\MonologBundle\Support\RedactionProcessor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RedactionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ContextSanitizer::class)) {
            $container->register(ContextSanitizer::class, ContextSanitizer::class);
        }
        if (!$container->hasDefinition(RedactionProcessor::class)) {
            $container->register(RedactionProcessor::class, RedactionProcessor::class)->setArguments([new Reference(ContextSanitizer::class)]);
        }
        $wrappers = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if (is_string($class) && is_a($class, HandlerInterface::class, true) && !is_a($class, ProcessableHandlerInterface::class, true) && !$definition->isAbstract() && !str_starts_with($class, 'Monolog\\Handler\\')) {
                $wrapper = $id . '.redacted';
                $container->register($wrapper, RedactingHandler::class)
                    ->setArguments([new Reference($id), new Reference(RedactionProcessor::class)]);
                $wrappers[$id] = $wrapper;
                continue;
            }
            if (!is_string($class) || (!is_a($class, ProcessableHandlerInterface::class, true) && !is_a($class, Logger::class, true))) {
                continue;
            }
            // Monolog unshifts processors: registering first runs redaction last, after interpolation and custom processors.
            $definition->setMethodCalls(array_merge([['pushProcessor', [new Reference(RedactionProcessor::class)]]], $definition->getMethodCalls()));
        }
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (!is_string($class) || (!is_a($class, Logger::class, true) && !str_starts_with($class, 'Monolog\\Handler\\'))) {
                continue;
            }
            $definition->setArguments($this->replaceReferences($definition->getArguments(), $wrappers));
            $definition->setMethodCalls($this->replaceReferences($definition->getMethodCalls(), $wrappers));
        }
    }

    /**
     * @template T of array
     * @param T $values
     * @param array<string, string> $wrappers
     * @return T
     */
    private function replaceReferences(array $values, array $wrappers): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Reference && isset($wrappers[(string) $value])) {
                $values[$key] = new Reference($wrappers[(string) $value], $value->getInvalidBehavior());
            } elseif (is_array($value)) {
                $values[$key] = $this->replaceReferences($value, $wrappers);
            }
        }
        /** @var T $result Argument and method-call array shapes are preserved; only Reference targets change. */
        $result = $values;
        return $result;
    }
}
