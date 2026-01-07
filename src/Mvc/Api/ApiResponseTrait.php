<?php

namespace Neuron\Mvc\Api;

use Neuron\Core\ProblemDetails\ProblemDetails;
use Neuron\Core\ProblemDetails\ProblemType;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Exception;

/**
 * Trait providing RFC 9457 Problem Details response methods for API controllers.
 *
 * This trait adds convenient methods to API controllers for returning
 * standardized error responses following RFC 9457. It provides both generic
 * and specific error response methods, making it easy to maintain consistency
 * across all API endpoints.
 *
 * The trait integrates with the existing Neuron MVC controller structure,
 * allowing controllers to return both success responses (using existing methods)
 * and error responses (using Problem Details format).
 *
 * @package Neuron\Mvc\Api
 *
 * @example
 * ```php
 * class UserApiController extends Base {
 *     use ApiResponseTrait;
 *
 *     public function create(Request $request): string {
 *         $errors = $this->validator->validate($request->getData());
 *
 *         if ($errors) {
 *             // Use the trait method for validation errors
 *             return $this->validationProblem($errors);
 *         }
 *
 *         try {
 *             $user = $this->userService->create($data);
 *             return $this->renderJson(HttpResponseStatus::CREATED, ['data' => $user]);
 *         } catch (Exception $e) {
 *             // Use generic problem response
 *             return $this->problemResponse(
 *                 ProblemType::INTERNAL_ERROR,
 *                 detail: 'An error occurred while creating the user'
 *             );
 *         }
 *     }
 * }
 * ```
 */
trait ApiResponseTrait
{
	/**
	 * Create a problem response from a ProblemDetails instance.
	 *
	 * @param ProblemDetails $problem The problem details
	 * @param array $headers Additional HTTP headers
	 * @return string JSON response
	 */
	protected function problemDetailsResponse(ProblemDetails $problem, array $headers = []): string
	{
		return ProblemDetailsResponse::create($problem, $headers)->send();
	}

	/**
	 * Create a problem response using individual parameters.
	 *
	 * @param ProblemType|string $type Problem type enum or URI string
	 * @param string|null $title Problem title (uses default if not provided)
	 * @param int|null $status HTTP status code (uses type default if not provided)
	 * @param string|null $detail Specific error details
	 * @param string|null $instance URI for this specific occurrence
	 * @param array $extensions Additional fields
	 * @param array $headers Additional HTTP headers
	 * @return string JSON response
	 */
	protected function problemResponse(
		ProblemType|string $type,
		?string $title = null,
		?int $status = null,
		?string $detail = null,
		?string $instance = null,
		array $extensions = [],
		array $headers = []
	): string {
		// Handle ProblemType enum
		if ($type instanceof ProblemType) {
			$problem = new ProblemDetails(
				type: $type->value,
				title: $title ?? $type->getDefaultTitle(),
				status: $status ?? $type->getRecommendedStatus(),
				detail: $detail,
				instance: $instance,
				extensions: $extensions
			);
		} else {
			// Handle string type URI
			$problem = new ProblemDetails(
				type: $type,
				title: $title ?? 'Error',
				status: $status ?? 500,
				detail: $detail,
				instance: $instance,
				extensions: $extensions
			);
		}

		return $this->problemDetailsResponse($problem, $headers);
	}

	/**
	 * Create a validation error response.
	 *
	 * @param array $errors Validation errors (field => message)
	 * @param string|null $detail Overall validation error message
	 * @return string JSON response
	 */
	protected function validationProblem(array $errors, ?string $detail = null): string
	{
		return ProblemDetailsResponse::validationError($errors, $detail)->send();
	}

	/**
	 * Create a not found error response.
	 *
	 * @param string $resource Resource type (e.g., 'User', 'Order')
	 * @param string|int|null $identifier Resource identifier
	 * @return string JSON response
	 */
	protected function notFoundProblem(string $resource, string|int|null $identifier = null): string
	{
		return ProblemDetailsResponse::notFound($resource, $identifier)->send();
	}

	/**
	 * Create an authentication required error response.
	 *
	 * @param string|null $detail Specific authentication failure details
	 * @param string|null $realm Authentication realm (for WWW-Authenticate header)
	 * @return string JSON response
	 */
	protected function authenticationProblem(?string $detail = null, ?string $realm = null): string
	{
		$headers = [];
		if ($realm !== null) {
			$headers['WWW-Authenticate'] = sprintf('Bearer realm="%s"', $realm);
		}

		return $this->problemResponse(
			ProblemType::AUTHENTICATION_REQUIRED,
			detail: $detail ?? 'Authentication is required to access this resource.',
			headers: $headers
		);
	}

	/**
	 * Create a permission denied error response.
	 *
	 * @param string|null $detail Specific permission failure details
	 * @param array|null $requiredPermissions List of required permissions
	 * @return string JSON response
	 */
	protected function permissionProblem(?string $detail = null, ?array $requiredPermissions = null): string
	{
		$extensions = [];
		if ($requiredPermissions !== null) {
			$extensions['required_permissions'] = $requiredPermissions;
		}

		return $this->problemResponse(
			ProblemType::PERMISSION_DENIED,
			detail: $detail ?? 'You do not have permission to perform this action.',
			extensions: $extensions
		);
	}

