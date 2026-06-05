<?php

namespace Neuron\Mvc\Security;

use Neuron\Routing\Filter;
use Neuron\Routing\RouteMap;
use Neuron\Mvc\Exceptions\CsrfValidationException;
use Neuron\Log\Log;

/**
 * Framework-level CSRF protection filter.
 *
 * Validates CSRF tokens on state-changing requests (POST, PUT, DELETE, PATCH)
 * to prevent Cross-Site Request Forgery. Register as the 'csrf' route filter
 * and apply via `filters: ['csrf']` on unsafe-method routes.
 *
 * @package Neuron\Mvc\Security
 */
class CsrfFilter extends Filter
{
	private CsrfToken $_csrfToken;

	/** @var list<string> */
	private array $_exemptMethods = [ 'GET', 'HEAD', 'OPTIONS' ];

	public function __construct( CsrfToken $csrfToken )
	{
		$this->_csrfToken = $csrfToken;

		parent::__construct(
			function( RouteMap $route ) { $this->validateCsrfToken( $route ); },
			null
		);
	}

	/**
	 * Validate the request's CSRF token.
	 *
	 * @throws CsrfValidationException When the token is missing or invalid
	 */
	protected function validateCsrfToken( RouteMap $route ): void
	{
		$method = $_SERVER[ 'REQUEST_METHOD' ] ?? 'GET';

		if( in_array( strtoupper( $method ), $this->_exemptMethods, true ) )
		{
			return;
		}

		$token = $this->getTokenFromRequest();

		if( !$token )
		{
			Log::warning( 'CSRF token missing from request' );
			throw new CsrfValidationException(
				'CSRF token missing from request',
				'CSRF token missing'
			);
		}

		if( !$this->_csrfToken->validate( $token ) )
		{
			Log::warning( 'Invalid CSRF token' );
			throw new CsrfValidationException(
				'Invalid CSRF token provided',
				'Invalid CSRF token'
			);
		}
	}

	/**
	 * Extract the CSRF token from the POST body or X-CSRF-Token header.
	 */
	private function getTokenFromRequest(): ?string
	{
		$token = \Neuron\Data\Filters\Post::filterScalar( 'csrf_token' );

		if( $token )
		{
			return $token;
		}

		if( isset( $_SERVER[ 'HTTP_X_CSRF_TOKEN' ] ) )
		{
			return $_SERVER[ 'HTTP_X_CSRF_TOKEN' ];
		}

		return null;
	}
}
