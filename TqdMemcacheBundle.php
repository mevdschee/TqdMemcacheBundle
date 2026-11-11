<?php

namespace Tqd\MemcacheBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tqd\MemcacheBundle\DependencyInjection\Compiler\EnableDoctrineSupport;
use Tqd\MemcacheBundle\DependencyInjection\Compiler\EnableSessionSupport;

/**
* Bundle for Memcache sessions and cache with debug toolbar integration
*
* @author Maurits van der Schee <m.vanderschee@leaseweb.com>
*/
class TqdMemcacheBundle extends Bundle
{
    /**
     * {@inheritDoc}
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new EnableSessionSupport());
        $container->addCompilerPass(new EnableDoctrineSupport());
    }
}
