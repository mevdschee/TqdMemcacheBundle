<?php
namespace Tqd\MemcacheBundle\Firewall;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * FirewallHandler.
 *
 * This Firewall Handler class allows to throttle concurrent requests.
 *
 * @author Maurits van der Schee <m.vanderschee@leaseweb.com>
 */
class FirewallHandler
{

	/**
	 * @var integer Microseconds to wait between acquire lock tries
	 */
	private $spinLockWait;
	
	/**
	 * @var integer Maximum amount of seconds to wait for the lock
	 */
	private $lockMaxWait;
	
	/**
	 * @var \MemcachePool Memcache driver.
	 */
	private $memcache;
	
	/**
	 * @var string Key prefix for shared environments.
	 */
	private $prefix;
	
	/**
	 * @var integer Number of allowed concurrent requests.
	 */
	private $concurrency;
	
	/**
	 * @var array List of reverse proxies that should be trusted.
	 */
	private $reverseProxies;
	
	/**
	 * @var string The name of the header field that holds the client IP address.
	 */
	private $xForwardedFor;
	
	/**
	 * @var boolean Keep track of concurrency counter increment
	 */
	private $incremented;

	/**
	 * @var string Key that is used in this request as the concurrency counter
	 */
	private $key;
	
	/**
	 * Constructor.
	 *
     * List of available options:
     *  * prefix: The prefix to use for the memcache keys in order to avoid collision
     *  * concurrency: The time to live in seconds
     *  * reverse_proxies: List of reverse proxies that should be trusted
     *  * x_forwarded_for: The name of the header field that holds the client IP address
     *  * spin_lock_wait: Microseconds to wait between acquire lock tries
     *  * lock_max_wait: Maximum amount of seconds to wait for the lock
     *  
	 * @param \MemcachePool $memcache  A \MemcachePool instance
	 * @param array         $options   An associative array of Memcache options
	 *
	 * @throws \InvalidArgumentException When unsupported options are passed
	 */
	public function __construct(\MemcachePool $memcache, array $options = array())
	{
		$this->memcache = $memcache;
	
		if ($diff = array_diff(array_keys($options), array('prefix', 'concurrency', 'reverse_proxies', 'x_forwarded_for', 'spin_lock_wait', 'lock_max_wait'))) {
			throw new \InvalidArgumentException(sprintf(
					'The following options are not supported "%s"', implode(', ', $diff)
			));
		}
	
		$this->prefix = $options['prefix'];
		$this->concurrency = $options['concurrency'];
		$this->reverseProxies = $options['reverse_proxies'];
		$this->xForwardedFor = $options['x_forwarded_for'];
		$this->spinLockWait = $options['spin_lock_wait'];
		$this->lockMaxWait = $options['lock_max_wait'];
		$this->incremented = false;
		$this->key = false;
	}	
	
	/**
	 * If the current concurrency is too high, delay or throw a TooManyRequestsHttpException
	 *
	 * @param RequestEvent $event Event
	 *
	 * @throws \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException
	 */
	public function onKernelRequest(RequestEvent $event)
	{
		if (!$event->isMainRequest()) {
			return;
		}
		
		$ip = $this->getClientIp($event->getRequest());
		$start = microtime(true);

		$this->key=$this->prefix.'_'.$ip;
		
		$this->memcache->add($this->key,0,0,(int) $this->lockMaxWait);
		while (true) {
			$this->incremented = true;
			if ($this->memcache->increment($this->key)<=$this->concurrency) {
				break;
			}
			$this->incremented = false;
			$this->memcache->decrement($this->key);
			if (!$this->spinLockWait || microtime(true)-$start>$this->lockMaxWait) {
				throw new TooManyRequestsHttpException();
			}
			usleep($this->spinLockWait);
		}
	}
	
	/**
	 * If the current request has ended, decrement concurrency counter
	 *
	 * @param TerminateEvent $event Event
	 *
	 */
	public function onKernelTerminate(TerminateEvent $event)
	{
		if (!$this->incremented) {
			return;
		}
		
		$this->incremented = false;
		$this->memcache->decrement($this->key);
	}
	
	/**
	 * Determine the client IP address. The forwarded header (X-Forwarded-For
	 * or the configured "x_forwarded_for" header) is only used when the
	 * request comes from one of the configured reverse proxies.
	 *
	 * @param Request $request The request
	 *
	 * @return string|null The client IP address
	 */
	public function getClientIp(Request $request)
	{
		$ip = $request->server->get('REMOTE_ADDR');
		if (!$this->reverseProxies || !$ip || !IpUtils::checkIp($ip, $this->reverseProxies)) {
			return $ip;
		}
		$header = $request->headers->get($this->xForwardedFor ?: 'X-Forwarded-For');
		if (!$header) {
			return $ip;
		}
		$ips = array_values(array_filter(array_map('trim', explode(',', $header)), function ($v) {
			return filter_var($v, FILTER_VALIDATE_IP) !== false;
		}));
		// walk back from the closest hop and return the first untrusted address
		for ($i = count($ips) - 1; $i >= 0; $i--) {
			if (!IpUtils::checkIp($ips[$i], $this->reverseProxies)) {
				return $ips[$i];
			}
		}
		return $ips ? $ips[0] : $ip;
	}

}
