<?php

namespace Tqd\MemcacheBundle\DependencyInjection;

use Doctrine\Common\Cache\Psr6\CacheAdapter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Tqd\MemcacheBundle\Cache\AntiDogPileMemcache;
use Tqd\MemcacheBundle\Command\ClearCommand;
use Tqd\MemcacheBundle\Command\StatisticsCommand;
use Tqd\MemcacheBundle\DataCollector\MemcacheDataCollector;
use Tqd\MemcacheBundle\Doctrine\Cache\MemcacheCache;
use Tqd\MemcacheBundle\Firewall\FirewallHandler;
use Tqd\MemcacheBundle\Session\Storage\LockingSessionHandler;

/**
 * Applies the configuration for the Memcache object
 * Based on Emagister\MemcachedBundle by Christian Soronellas
 */
class TqdMemcacheExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('memcache.doctrine_cache.class', MemcacheCache::class);
        $container->setParameter('memcache.session_handler.class', LockingSessionHandler::class);
        $container->setParameter('memcache.firewall_handler.class', FirewallHandler::class);

        if ($container->getParameter('kernel.debug')) {
            $this->loadDataCollector($container);
        }

        if (isset($config['session'])) {
            $this->enableSessionSupport($config, $container);
        } else {
            $container->setParameter('memcache.session_handler.auto_load', false);
        }
        $container->setParameter('memcache.doctrine_caches', array());
        if (isset($config['doctrine'])) {
            $this->loadDoctrine($config, $container);
        }
        if (isset($config['firewall'])) {
            $this->loadFirewall($config, $container);
        }
        $pools = isset($config['pools']) ? $config['pools'] : array();
        $this->addClients($pools, $container);
        $this->loadCommands(array_keys($pools), $container);
    }

    /**
     * Registers the data collector for the Web Debug Toolbar
     *
     * @param ContainerBuilder $container Service container
     */
    private function loadDataCollector(ContainerBuilder $container)
    {
        $container->setParameter('memcache.data_collector.class', MemcacheDataCollector::class);
        $container->setParameter('memcache.data_collector.template', '@TqdMemcache/Collector/memcache.html.twig');

        $definition = new Definition('%memcache.data_collector.class%');
        $definition->addTag('data_collector', array(
            'template' => '%memcache.data_collector.template%',
            'id' => 'memcache',
        ));
        $container->setDefinition('memcache.data_collector', $definition);
    }

    /**
     * Enables session support using Memcache based on the configuration
     *
     * @param array            $config    Configuration for bundle
     * @param ContainerBuilder $container Service container
     *
     * @return void
     */
    private function enableSessionSupport($config, ContainerBuilder $container)
    {
        // make sure the pool is specified and it exists
        $pool = $config['session']['pool'];
        if (null === $pool) {
            return;
        }
        if (!isset($config['pools']) || !isset($config['pools'][$pool])) {
            throw new \LogicException(sprintf('The pool "%s" does not exist! Cannot enable the session support!', $pool));
        }
        // calculate options
        $sessionOptions = $container->hasParameter('session.storage.options') ? $container->getParameter('session.storage.options') : array();
        $options = array();
        if (isset($config['session']['ttl'])) {
            $options['expiretime'] = $config['session']['ttl'];
        } elseif (isset($sessionOptions['cookie_lifetime'])) {
            $options['expiretime'] = $sessionOptions['cookie_lifetime'];
        }
        $options['prefix'] = $config['session']['prefix'];
        $options['locking'] = $config['session']['locking'];
        $options['spin_lock_wait'] = $config['session']['spin_lock_wait'];
        $options['lock_max_wait'] = $config['session']['lock_max_wait'];
        // set the auto_load parameter
        $container->setParameter('memcache.session_handler.auto_load', $config['session']['auto_load']);
        // load the session handler
        $definition = new Definition($container->getParameter('memcache.session_handler.class'));
        $definition->setPublic(true);
        $container->setDefinition('memcache.session_handler', $definition);
        $definition
            ->addArgument(new Reference(sprintf('memcache.%s', $pool)))
            ->addArgument($options);
    }

    /**
     * Loads the Doctrine configuration.
     *
     * The services are PSR-6 cache pools (as required by Doctrine ORM 3 and
     * DoctrineBundle 2+) that wrap the Doctrine cache provider for Memcache.
     * The EnableDoctrineSupport compiler pass points the Doctrine cache ids
     * to them, so that DoctrineBundle can not override them.
     *
     * @param array $config A configuration array
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    protected function loadDoctrine(array $config, ContainerBuilder $container)
    {
        $caches = array();
        foreach ($config['doctrine'] as $name => $cache) {
            foreach ($cache['entity_managers'] as $em) {
                $id = sprintf('doctrine.orm.%s_%s', $em, $name);
                $caches[$id] = $this->addDoctrineCache($id, $cache, $container);
            }
            foreach ($cache['document_managers'] as $dm) {
                $id = sprintf('doctrine.odm.mongodb.%s_%s', $dm, $name);
                $caches[$id] = $this->addDoctrineCache($id, $cache, $container);
            }
        }
        $container->setParameter('memcache.doctrine_caches', $caches);
    }

    /**
     * Adds a PSR-6 Doctrine cache service backed by a Memcache pool.
     *
     * @param string           $id        Doctrine cache service id
     * @param array            $cache     Cache configuration
     * @param ContainerBuilder $container Service container
     *
     * @return string The id of the PSR-6 cache service
     */
    private function addDoctrineCache($id, array $cache, ContainerBuilder $container)
    {
        $id = 'memcache.'.$id;
        if (!class_exists(CacheAdapter::class)) {
            throw new \LogicException('The Doctrine cache integration requires the "doctrine/cache" package. Try running "composer require doctrine/cache".');
        }
        $provider = new Definition($container->getParameter('memcache.doctrine_cache.class'));
        $provider->addMethodCall('setMemcache', array(new Reference(sprintf('memcache.%s', $cache['pool']))));
        if ($cache['prefix']) {
            $provider->addMethodCall('setPrefix', array($cache['prefix']));
        }
        $container->setDefinition($id.'.provider', $provider);

        $definition = new Definition(CacheItemPoolInterface::class);
        $definition->setFactory(array(CacheAdapter::class, 'wrap'));
        $definition->addArgument(new Reference($id.'.provider'));
        $container->setDefinition($id, $definition);

        return $id;
    }

    /**
     * Loads the Firewall configuration.
     *
     * @param array $config A configuration array
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    protected function loadFirewall(array $config, ContainerBuilder $container)
    {
        // make sure the pool is specified and it exists
        $pool = $config['firewall']['pool'];
        if (null === $pool) {
            return;
        }
        if (!isset($config['pools']) || !isset($config['pools'][$pool])) {
            throw new \LogicException(sprintf('The pool "%s" does not exist! Cannot enable the firewall!', $pool));
        }
        // calculate options
        $options = array();
        $options['prefix'] = $config['firewall']['prefix'];
        $options['concurrency'] = $config['firewall']['concurrency'];
        $options['spin_lock_wait'] = $config['firewall']['spin_lock_wait'];
        $options['lock_max_wait'] = $config['firewall']['lock_max_wait'];
        $options['reverse_proxies'] = $config['firewall']['reverse_proxies'];
        $options['x_forwarded_for'] = $config['firewall']['x_forwarded_for'];
        // load the firewall handler
        $definition = new Definition($container->getParameter('memcache.firewall_handler.class'));
        $definition->setPublic(true);
        $container->setDefinition('memcache.firewall_handler', $definition);
        $definition
            ->addArgument(new Reference(sprintf('memcache.%s', $pool)))
            ->addArgument($options);
        $definition->addTag('kernel.event_listener', array('event' => 'kernel.request', 'method' => 'onKernelRequest'));
        $definition->addTag('kernel.event_listener', array('event' => 'kernel.terminate', 'method' => 'onKernelTerminate'));
    }

    /**
     * Registers the console commands with a locator for the configured pools
     *
     * @param array            $pools     Names of the configured pools
     * @param ContainerBuilder $container Service container
     */
    private function loadCommands(array $pools, ContainerBuilder $container)
    {
        $references = array();
        foreach ($pools as $pool) {
            $references[$pool] = new Reference(sprintf('memcache.%s', $pool));
        }
        $locator = ServiceLocatorTagPass::register($container, $references);

        foreach (array('memcache.command.clear' => ClearCommand::class, 'memcache.command.statistics' => StatisticsCommand::class) as $id => $class) {
            $definition = new Definition($class);
            $definition->addArgument($locator);
            $definition->addTag('console.command');
            $container->setDefinition($id, $definition);
        }
    }

    /**
     * Adds memcache/memcache pools to the service contaienr
     *
     * @param array            $pools   Array of pool configurations
     * @param ContainerBuilder $container Service container
     *
     * @throws \LogicException
     */
    private function addClients(array $pools, ContainerBuilder $container)
    {
        foreach ($pools as $pool => $memcacheConfig) {
            $this->newMemcacheClient($pool, $memcacheConfig, $container);
        }
    }

    /**
     * Creates a new Memcache definition
     *
     * @param string           $name      Client name
     * @param array            $config    Client configuration
     * @param ContainerBuilder $container Service container
     *
     * @throws \LogicException
     */
    private function newMemcacheClient($name, array $config, ContainerBuilder $container)
    {
        // Check if the Memcache extension is loaded
        if (!extension_loaded('memcache')) {
            throw new \LogicException('Memcache extension is not loaded! To configure pools it MUST be loaded!');
        }

        $memcache = new Definition(AntiDogPileMemcache::class);
        $memcache->setPublic(true);
        $memcache->addArgument(new Parameter('kernel.debug'));

        // Add servers to the memcache pool
        foreach ($config['servers'] as $s) {
            $server = array(
                $s['host'],
                (int) $s['tcp_port'],
                (int) $s['udp_port'],
                $s['persistent'],
                (int) $s['weight'],
                (int) $s['timeout'],
                (int) $s['retry_interval']
            );
            if ($s['host']) {
                $memcache->addMethodCall('addServer', $server);
            }
        }

        $memcache->addArgument($config['options']);

        $options = array();
        // Make sure that config values are human readable
        foreach ($config['options'] as $key => $value) {
            $options[$key] = var_export($value, true);
        }

        // Add the service to the container
        $serviceName = sprintf('memcache.%s', $name);
        $container->setDefinition($serviceName, $memcache);
        // Add the service to the data collector
        if ($container->hasDefinition('memcache.data_collector')) {
            $definition = $container->getDefinition('memcache.data_collector');
            $definition->addMethodCall('addClient', array($name, $options, new Reference($serviceName)));
        }
    }

}