	/**
	 * Create a rate limit exceeded error response.
	 *
	 * @param int $retryAfter Seconds until the client can retry
	 * @param string|null $detail Specific rate limit details
	 * @param int|null $limit The rate limit that was exceeded
	 * @param int|null $remaining Remaining requests in the current window
	 * @return string JSON response
	 */
	protected function rateLimitProblem(
		int $retryAfter,
		?string $detail = null,
		?int $limit = null,
		?int $remaining = null
	): string {
		$extensions = ['retry_after' => $retryAfter];

		if ($limit !== null) {
			$extensions['limit'] = $limit;
		}
		if ($remaining !== null) {
			$extensions['remaining'] = $remaining;
		}

		return ProblemDetailsResponse::rateLimitExceeded($retryAfter, $detail)->send();
	}

	/**
	 * Create a bad request error response.
	 *
	 * @param string|null $detail Specific error details
	 * @param array $errors Optional field-specific errors
	 * @return string JSON response
	 */
	protected function badRequestProblem(?string $detail = null, array $errors = []): string
	{
		$extensions = !empty($errors) ? ['errors' => $errors] : [];

		return $this->problemResponse(
			ProblemType::BAD_REQUEST,
			detail: $detail ?? 'The request is invalid or malformed.',
			extensions: $extensions
		);
	}

	/**
	 * Create a conflict error response.
	 *
	 * @param string|null $detail Specific conflict details
	 * @param string|null $conflictingResource The resource causing the conflict
	 * @return string JSON response
	 */
	protected function conflictProblem(?string $detail = null, ?string $conflictingResource = null): string
	{
		$extensions = [];
		if ($conflictingResource !== null) {
			$extensions['conflicting_resource'] = $conflictingResource;
		}

		return $this->problemResponse(
			ProblemType::CONFLICT,
			detail: $detail ?? 'The request conflicts with the current state.',
			extensions: $extensions
		);
	}

	/**
	 * Create a service unavailable error response.
	 *
	 * @param string|null $detail Specific unavailability details
	 * @param int|null $retryAfter Optional seconds until service is available
	 * @return string JSON response
	 */
	protected function serviceUnavailableProblem(?string $detail = null, ?int $retryAfter = null): string
	{
		$headers = [];
		$extensions = [];

		if ($retryAfter !== null) {
			$headers['Retry-After'] = (string) $retryAfter;
			$extensions['retry_after'] = $retryAfter;
		}

		return $this->problemResponse(
			ProblemType::SERVICE_UNAVAILABLE,
			detail: $detail ?? 'The service is temporarily unavailable.',
			extensions: $extensions,
			headers: $headers
		);
	}

	/**
	 * Create an internal error response.
	 *
	 * This should be used for unexpected errors. In production, avoid
	 * exposing internal details that could be security sensitive.
	 *
	 * @param string|null $detail Error details (be careful in production)
	 * @param bool $includeDebug Include debug information (only in development)
	 * @param Exception|null $exception The exception that occurred
	 * @return string JSON response
	 */
	protected function internalErrorProblem(
		?string $detail = null,
		bool $includeDebug = false,
		?Exception $exception = null
	): string {
		$extensions = [];

		// Only include debug info in development environments
		if ($includeDebug && $exception !== null) {
			$extensions['debug'] = [
				'exception' => get_class($exception),
				'message' => $exception->getMessage(),
				'file' => $exception->getFile(),
				'line' => $exception->getLine(),
			];
		}

		return $this->problemResponse(
			ProblemType::INTERNAL_ERROR,
			detail: $detail ?? 'An unexpected error occurred.',
			extensions: $extensions
		);
	}

	/**
	 * Create a method not allowed error response.
	 *
	 * @param array $allowedMethods List of allowed HTTP methods
	 * @param string|null $detail Specific error details
	 * @return string JSON response
	 */
	protected function methodNotAllowedProblem(array $allowedMethods, ?string $detail = null): string
	{
		$headers = [
			'Allow' => implode(', ', $allowedMethods)
		];

		return $this->problemResponse(
			ProblemType::METHOD_NOT_ALLOWED,
			detail: $detail ?? sprintf('Method not allowed. Allowed methods: %s', implode(', ', $allowedMethods)),
			extensions: ['allowed_methods' => $allowedMethods],
			headers: $headers
		);
	}

	/**
	 * Create an unsupported media type error response.
	 *
	 * @param array $supportedTypes List of supported content types
	 * @param string|null $detail Specific error details
	 * @return string JSON response
	 */
	protected function unsupportedMediaTypeProblem(array $supportedTypes, ?string $detail = null): string
	{
		return $this->problemResponse(
			ProblemType::UNSUPPORTED_MEDIA_TYPE,
			detail: $detail ?? sprintf('Unsupported media type. Supported types: %s', implode(', ', $supportedTypes)),
			extensions: ['supported_types' => $supportedTypes]
		);
	}
}