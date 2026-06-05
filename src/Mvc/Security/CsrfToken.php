<?php

namespace Neuron\Mvc\Security;

use Neuron\Core\System\ISession;
use Neuron\Core\System\RealSession;
use Neuron\Core\System\IRandom;
use Neuron\Core\System\RealRandom;

/**
 * Framework-level CSRF token service.
 *
 * Generates and validates single-use CSRF tokens backed by the core
 * {@see ISession} abstraction (defaulting to a real PHP session). The session
 * key ('csrf_token') matches the CMS implementation so both stay compatible.
 *
 * @package Neuron\Mvc\Security
 */
class CsrfToken
{
	private ISession $_session;
	private IRandom $_random;
	private string $_tokenKey = 'csrf_token';

	/**
	 * @param ISession|null $session Session abstraction (defaults to RealSession)
	 * @param IRandom|null $random Random source (defaults to RealRandom)
	 */
	public function __construct( ?ISession $session = null, ?IRandom $random = null )
	{
		$this->_session = $session ?? new RealSession();
		$this->_random = $random ?? new RealRandom();
	}

	/**
	 * Generate and store a new CSRF token.
	 */
	public function generate(): string
	{
		$token = $this->_random->string( 64, 'hex' );
		$this->_session->set( $this->_tokenKey, $token );

		return $token;
	}

	/**
	 * Get the current CSRF token, generating one if absent.
	 */
	public function getToken(): string
	{
		if( !$this->_session->has( $this->_tokenKey ) )
		{
			return $this->generate();
		}

		return $this->_session->get( $this->_tokenKey );
	}

	/**
	 * Validate a CSRF token. Valid tokens are consumed (single-use).
	 */
	public function validate( string $token ): bool
	{
		$storedToken = $this->_session->get( $this->_tokenKey );

		if( !$storedToken )
		{
			return false;
		}

		$isValid = hash_equals( $storedToken, $token );

		if( $isValid )
		{
			$this->_session->remove( $this->_tokenKey );
		}

		return $isValid;
	}

	/**
	 * Regenerate the CSRF token.
	 */
	public function regenerate(): string
	{
		return $this->generate();
	}
}
