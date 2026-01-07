<?php

namespace Tests\Mvc;

use Neuron\Core\Exceptions\Forbidden;
use Neuron\Core\Exceptions\Unauthorized;
use Neuron\Mvc\Events\Http401;
use Neuron\Mvc\Events\Http403;
use PHPUnit\Framework\TestCase;

/**
 * Simple tests for HTTP 401/403 functionality
 * Tests the exceptions and events directly without full MVC integration
 */
class SimpleHttpStatusTest extends TestCase
{
	public function testUnauthorizedExceptionProperties(): void
	{
		$exception = new Unauthorized( 'Need login', 'Admin Area', 401 );

		$this->assertEquals( 'Need login', $exception->getMessage() );
		$this->assertEquals( 'Admin Area', $exception->getRealm() );
		$this->assertEquals( 401, $exception->getCode() );
	}

	public function testForbiddenExceptionProperties(): void
	{
		$exception = new Forbidden( 'No access', 'User Profile', 'user.edit', 403 );

		$this->assertEquals( 'No access', $exception->getMessage() );
		$this->assertEquals( 'User Profile', $exception->getResource() );
		$this->assertEquals( 'user.edit', $exception->getPermission() );
		$this->assertEquals( 403, $exception->getCode() );
	}

	public function testHttp401EventProperties(): void
	{
		$event = new Http401( '/admin/dashboard', 'Protected Area' );

		$this->assertEquals( '/admin/dashboard', $event->route );
		$this->assertEquals( 'Protected Area', $event->realm );
		$this->assertInstanceOf( \Neuron\Events\IEvent::class, $event );
	}

	public function testHttp403EventProperties(): void
	{
		$event = new Http403( '/users/123/delete', 'User #123', 'users.delete' );

		$this->assertEquals( '/users/123/delete', $event->route );
		$this->assertEquals( 'User #123', $event->resource );
		$this->assertEquals( 'users.delete', $event->permission );
		$this->assertInstanceOf( \Neuron\Events\IEvent::class, $event );
	}

	public function testExceptionInheritance(): void
	{
		$unauthorized = new Unauthorized();
		$forbidden = new Forbidden();

		$this->assertInstanceOf( \Exception::class, $unauthorized );
		$this->assertInstanceOf( \Throwable::class, $unauthorized );
		$this->assertInstanceOf( \Exception::class, $forbidden );
		$this->assertInstanceOf( \Throwable::class, $forbidden );
	}

	public function testThrowingUnauthorized(): void
	{
		$this->expectException( Unauthorized::class );
		$this->expectExceptionMessage( 'Invalid token' );
		$this->expectExceptionCode( 401 );

		throw new Unauthorized( 'Invalid token' );
	}

	public function testThrowingForbidden(): void
	{
		$this->expectException( Forbidden::class );
		$this->expectExceptionMessage( 'Cannot delete' );
		$this->expectExceptionCode( 403 );

		throw new Forbidden( 'Cannot delete' );
	}

	public function testExceptionChaining(): void
	{
		$previous = new \RuntimeException( 'Database error' );
		$unauthorized = new Unauthorized( 'Auth failed', null, 401, $previous );
		$forbidden = new Forbidden( 'Access denied', null, null, 403, $previous );

		$this->assertSame( $previous, $unauthorized->getPrevious() );
		$this->assertSame( $previous, $forbidden->getPrevious() );
	}
}