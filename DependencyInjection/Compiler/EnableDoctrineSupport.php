<?php

namespace Tqd\MemcacheBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * EnableDoctrineSupport is a compiler pass that points the Doctrine cache
 * services to the Memcache backed caches. It runs after all extensions are
 * loaded, so it works regardless of the order in which the bundles are registered.
 */
class EnableDoctrineSupport implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('memcache.doctrine_caches')) {
            return;
        }
        foreach ($container->getParameter('memcache.doctrine_caches') as $doctrineId => $memcacheId) {
            $container->setAlias($doctrineId, new Alias($memcacheId, true));
        }
    }
}
