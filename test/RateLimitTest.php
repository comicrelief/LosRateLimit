<?php

namespace LosMiddlewareTest\RateLimit;

use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;
use LosMiddleware\RateLimit\RateLimit;
use Zend\Session\Container;
use Zend\ServiceManager\ServiceManager;
use Zend\ServiceManager\Config;
use LosMiddleware\RateLimit\RateLimitFactory;
use LosMiddleware\RateLimit\Storage\ArrayStorage;
use LosMiddleware\RateLimit\Exception\MissingParameterException;

class RateLimitTest extends \PHPUnit_Framework_TestCase
{
    protected $middleware;

    protected function setUp()
    {
        $container = new ServiceManager(new Config([]));
        $container->setService('config', [
            'los_rate_limit' => [
                'max_requests' => 2,
                'reset_time' => 10,
                'ip_max_requests' => 2,
                'ip_reset_time' => 10,
                'api_header' => 'X-Api-Key',
                'trust_forwarded' => true,
                'prefer_forwarded' => false,
            ],
        ]);
        //$factory = new RateLimitFactory();
        //$this->middleware = $factory($container);
        $config = $container->get('config');
        $rateConfig = array_key_exists('los_rate_limit', $config) ? $config['los_rate_limit'] : [];
        $this->middleware = new RateLimit(new ArrayStorage(), $rateConfig);
    }

    /**
     * @covers LosMiddleware\RateLimit\RateLimit::__construct
     * @covers LosMiddleware\RateLimit\RateLimit::__invoke
     */
    public function testNeedIpOuApiKey()
    {
        $request = new ServerRequest();
        $response = new Response();

        $outFunction = function ($request, $response) {
            return $response;
        };

        $this->setExpectedException(MissingParameterException::class);
        call_user_func($this->middleware, $request, $response, $outFunction);
    }

    /**
     * @covers LosMiddleware\RateLimit\RateLimit::__invoke
     */
    public function testAddHeadersForApiKey()
    {
        $request = new ServerRequest();
        $request = $request->withHeader('X-Api-Key', '123');
        $response = new Response();

        $outFunction = function ($request, $response) {
            return $response;
        };

        $result = call_user_func($this->middleware, $request, $response, $outFunction);

        $this->assertNotSame($response, $result);
        $this->assertArrayHasKey(RateLimit::HEADER_REMAINING, $result->getHeaders());
        $this->assertArrayHasKey(RateLimit::HEADER_LIMIT, $result->getHeaders());
        $this->assertArrayHasKey(RateLimit::HEADER_RESET, $result->getHeaders());

        $this->assertEquals(2, $result->getHeader(RateLimit::HEADER_REMAINING)[0]);
        $this->assertLessThanOrEqual(10, $result->getHeader(RateLimit::HEADER_RESET)[0]);
        $this->assertEquals(2, $result->getHeader(RateLimit::HEADER_LIMIT)[0]);
    }

    /**
     * @covers LosMiddleware\RateLimit\RateLimit::__invoke
     * @covers LosMiddleware\RateLimit\RateLimit::getClientIp
     */
    public function testAddHeadersForIp()
    {
        $request = new ServerRequest(['REMOTE_ADDR' => '127.0.0.1']);
        $request = $request->withHeader('X-Forwarded-For', '192.168.1.1');
        $response = new Response();

        $outFunction = function ($request, $response) {
            return $response;
        };

        $result = call_user_func($this->middleware, $request, $response, $outFunction);

        $this->assertNotSame($response, $result);
        $this->assertArrayHasKey(RateLimit::HEADER_REMAINING, $result->getHeaders());
        $this->assertArrayHasKey(RateLimit::HEADER_LIMIT, $result->getHeaders());
        $this->assertArrayHasKey(RateLimit::HEADER_RESET, $result->getHeaders());

        $this->assertEquals(2, $result->getHeader(RateLimit::HEADER_REMAINING)[0]);
        $this->assertLessThanOrEqual(10, $result->getHeader(RateLimit::HEADER_RESET)[0]);
        $this->assertEquals(2, $result->getHeader(RateLimit::HEADER_LIMIT)[0]);
    }

    /**
     * @covers LosMiddleware\RateLimit\RateLimit::__invoke
     */
    public function testDecreaseRemaining()
    {
        $request = new ServerRequest();
        $request = $request->withHeader('X-Api-Key', '123');
        $response = new Response();

        $outFunction = function ($request, $response) {
            return $response;
        };

        $result = call_user_func($this->middleware, $request, $response, $outFunction);
        $result = call_user_func($this->middleware, $request, $response, $outFunction);

        $this->assertEquals(1, $result->getHeader(RateLimit::HEADER_REMAINING)[0]);
        $this->assertLessThanOrEqual(10, $result->getHeader(RateLimit::HEADER_RESET)[0]);
        $this->assertEquals(2, $result->getHeader(RateLimit::HEADER_LIMIT)[0]);
    }

    /**
     * @covers LosMiddleware\RateLimit\RateLimit::__invoke
     */
    public function testGenerate429()
    {
        $request = new ServerRequest();
        $request = $request->withHeader('X-Api-Key', '123');
        $response = new Response();

        $outFunction = function ($request, $response) {
            return $response;
        };

        $result = call_user_func($this->middleware, $request, $response, $outFunction);
        $result = call_user_func($this->middleware, $request, $response, $outFunction);
        $result = call_user_func($this->middleware, $request, $response, $outFunction);
        $result = call_user_func($this->middleware, $request, $response, $outFunction);

        $this->assertArrayNotHasKey(RateLimit::HEADER_REMAINING, $result->getHeaders());
        $this->assertArrayNotHasKey(RateLimit::HEADER_LIMIT, $result->getHeaders());
        $this->assertLessThanOrEqual(10, $result->getHeader(RateLimit::HEADER_RESET)[0]);
        $this->assertSame(429, $result->getStatusCode());
    }

    /**
     * @covers LosMiddleware\RateLimit\RateLimit::__invoke
     */
    public function testReset()
    {
        $container = new Container('LosRateLimit');
        $container->offsetSet('remaining', 0);
        $container->offsetSet('created', strtotime('-20 second'));

        $request = new ServerRequest();
        $request = $request->withHeader('X-Api-Key', '123');
        $response = new Response();

        $outFunction = function ($request, $response) {
            return $response;
        };

        $result = call_user_func($this->middleware, $request, $response, $outFunction);

        $this->assertArrayHasKey(RateLimit::HEADER_REMAINING, $result->getHeaders());
        $this->assertArrayHasKey(RateLimit::HEADER_LIMIT, $result->getHeaders());
        $this->assertLessThanOrEqual(10, $result->getHeader(RateLimit::HEADER_RESET)[0]);
    }
}
