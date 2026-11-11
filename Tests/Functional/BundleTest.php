<?php

namespace Tqd\MemcacheBundle\Tests\Functional;

use Doctrine\Common\Cache\Psr6\CacheAdapter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tqd\MemcacheBundle\Cache\AntiDogPileMemcache;
use Tqd\MemcacheBundle\DataCollector\MemcacheDataCollector;
use Tqd\MemcacheBundle\Firewall\FirewallHandler;
use Tqd\MemcacheBundle\Session\Storage\LockingSessionHandler;
use Tqd\MemcacheBundle\Tests\Fixtures\TestKernel;

/**
 * Boots a kernel with the bundle and checks the wiring against a local memcached.
 */
class BundleTest extends \PHPUnit\Framework\TestCase
{
    private $kernel;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir().'/tqd_memcache_bundle');
    }

    protected function tearDown(): void
    {
        if ($this->kernel) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }
    }

    private function bootKernel(array $config = array(), $debug = true)
    {
        $config = array_replace(array(
            'session' => array('pool' => 'sessions', 'prefix' => 'test_session_', 'locking' => false),
            'firewall' => array('pool' => 'default', 'prefix' => 'test_firewall', 'concurrency' => 1, 'spin_lock_wait' => 0, 'reverse_proxies' => array('10.0.0.1')),
            'doctrine' => array('metadata_cache' => array('pool' => 'default', 'entity_manager' => 'default', 'prefix' => 'test_doctrine_')),
            'pools' => array(
                'default' => array('servers' => array(array('host' => 'localhost', 'tcp_port' => 11211))),
                'sessions' => array('servers' => array(array('host' => 'localhost', 'tcp_port' => 11211))),
            ),
        ), $config);
        $this->kernel = new TestKernel($config, $debug);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        // reset the firewall counters that a failed earlier run may have left behind
        foreach (array('127.0.0.1', '192.0.2.10') as $ip) {
            $container->get('memcache.default')->delete('test_firewall_'.$ip);
        }

        return $container;
    }

    public function testPoolServices()
    {
        $container = $this->bootKernel();

        $pool = $container->get('memcache.default');
        $this->assertInstanceOf(AntiDogPileMemcache::class, $pool);
        $this->assertTrue($pool->set('test_pool_key', 'value', 0, 10));
        $this->assertSame('value', $pool->get('test_pool_key'));

        $this->assertTrue($pool->setAdp('test_adp_key', array('a' => 1), 10));
        $this->assertEquals((object) array('a' => 1), $pool->getAdp('test_adp_key'));
    }

    /**
     * Native sessions can not start after PHPUnit has written output.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSessionHandlerIsUsed()
    {
        $container = $this->bootKernel();
        $this->assertInstanceOf(LockingSessionHandler::class, $container->get('memcache.session_handler'));

        $response = $this->kernel->handle(Request::create('/session', 'GET', array(), array(), array(), array('REMOTE_ADDR' => '127.0.0.1')));
        $this->assertSame('1', $response->getContent());
        $sessionId = session_id();
        $this->assertNotEmpty($sessionId);
        $this->kernel->terminate(Request::create('/session'), $response);
        session_write_close();

        $stored = $container->get('memcache.sessions')->get('test_session_'.$sessionId);
        $this->assertIsString($stored);
        $this->assertStringContainsString('counter', $stored);
    }

    public function testSessionHandlerLocking()
    {
        $memcache = $this->bootKernel()->get('memcache.sessions');
        $options = array('prefix' => 'test_lock_', 'locking' => true, 'spin_lock_wait' => 100000, 'lock_max_wait' => 1);
        $id = 'locktest'.uniqid();

        $first = new LockingSessionHandler($memcache, $options);
        $this->assertTrue($first->open('', 'test'));
        $this->assertSame('', $first->read($id));
        $this->assertTrue($first->write($id, 'data'));

        // a second handler can not get the lock while the first holds it
        $second = new LockingSessionHandler($memcache, $options);
        $this->assertFalse($second->read($id));

        $this->assertTrue($first->close());
        $this->assertSame('data', $second->read($id));
        $this->assertTrue($second->destroy($id));
        $this->assertSame(0, $second->gc(100));
    }

    public function testFirewallLimitsConcurrency()
    {
        $container = $this->bootKernel();
        $firewall = $container->get('memcache.firewall_handler');
        $this->assertInstanceOf(FirewallHandler::class, $firewall);

        $request = Request::create('/hello', 'GET', array(), array(), array(), array('REMOTE_ADDR' => '192.0.2.10'));
        $response = $this->kernel->handle($request);
        $this->assertSame('hello', $response->getContent());

        // the first request is still running (not terminated), a second one is refused
        $other = new FirewallHandler($container->get('memcache.default'), array(
            'prefix' => 'test_firewall', 'concurrency' => 1, 'spin_lock_wait' => 0, 'lock_max_wait' => 300,
            'reverse_proxies' => array(), 'x_forwarded_for' => false,
        ));
        $event = new \Symfony\Component\HttpKernel\Event\RequestEvent($this->kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
        try {
            $other->onKernelRequest($event);
            $this->fail('Expected TooManyRequestsHttpException');
        } catch (TooManyRequestsHttpException $e) {
        }

        $this->kernel->terminate($request, $response);
        $other->onKernelRequest($event);
        $this->assertSame(1, $container->get('memcache.default')->get('test_firewall_192.0.2.10'));
    }

    public function testFirewallClientIp()
    {
        $firewall = $this->bootKernel()->get('memcache.firewall_handler');

        $direct = Request::create('/', 'GET', array(), array(), array(), array('REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'));
        $this->assertSame('192.0.2.1', $firewall->getClientIp($direct));

        $proxied = Request::create('/', 'GET', array(), array(), array(), array('REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1'));
        $this->assertSame('198.51.100.7', $firewall->getClientIp($proxied));

        $custom = new FirewallHandler($this->kernel->getContainer()->get('memcache.default'), array(
            'prefix' => 'x', 'concurrency' => 1, 'spin_lock_wait' => 0, 'lock_max_wait' => 1,
            'reverse_proxies' => array('10.0.0.0/8'), 'x_forwarded_for' => 'X-Real-Ip',
        ));
        $proxied = Request::create('/', 'GET', array(), array(), array(), array('REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_REAL_IP' => '198.51.100.8', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'));
        $this->assertSame('198.51.100.8', $custom->getClientIp($proxied));
    }

    public function testDoctrineCache()
    {
        if (!class_exists(CacheAdapter::class)) {
            $this->markTestSkipped('doctrine/cache is not installed');
        }
        $cache = $this->bootKernel()->get('doctrine.orm.default_metadata_cache');
        $this->assertInstanceOf(CacheAdapter::class, $cache);

        $item = $cache->getItem('key');
        $item->set(array('some' => 'data'));
        $this->assertTrue($cache->save($item));
        $this->assertSame(array('some' => 'data'), $cache->getItem('key')->get());
        $this->assertTrue($cache->getItem('key')->isHit());
    }

    public function testCommands()
    {
        $this->bootKernel();
        $application = new Application($this->kernel);

        $tester = new CommandTester($application->find('memcache:statistics'));
        $this->assertSame(0, $tester->execute(array('pool' => 'default')));
        $this->assertStringContainsString('Servers found: 1', $tester->getDisplay());

        $this->assertSame(1, $tester->execute(array('pool' => 'missing')));
        $this->assertStringContainsString("pool 'missing' is not found", $tester->getDisplay());

        $tester = new CommandTester($application->find('memcache:clear'));
        $tester->setInputs(array('default'));
        $this->assertSame(0, $tester->execute(array(), array('interactive' => true)));
        $this->assertStringContainsString('OK', $tester->getDisplay());
    }

    public function testDataCollector()
    {
        $container = $this->bootKernel()->get('test.service_container');
        $this->assertTrue($container->has('memcache.data_collector'));
        $container->get('memcache.default')->get('test_collector_key');

        /** @var MemcacheDataCollector $collector */
        $collector = $container->get('memcache.data_collector');
        $collector->collect(Request::create('/'), new \Symfony\Component\HttpFoundation\Response());
        $totals = $collector->getTotals();
        $this->assertGreaterThanOrEqual(1, $totals['reads']);
        $this->assertSame('memcache', $collector->getName());
        $collector->reset();
    }

    /**
     * The Symfony 6.4 profiler layout touches the (native) session.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProfilerPanel()
    {
        $container = $this->bootKernel();
        $request = Request::create('/hello', 'GET', array(), array(), array(), array('REMOTE_ADDR' => '127.0.0.1'));
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        $token = $response->headers->get('X-Debug-Token');
        $this->assertNotEmpty($token);

        $toolbar = $this->kernel->handle($request = Request::create('/_wdt/'.$token));
        $this->kernel->terminate($request, $toolbar);
        $this->assertSame(200, $toolbar->getStatusCode(), $toolbar->getContent());
        $this->assertStringContainsString('Memcache Calls', $toolbar->getContent());

        $panel = $this->kernel->handle($request = Request::create('/_profiler/'.$token, 'GET', array('panel' => 'memcache')));
        $this->kernel->terminate($request, $panel);
        $this->assertSame(200, $panel->getStatusCode(), $panel->getContent());
        $this->assertStringContainsString("Statistics for pool 'default'", $panel->getContent());
        $this->assertStringContainsString('increment', $panel->getContent());
    }

    public function testNoDataCollectorWithoutDebug()
    {
        $container = $this->bootKernel(array(), false)->get('test.service_container');
        $this->assertFalse($container->has('memcache.data_collector'));
    }
}
