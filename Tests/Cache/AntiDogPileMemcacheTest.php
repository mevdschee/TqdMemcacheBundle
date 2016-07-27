<?php

namespace Tqd\MemcacheBundle\Tests\Cache;


use Tqd\MemcacheBundle\Cache\AntiDogPileMemcache;

/**
 * Testing the AntiDogPileMemcache Class.
 *
 * @author Julius Beckmann <github@h4cc.de>
 */
class AntiDogPileMemcacheTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructAndInterfaces()
    {
        $cache = new AntiDogPileMemcache('foo',array());

        $this->assertInstanceOf('\Tqd\MemcacheBundle\Cache\LoggingMemcache', $cache);
    }
}
 