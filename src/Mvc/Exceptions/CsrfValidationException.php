<?php

namespace Neuron\Mvc\Exceptions;

use RuntimeException;

/**
 * Exception thrown when CSRF token validation fails.
 *
 * Carries HTTP status 403. Applications/middleware should catch this and
 * return a Forbidden response.
 *
 * @package Neuron\Mvc\Exceptions
 */
class CsrfValidationException extends RuntimeException
{
	private string $_userMessage;

	/**
	 * @param string $message Technical message for logging
	 * @param string $userMessage User-friendly message to display
	 */
	public function __construct( string $message, string $userMessage = 'CSRF token validation failed' )
	{
		parent::__construct( $message, 403 );
		$this->_userMessage = $userMessage;
	}

	/**
	 * Get user-friendly message suitable for display.
	 */
	public function getUserMessage(): string
	{
		return $this->_userMessage;
	}
}
