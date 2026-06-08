<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfiguredHandlerAliasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('monolog.handler_aliases')) {
            return;
        }

        $aliases = $container->getParameter('monolog.handler_aliases');

        if (!is_array($aliases)) {
            return;
        }

        foreach ($aliases as $publicId => $configuredId) {
            if (!is_string($publicId) || !is_string($configuredId) || $publicId === '' || $configuredId === '') {
                continue;
            }

            if ($container->hasDefinition($publicId)) {
                $container->removeDefinition($publicId);
            }

            if ($container->hasAlias($publicId)) {
                $container->removeAlias($publicId);
            }

            $container->setAlias($publicId, $configuredId);
        }

        $container->getParameterBag()->remove('monolog.handler_aliases');
    }
}
