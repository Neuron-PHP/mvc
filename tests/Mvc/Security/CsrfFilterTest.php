<?php

namespace Tests\Mvc\Security;

use PHPUnit\Framework\TestCase;
use Neuron\Mvc\Security\CsrfToken;
use Neuron\Mvc\Security\CsrfFilter;
use Neuron\Mvc\Exceptions\CsrfValidationException;
use Neuron\Core\System\MemorySession;
use Neuron\Routing\RouteMap;

class CsrfFilterTest extends TestCase
{
	protected function tearDown(): void
	{
		unset( $_SERVER['REQUEST_METHOD'], $_POST['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'] );
	}

	private function route(): RouteMap
	{
		return new RouteMap( '/test', function() {} );
	}

	public function testSafeMethodSkipsValidation(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$filter = new CsrfFilter( new CsrfToken( new MemorySession() ) );

		// No exception expected for a safe method.
		$this->assertNull( $filter->pre( $this->route() ) );
	}

	public function testMissingTokenThrows(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$filter = new CsrfFilter( new CsrfToken( new MemorySession() ) );

		$this->expectException( CsrfValidationException::class );
		$filter->pre( $this->route() );
	}

	public function testValidTokenPasses(): void
	{
		$session = new MemorySession();
		$csrf = new CsrfToken( $session );
		$token = $csrf->getToken();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['csrf_token'] = $token;

		$filter = new CsrfFilter( $csrf );

		$this->assertNull( $filter->pre( $this->route() ) );
	}

	public function testInvalidTokenThrows(): void
	{
		$session = new MemorySession();
		$csrf = new CsrfToken( $session );
		$csrf->getToken();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['csrf_token'] = 'wrong';

		$filter = new CsrfFilter( $csrf );

		$this->expectException( CsrfValidationException::class );
		$filter->pre( $this->route() );
	}
}
