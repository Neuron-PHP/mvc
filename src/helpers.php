<?php

/**
 * Framework-level view helpers for Neuron MVC.
 *
 * These are declared in the global namespace and guarded with function_exists()
 * so they coexist with the equivalent CMS helpers when both packages are loaded.
 */

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Patterns\Registry;

if( !function_exists( 'csrf_token' ) )
{
	/**
	 * Get the current CSRF token seeded into the registry by the framework.
	 *
	 * @return string
	 */
	function csrf_token(): string
	{
		$token = Registry::getInstance()->get( RegistryKeys::AUTH_CSRF_TOKEN );

		return is_string( $token ) ? $token : '';
	}
}

if( !function_exists( 'csrf_field' ) )
{
	/**
	 * Render a hidden CSRF token input for use in HTML forms.
	 *
	 * @return string
	 */
	function csrf_field(): string
	{
		$token = csrf_token();

		return '<input type="hidden" name="csrf_token" value="'
			. htmlspecialchars( $token, ENT_QUOTES )
			. '">';
	}
}
