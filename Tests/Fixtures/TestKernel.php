<?php

namespace Tqd\MemcacheBundle\Tests\Fixtures;

use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tqd\MemcacheBundle\TqdMemcacheBundle;

/**
 * Minimal kernel to test the bundle wiring.
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private $memcacheConfig;

    public function __construct(array $memcacheConfig, $debug = true)
    {
        $this->memcacheConfig = $memcacheConfig;
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        // FakeDoctrineBundle is registered last so its extension is loaded after ours
        $bundles = array(new FrameworkBundle(), new TqdMemcacheBundle(), new FakeDoctrineBundle());
        if ($this->debug) {
            $bundles[] = new TwigBundle();
            $bundles[] = new WebProfilerBundle();
        }

        return $bundles;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tqd_memcache_bundle/'.md5(serialize($this->memcacheConfig)).($this->debug ? '_debug' : '').'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tqd_memcache_bundle/logs';
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', array(
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => array('log' => true),
            'session' => array('storage_factory_id' => 'session.storage.factory.native', 'cookie_secure' => 'auto', 'cookie_samesite' => 'lax'),
            'profiler' => array('enabled' => $this->debug),
        ));
        if ($this->debug) {
            $container->loadFromExtension('twig', array('strict_variables' => true));
            $container->loadFromExtension('web_profiler', array('toolbar' => true));
        }
        $container->loadFromExtension('tqd_memcache', $this->memcacheConfig);
        $container->loadFromExtension('fake_doctrine', array());
        $container->register('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('session', '/session')->controller(array($this, 'sessionAction'));
        $routes->add('hello', '/hello')->controller(array($this, 'helloAction'));
        if ($this->debug) {
            // Symfony 6.4 only ships XML routing files, 7.x prefers the PHP ones
            $dir = dirname((new \ReflectionClass(WebProfilerBundle::class))->getFileName()).'/Resources/config/routing';
            $ext = file_exists($dir.'/wdt.php') ? 'php' : 'xml';
            $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.'.$ext)->prefix('/_wdt');
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.'.$ext)->prefix('/_profiler');
        }
    }

    public function helloAction(): Response
    {
        return new Response('hello');
    }

    public function sessionAction(Request $request): Response
    {
        $session = $request->getSession();
        $session->set('counter', $session->get('counter', 0) + 1);

        return new Response((string) $session->get('counter'));
    }
}
