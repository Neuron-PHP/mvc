<?php

namespace Tests\Mvc\Security;

use PHPUnit\Framework\TestCase;
use Neuron\Mvc\Security\CsrfToken;
use Neuron\Core\System\MemorySession;

class CsrfTokenTest extends TestCase
{
	public function testGenerateStoresTokenInSession(): void
	{
		$session = new MemorySession();
		$csrf = new CsrfToken( $session );

		$token = $csrf->generate();

		$this->assertNotEmpty( $token );
		$this->assertEquals( $token, $session->get( 'csrf_token' ) );
	}

	public function testGetTokenReturnsExistingToken(): void
	{
		$session = new MemorySession();
		$csrf = new CsrfToken( $session );

		$token = $csrf->getToken();

		$this->assertEquals( $token, $csrf->getToken() );
	}

	public function testValidateAcceptsCorrectTokenOnce(): void
	{
		$session = new MemorySession();
		$csrf = new CsrfToken( $session );

		$token = $csrf->getToken();

		$this->assertTrue( $csrf->validate( $token ) );
		// Single-use: the same token must not validate twice.
		$this->assertFalse( $csrf->validate( $token ) );
	}

	public function testValidateRejectsWrongToken(): void
	{
		$session = new MemorySession();
		$csrf = new CsrfToken( $session );
		$csrf->getToken();

		$this->assertFalse( $csrf->validate( 'not-the-token' ) );
	}

	public function testValidateRejectsWhenNoTokenStored(): void
	{
		$csrf = new CsrfToken( new MemorySession() );

		$this->assertFalse( $csrf->validate( 'anything' ) );
	}

	public function testRegenerateProducesNewToken(): void
	{
		$session = new MemorySession();
		$csrf = new CsrfToken( $session );

		$first = $csrf->getToken();
		$second = $csrf->regenerate();

		$this->assertNotEquals( $first, $second );
		$this->assertEquals( $second, $session->get( 'csrf_token' ) );
	}
}
