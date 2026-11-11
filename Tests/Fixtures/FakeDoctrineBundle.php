<?php

namespace Tqd\MemcacheBundle\Tests\Fixtures;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers its own Doctrine cache service, like DoctrineBundle does, to
 * check that the Memcache cache wins regardless of the bundle order.
 */
class FakeDoctrineBundle extends Bundle
{
    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
                $container->register('cache.doctrine.orm.default.metadata', ArrayAdapter::class);
                $container->setAlias('doctrine.orm.default_metadata_cache', 'cache.doctrine.orm.default.metadata');
            }

            public function getAlias(): string
            {
                return 'fake_doctrine';
            }
        };
    }
}
