<?php

declare(strict_types=1);

namespace SymPress\MonologBundle;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\Attribute\WithMonologChannel;
use Monolog\Processor\ProcessorInterface;
use Monolog\ResettableInterface;
use Psr\Log\LoggerAwareInterface;
use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\MonologBundle\Compiler\AddProcessorsPass;
use SymPress\MonologBundle\Compiler\ConfiguredHandlerAliasPass;
use SymPress\MonologBundle\Compiler\LoggerChannelPass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class MonologBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ConfiguredHandlerAliasPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
        $container->addCompilerPass(new LoggerChannelPass());
        $container->addCompilerPass(new AddProcessorsPass());
        $container->registerForAutoconfiguration(ProcessorInterface::class)
            ->addTag('monolog.processor');
        $container->registerForAutoconfiguration(ResettableInterface::class)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->registerForAutoconfiguration(LoggerAwareInterface::class)
            ->addMethodCall('setLogger', [new Reference('logger')]);

        $container->registerAttributeForAutoconfiguration(
            AsMonologProcessor::class,
            static function (ChildDefinition $definition, AsMonologProcessor $attribute, \Reflector $reflector): void {
                $tag = get_object_vars($attribute);

                if ($reflector instanceof \ReflectionMethod) {
                    if (isset($tag['method'])) {
                        throw new \LogicException(
                            sprintf(
                                'AsMonologProcessor attribute cannot declare a method on "%s::%s()".',
                                $reflector->class,
                                $reflector->name,
                            ),
                        );
                    }

                    $tag['method'] = $reflector->getName();
                }

                $definition->addTag('monolog.processor', $tag);
            },
        );
        $container->registerAttributeForAutoconfiguration(
            WithMonologChannel::class,
            static function (ChildDefinition $definition, WithMonologChannel $attribute): void {
                $definition->addTag('monolog.logger', ['channel' => $attribute->channel]);
            },
        );
    }
}
