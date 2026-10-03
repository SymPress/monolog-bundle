<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SecurityAuditConfigurationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('monolog.security_audit.configured')) {
            return;
        }

        $config = $container->getParameter('monolog.security_audit.configured');
        if (is_array($config)) {
            foreach ($config as $key => $value) {
                $container->setParameter('monolog.security_audit.' . $key, $value);
            }
        }
        $container->getParameterBag()->remove('monolog.security_audit.configured');
    }
}
