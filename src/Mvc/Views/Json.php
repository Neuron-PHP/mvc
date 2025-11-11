<?php

namespace Neuron\Mvc\Views;

/**
 * JSON view implementation for API responses and AJAX endpoints.
 * 
 * This view class renders data arrays as JSON-formatted strings, making it
 * ideal for REST APIs, AJAX endpoints, and any application that needs to
 * provide machine-readable JSON output. It implements the IView interface
 * to ensure consistent integration with the MVC framework's rendering system.
 * 
 * Key features:
 * - Clean JSON encoding with proper UTF-8 handling
 * - Automatic error handling for non-serializable data
 * - Integration with MVC controller response system
 * - Support for nested arrays and objects
 * - Consistent output formatting for API consumers
 * 
 * Common use cases:
 * - REST API endpoints returning data
 * - AJAX responses for dynamic web applications
 * - Mobile application API responses
 * - Third-party integrations requiring JSON format
 * - Configuration data export
 * 
 * @package Neuron\Mvc\Views
 * 
 * @example
 * ```php
 * // Basic usage in controller
 * $jsonView = new Json();
 * $response = $jsonView->render([
 *     'status' => 'success',
 *     'data' => [
 *         'users' => $userList,
 *         'count' => count($userList)
 *     ],
 *     'timestamp' => time()
 * ]);
 * // Output: {"status":"success","data":{"users":[...],"count":5},"timestamp":1234567890}
 * 
 * // API endpoint usage
 * public function apiGetUsers(): string
 * {
 *     $users = $this->userService->getAllUsers();
 *     return $this->render(['users' => $users], new Json());
 * }
 * ```
 */
class Json implements IView
{
	public function render( array $data ): string
	{
		return json_encode( $data );
	}
}
