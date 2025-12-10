<?php

namespace Tests\Mvc\Events;

use Neuron\Mvc\Events\Http404;
use Neuron\Mvc\Events\Http500;
use Neuron\Mvc\Events\RequestReceivedEvent;
use Neuron\Mvc\Events\RateLimitExceededEvent;
use Neuron\Mvc\Events\ViewCacheHitEvent;
use Neuron\Mvc\Events\ViewCacheMissEvent;
use PHPUnit\Framework\TestCase;

class EventsTest extends TestCase
{
	public function testHttp404Event(): void
	{
		$event = new Http404( '/test/route' );

		$this->assertEquals( '/test/route', $event->route );
	}

	public function testHttp500Event(): void
	{
		$event = new Http500(
			'/error/route',
			'RuntimeException',
			'Something went wrong',
			'/path/to/file.php',
			42
		);

		$this->assertEquals( '/error/route', $event->route );
		$this->assertEquals( 'RuntimeException', $event->exceptionType );
		$this->assertEquals( 'Something went wrong', $event->message );
		$this->assertEquals( '/path/to/file.php', $event->file );
		$this->assertEquals( 42, $event->line );
	}

	public function testRequestReceivedEvent(): void
	{
		$timestamp = microtime( true );
		$event = new RequestReceivedEvent(
			'GET',
			'/api/users',
			'192.168.1.100',
			$timestamp
		);

		$this->assertEquals( 'GET', $event->method );
		$this->assertEquals( '/api/users', $event->route );
		$this->assertEquals( '192.168.1.100', $event->ip );
		$this->assertEquals( $timestamp, $event->timestamp );
		$this->assertEquals( 'request.received', $event->getName() );
	}

	public function testRequestReceivedEventWithDifferentMethods(): void
	{
		$methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

		foreach( $methods as $method )
		{
			$event = new RequestReceivedEvent(
				$method,
				'/test',
				'127.0.0.1',
				microtime( true )
			);

			$this->assertEquals( $method, $event->method );
		}
	}

	public function testRateLimitExceededEvent(): void
	{
		$event = new RateLimitExceededEvent(
			'203.0.113.42',
			'/api/data',
			100,
			3600,
			150
		);

		$this->assertEquals( '203.0.113.42', $event->ip );
		$this->assertEquals( '/api/data', $event->route );
		$this->assertEquals( 100, $event->limit );
		$this->assertEquals( 3600, $event->window );
		$this->assertEquals( 150, $event->attempts );
		$this->assertEquals( 'rate_limit.exceeded', $event->getName() );
	}

	public function testRateLimitExceededEventWithDifferentLimits(): void
	{
		$event1 = new RateLimitExceededEvent( '1.2.3.4', '/test', 10, 60, 15 );
		$this->assertEquals( 10, $event1->limit );
		$this->assertEquals( 60, $event1->window );

		$event2 = new RateLimitExceededEvent( '5.6.7.8', '/api', 1000, 86400, 1500 );
		$this->assertEquals( 1000, $event2->limit );
		$this->assertEquals( 86400, $event2->window );
	}

	public function testViewCacheHitEvent(): void
	{
		$event = new ViewCacheHitEvent( 'UserController', 'profile', 'user.profile.123' );

		$this->assertEquals( 'UserController', $event->controller );
		$this->assertEquals( 'profile', $event->page );
		$this->assertEquals( 'user.profile.123', $event->cacheKey );
		$this->assertEquals( 'view.cache_hit', $event->getName() );
	}

	public function testViewCacheHitEventWithDifferentValues(): void
	{
		$event1 = new ViewCacheHitEvent( 'HomeController', 'index', 'homepage' );
		$this->assertEquals( 'HomeController', $event1->controller );
		$this->assertEquals( 'index', $event1->page );
		$this->assertEquals( 'homepage', $event1->cacheKey );

		$event2 = new ViewCacheHitEvent( 'ProductController', 'detail', 'product.456' );
		$this->assertEquals( 'ProductController', $event2->controller );
		$this->assertEquals( 'detail', $event2->page );
		$this->assertEquals( 'product.456', $event2->cacheKey );
	}

	public function testViewCacheMissEvent(): void
	{
		$event = new ViewCacheMissEvent( 'PostController', 'show', 'post.123.comments' );

		$this->assertEquals( 'PostController', $event->controller );
		$this->assertEquals( 'show', $event->page );
		$this->assertEquals( 'post.123.comments', $event->cacheKey );
		$this->assertEquals( 'view.cache_miss', $event->getName() );
	}

	public function testViewCacheMissEventWithDifferentValues(): void
	{
		$event1 = new ViewCacheMissEvent( 'DashboardController', 'overview', 'dashboard' );
		$this->assertEquals( 'DashboardController', $event1->controller );
		$this->assertEquals( 'overview', $event1->page );
		$this->assertEquals( 'dashboard', $event1->cacheKey );

		$event2 = new ViewCacheMissEvent( 'ReportController', 'monthly', 'report.2024-12' );
		$this->assertEquals( 'ReportController', $event2->controller );
		$this->assertEquals( 'monthly', $event2->page );
		$this->assertEquals( 'report.2024-12', $event2->cacheKey );
	}

	public function testEventNamesAreConsistent(): void
	{
		$requestEvent = new RequestReceivedEvent( 'GET', '/', '127.0.0.1', microtime( true ) );
		$rateLimitEvent = new RateLimitExceededEvent( '1.1.1.1', '/', 10, 60, 15 );
		$cacheHitEvent = new ViewCacheHitEvent( 'Test', 'page', 'test.key' );
		$cacheMissEvent = new ViewCacheMissEvent( 'Test', 'page', 'test.key' );

		// Verify event names are strings
		$this->assertIsString( $requestEvent->getName() );
		$this->assertIsString( $rateLimitEvent->getName() );
		$this->assertIsString( $cacheHitEvent->getName() );
		$this->assertIsString( $cacheMissEvent->getName() );

		// Verify event names are not empty
		$this->assertNotEmpty( $requestEvent->getName() );
		$this->assertNotEmpty( $rateLimitEvent->getName() );
		$this->assertNotEmpty( $cacheHitEvent->getName() );
		$this->assertNotEmpty( $cacheMissEvent->getName() );
	}
}
