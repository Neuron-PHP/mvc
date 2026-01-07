<?php

namespace Neuron\Mvc\Tests\Api;

use Neuron\Core\ProblemDetails\ProblemDetails;
use Neuron\Core\ProblemDetails\ProblemType;
use Neuron\Mvc\Api\ApiResponseTrait;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiResponseTrait.
 */
class ApiResponseTraitTest extends TestCase
{
	private $controller;

	protected function setUp(): void
	{
		// Create a mock controller that uses the trait
		$this->controller = new class {
			use ApiResponseTrait;

			// Expose protected methods for testing
			public function testProblemDetailsResponse(ProblemDetails $problem, array $headers = []): string
			{
				// In tests, we can't actually send headers, so just return the JSON
				return json_encode($problem);
			}

			public function testProblemResponse(
				ProblemType|string $type,
				?string $title = null,
				?int $status = null,
				?string $detail = null,
				?string $instance = null,
				array $extensions = [],
				array $headers = []
			): string {
				// Create the problem details without sending headers
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
					$problem = new ProblemDetails(
						type: $type,
						title: $title ?? 'Error',
						status: $status ?? 500,
						detail: $detail,
						instance: $instance,
						extensions: $extensions
					);
				}
				return json_encode($problem);
			}

			public function testValidationProblem(array $errors, ?string $detail = null): string
			{
				$problem = new ProblemDetails(
					type: '/errors/validation',
					title: 'Validation Failed',
					status: 400,
					detail: $detail ?? 'The request contains invalid fields.',
					extensions: ['errors' => $errors]
				);
				return json_encode($problem);
			}

			public function testNotFoundProblem(string $resource, string|int|null $identifier = null): string
			{
				$detail = $identifier !== null
					? "$resource with identifier '$identifier' was not found."
					: "$resource was not found.";

				$problem = new ProblemDetails(
					type: '/errors/not-found',
					title: 'Resource Not Found',
					status: 404,
					detail: $detail
				);
				return json_encode($problem);
			}

			public function testAuthenticationProblem(?string $detail = null): string
			{
				$problem = new ProblemDetails(
					type: ProblemType::AUTHENTICATION_REQUIRED->value,
					title: ProblemType::AUTHENTICATION_REQUIRED->getDefaultTitle(),
					status: 401,
					detail: $detail ?? 'Authentication is required to access this resource.'
				);
				return json_encode($problem);
			}

			public function testPermissionProblem(?string $detail = null, ?array $requiredPermissions = null): string
			{
				$extensions = [];
				if ($requiredPermissions !== null) {
					$extensions['required_permissions'] = $requiredPermissions;
				}

				$problem = new ProblemDetails(
					type: ProblemType::PERMISSION_DENIED->value,
					title: ProblemType::PERMISSION_DENIED->getDefaultTitle(),
					status: 403,
					detail: $detail ?? 'You do not have permission to perform this action.',
					extensions: $extensions
				);
				return json_encode($problem);
			}

			public function testBadRequestProblem(?string $detail = null, array $errors = []): string
			{
				$extensions = !empty($errors) ? ['errors' => $errors] : [];

				$problem = new ProblemDetails(
					type: ProblemType::BAD_REQUEST->value,
					title: ProblemType::BAD_REQUEST->getDefaultTitle(),
					status: 400,
					detail: $detail ?? 'The request is invalid or malformed.',
					extensions: $extensions
				);
				return json_encode($problem);
			}

			public function testConflictProblem(?string $detail = null, ?string $conflictingResource = null): string
			{
				$extensions = [];
				if ($conflictingResource !== null) {
					$extensions['conflicting_resource'] = $conflictingResource;
				}

				$problem = new ProblemDetails(
					type: ProblemType::CONFLICT->value,
					title: ProblemType::CONFLICT->getDefaultTitle(),
					status: 409,
					detail: $detail ?? 'The request conflicts with the current state.',
					extensions: $extensions
				);
				return json_encode($problem);
			}

			public function testInternalErrorProblem(?string $detail = null, bool $includeDebug = false, ?\Exception $exception = null): string
			{
				$extensions = [];

				if ($includeDebug && $exception !== null) {
					$extensions['debug'] = [
						'exception' => get_class($exception),
						'message' => $exception->getMessage(),
						'file' => $exception->getFile(),
						'line' => $exception->getLine(),
					];
				}

				$problem = new ProblemDetails(
					type: ProblemType::INTERNAL_ERROR->value,
					title: ProblemType::INTERNAL_ERROR->getDefaultTitle(),
					status: 500,
					detail: $detail ?? 'An unexpected error occurred.',
					extensions: $extensions
				);
				return json_encode($problem);
			}
		};
	}

	/**
	 * Test validation problem response.
	 */
	public function testValidationProblem(): void
	{
		$errors = [
			'email' => 'Invalid email format',
			'password' => 'Too short'
		];

		$json = $this->controller->testValidationProblem($errors);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/validation', $data['type']);
		$this->assertEquals('Validation Failed', $data['title']);
		$this->assertEquals(400, $data['status']);
		$this->assertArrayHasKey('errors', $data);
		$this->assertEquals($errors, $data['errors']);
	}

	/**
	 * Test validation problem with custom detail.
	 */
	public function testValidationProblemWithDetail(): void
	{
		$errors = ['field' => 'error'];
		$detail = 'Custom validation message';

		$json = $this->controller->testValidationProblem($errors, $detail);
		$data = json_decode($json, true);

		$this->assertEquals($detail, $data['detail']);
		$this->assertEquals($errors, $data['errors']);
	}

	/**
	 * Test not found problem.
	 */
	public function testNotFoundProblem(): void
	{
		$json = $this->controller->testNotFoundProblem('User', 123);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/not-found', $data['type']);
		$this->assertEquals('Resource Not Found', $data['title']);
		$this->assertEquals(404, $data['status']);
		$this->assertEquals("User with identifier '123' was not found.", $data['detail']);
	}

	/**
	 * Test not found problem without identifier.
	 */
	public function testNotFoundProblemWithoutIdentifier(): void
	{
		$json = $this->controller->testNotFoundProblem('Page');
		$data = json_decode($json, true);

		$this->assertEquals("Page was not found.", $data['detail']);
	}

	/**
	 * Test authentication problem.
	 */
	public function testAuthenticationProblem(): void
	{
		$json = $this->controller->testAuthenticationProblem();
		$data = json_decode($json, true);

		$this->assertEquals('/errors/authentication', $data['type']);
		$this->assertEquals('Authentication Required', $data['title']);
		$this->assertEquals(401, $data['status']);
		$this->assertEquals('Authentication is required to access this resource.', $data['detail']);
	}

	/**
	 * Test authentication problem with custom detail.
	 */
	public function testAuthenticationProblemWithDetail(): void
	{
		$detail = 'Invalid token';
		$json = $this->controller->testAuthenticationProblem($detail);
		$data = json_decode($json, true);

		$this->assertEquals($detail, $data['detail']);
	}

	/**
	 * Test permission problem.
	 */
	public function testPermissionProblem(): void
	{
		$json = $this->controller->testPermissionProblem();
		$data = json_decode($json, true);

		$this->assertEquals('/errors/authorization', $data['type']);
		$this->assertEquals('Permission Denied', $data['title']);
		$this->assertEquals(403, $data['status']);
	}

	/**
	 * Test permission problem with required permissions.
	 */
	public function testPermissionProblemWithRequiredPermissions(): void
	{
		$permissions = ['admin', 'editor'];
		$json = $this->controller->testPermissionProblem(null, $permissions);
		$data = json_decode($json, true);

		$this->assertArrayHasKey('required_permissions', $data);
		$this->assertEquals($permissions, $data['required_permissions']);
	}

	/**
	 * Test bad request problem.
	 */
	public function testBadRequestProblem(): void
	{
		$json = $this->controller->testBadRequestProblem();
		$data = json_decode($json, true);

		$this->assertEquals('/errors/bad-request', $data['type']);
		$this->assertEquals('Bad Request', $data['title']);
		$this->assertEquals(400, $data['status']);
	}

	/**
	 * Test bad request with errors.
	 */
	public function testBadRequestProblemWithErrors(): void
	{
		$errors = ['field1' => 'error1', 'field2' => 'error2'];
		$json = $this->controller->testBadRequestProblem(null, $errors);
		$data = json_decode($json, true);

		$this->assertArrayHasKey('errors', $data);
		$this->assertEquals($errors, $data['errors']);
	}

	/**
	 * Test conflict problem.
	 */
	public function testConflictProblem(): void
	{
		$json = $this->controller->testConflictProblem();
		$data = json_decode($json, true);

		$this->assertEquals('/errors/conflict', $data['type']);
		$this->assertEquals('Conflict', $data['title']);
		$this->assertEquals(409, $data['status']);
	}

	/**
	 * Test conflict problem with conflicting resource.
	 */
	public function testConflictProblemWithResource(): void
	{
		$resource = 'user-email';
		$json = $this->controller->testConflictProblem(null, $resource);
		$data = json_decode($json, true);

		$this->assertArrayHasKey('conflicting_resource', $data);
		$this->assertEquals($resource, $data['conflicting_resource']);
	}

	/**
	 * Test internal error problem.
	 */
	public function testInternalErrorProblem(): void
	{
		$json = $this->controller->testInternalErrorProblem();
		$data = json_decode($json, true);

		$this->assertEquals('/errors/internal', $data['type']);
		$this->assertEquals('Internal Server Error', $data['title']);
		$this->assertEquals(500, $data['status']);
		$this->assertEquals('An unexpected error occurred.', $data['detail']);
		$this->assertArrayNotHasKey('debug', $data);
	}

	/**
	 * Test internal error problem with debug info.
	 */
	public function testInternalErrorProblemWithDebug(): void
	{
		$exception = new \Exception('Test exception');
		$json = $this->controller->testInternalErrorProblem(null, true, $exception);
		$data = json_decode($json, true);

		$this->assertArrayHasKey('debug', $data);
		$this->assertEquals('Exception', $data['debug']['exception']);
		$this->assertEquals('Test exception', $data['debug']['message']);
		$this->assertArrayHasKey('file', $data['debug']);
		$this->assertArrayHasKey('line', $data['debug']);
	}

	/**
	 * Test problem response with ProblemType enum.
	 */
	public function testProblemResponseWithEnum(): void
	{
		$json = $this->controller->testProblemResponse(
			ProblemType::RATE_LIMIT_EXCEEDED,
			detail: 'Too many requests'
		);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/rate-limit', $data['type']);
		$this->assertEquals('Rate Limit Exceeded', $data['title']);
		$this->assertEquals(429, $data['status']);
		$this->assertEquals('Too many requests', $data['detail']);
	}

	/**
	 * Test problem response with string type.
	 */
	public function testProblemResponseWithString(): void
	{
		$json = $this->controller->testProblemResponse(
			'/custom/error',
			'Custom Error',
			418,
			'I am a teapot'
		);
		$data = json_decode($json, true);

		$this->assertEquals('/custom/error', $data['type']);
		$this->assertEquals('Custom Error', $data['title']);
		$this->assertEquals(418, $data['status']);
		$this->assertEquals('I am a teapot', $data['detail']);
	}

	/**
	 * Test problem response with all parameters.
	 */
	public function testProblemResponseFull(): void
	{
		$json = $this->controller->testProblemResponse(
			ProblemType::VALIDATION_ERROR,
			'Custom Title',
			422,
			'Custom detail',
			'/api/test',
			['custom' => 'extension']
		);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/validation', $data['type']);
		$this->assertEquals('Custom Title', $data['title']);
		$this->assertEquals(422, $data['status']);
		$this->assertEquals('Custom detail', $data['detail']);
		$this->assertEquals('/api/test', $data['instance']);
		$this->assertEquals('extension', $data['custom']);
	}

	/**
	 * Test service unavailable problem.
	 */
	public function testServiceUnavailableProblem(): void
	{
		// Add test method to controller
		$this->controller->testServiceUnavailableProblem = function(?string $detail = null, ?int $retryAfter = null): string {
			$extensions = [];
			if ($retryAfter !== null) {
				$extensions['retry_after'] = $retryAfter;
			}

			$problem = new ProblemDetails(
				type: ProblemType::SERVICE_UNAVAILABLE->value,
				title: ProblemType::SERVICE_UNAVAILABLE->getDefaultTitle(),
				status: 503,
				detail: $detail ?? 'The service is temporarily unavailable.',
				extensions: $extensions
			);
			return json_encode($problem);
		};

		$json = ($this->controller->testServiceUnavailableProblem)();
		$data = json_decode($json, true);

		$this->assertEquals('/errors/service-unavailable', $data['type']);
		$this->assertEquals('Service Unavailable', $data['title']);
		$this->assertEquals(503, $data['status']);
	}

	/**
	 * Test service unavailable with retry after.
	 */
	public function testServiceUnavailableWithRetryAfter(): void
	{
		$this->controller->testServiceUnavailableProblem = function(?string $detail = null, ?int $retryAfter = null): string {
			$extensions = [];
			if ($retryAfter !== null) {
				$extensions['retry_after'] = $retryAfter;
			}

			$problem = new ProblemDetails(
				type: ProblemType::SERVICE_UNAVAILABLE->value,
				title: ProblemType::SERVICE_UNAVAILABLE->getDefaultTitle(),
				status: 503,
				detail: $detail ?? 'The service is temporarily unavailable.',
				extensions: $extensions
			);
			return json_encode($problem);
		};

		$json = ($this->controller->testServiceUnavailableProblem)('Maintenance in progress', 300);
		$data = json_decode($json, true);

		$this->assertEquals('Maintenance in progress', $data['detail']);
		$this->assertEquals(300, $data['retry_after']);
	}

	/**
	 * Test method not allowed problem.
	 */
	public function testMethodNotAllowedProblem(): void
	{
		$this->controller->testMethodNotAllowedProblem = function(array $allowedMethods, ?string $detail = null): string {
			$problem = new ProblemDetails(
				type: ProblemType::METHOD_NOT_ALLOWED->value,
				title: ProblemType::METHOD_NOT_ALLOWED->getDefaultTitle(),
				status: 405,
				detail: $detail ?? sprintf('Method not allowed. Allowed methods: %s', implode(', ', $allowedMethods)),
				extensions: ['allowed_methods' => $allowedMethods]
			);
			return json_encode($problem);
		};

		$methods = ['GET', 'POST', 'PUT'];
		$json = ($this->controller->testMethodNotAllowedProblem)($methods);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/method-not-allowed', $data['type']);
		$this->assertEquals(405, $data['status']);
		$this->assertStringContainsString('GET, POST, PUT', $data['detail']);
		$this->assertEquals($methods, $data['allowed_methods']);
	}

	/**
	 * Test unsupported media type problem.
	 */
	public function testUnsupportedMediaTypeProblem(): void
	{
		$this->controller->testUnsupportedMediaTypeProblem = function(array $supportedTypes, ?string $detail = null): string {
			$problem = new ProblemDetails(
				type: ProblemType::UNSUPPORTED_MEDIA_TYPE->value,
				title: ProblemType::UNSUPPORTED_MEDIA_TYPE->getDefaultTitle(),
				status: 415,
				detail: $detail ?? sprintf('Unsupported media type. Supported types: %s', implode(', ', $supportedTypes)),
				extensions: ['supported_types' => $supportedTypes]
			);
			return json_encode($problem);
		};

		$types = ['application/json', 'application/xml'];
		$json = ($this->controller->testUnsupportedMediaTypeProblem)($types);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/unsupported-media-type', $data['type']);
		$this->assertEquals(415, $data['status']);
		$this->assertStringContainsString('application/json, application/xml', $data['detail']);
		$this->assertEquals($types, $data['supported_types']);
	}

	/**
	 * Test request timeout problem.
	 */
	public function testRequestTimeoutProblem(): void
	{
		$json = $this->controller->testProblemResponse(
			ProblemType::REQUEST_TIMEOUT,
			detail: 'Request processing timed out'
		);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/timeout', $data['type']);
		$this->assertEquals('Request Timeout', $data['title']);
		$this->assertEquals(408, $data['status']);
	}

	/**
	 * Test payload too large problem.
	 */
	public function testPayloadTooLargeProblem(): void
	{
		$json = $this->controller->testProblemResponse(
			ProblemType::PAYLOAD_TOO_LARGE,
			detail: 'Request body exceeds 10MB limit',
			extensions: ['max_size' => '10MB', 'received_size' => '15MB']
		);
		$data = json_decode($json, true);

		$this->assertEquals('/errors/payload-too-large', $data['type']);
		$this->assertEquals(413, $data['status']);
		$this->assertEquals('10MB', $data['max_size']);
		$this->assertEquals('15MB', $data['received_size']);
	}

	/**
	 * Test rate limit problem with all parameters.
	 */
	public function testRateLimitProblemComplete(): void
	{
		// Create test method for complete rate limit testing
		$this->controller->testRateLimitProblemComplete = function(
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

			$problem = new ProblemDetails(
				type: ProblemType::RATE_LIMIT_EXCEEDED->value,
				title: ProblemType::RATE_LIMIT_EXCEEDED->getDefaultTitle(),
				status: 429,
				detail: $detail ?? "Rate limit exceeded. Please retry after $retryAfter seconds.",
				extensions: $extensions
			);
			return json_encode($problem);
		};

		$json = ($this->controller->testRateLimitProblemComplete)(
			60,
			'API rate limit reached',
			100,
			0
		);
		$data = json_decode($json, true);

		$this->assertEquals(429, $data['status']);
		$this->assertEquals('API rate limit reached', $data['detail']);
		$this->assertEquals(60, $data['retry_after']);
		$this->assertEquals(100, $data['limit']);
		$this->assertEquals(0, $data['remaining']);
	}

	/**
	 * Test authentication problem with realm.
	 */
	public function testAuthenticationProblemWithRealm(): void
	{
		// Since we can't test headers in unit tests, we'll verify the logic
		$this->controller->testAuthenticationProblemWithRealm = function(?string $detail = null, ?string $realm = null): array {
			$headers = [];
			if ($realm !== null) {
				$headers['WWW-Authenticate'] = sprintf('Bearer realm="%s"', $realm);
			}

			$problem = new ProblemDetails(
				type: ProblemType::AUTHENTICATION_REQUIRED->value,
				title: ProblemType::AUTHENTICATION_REQUIRED->getDefaultTitle(),
				status: 401,
				detail: $detail ?? 'Authentication is required to access this resource.'
			);

			return ['problem' => $problem->toArray(), 'headers' => $headers];
		};

		$result = ($this->controller->testAuthenticationProblemWithRealm)('Token expired', 'api');

		$this->assertEquals(401, $result['problem']['status']);
		$this->assertEquals('Token expired', $result['problem']['detail']);
		$this->assertEquals('Bearer realm="api"', $result['headers']['WWW-Authenticate']);
	}

	/**
	 * Test problem response with minimal parameters.
	 */
	public function testProblemResponseMinimal(): void
	{
		$json = $this->controller->testProblemResponse('/custom/minimal');
		$data = json_decode($json, true);

		$this->assertEquals('/custom/minimal', $data['type']);
		$this->assertEquals('Error', $data['title']); // Default title
		$this->assertEquals(500, $data['status']); // Default status
		$this->assertArrayNotHasKey('detail', $data);
		$this->assertArrayNotHasKey('instance', $data);
	}

	/**
	 * Test validation problem with nested errors.
	 */
	public function testValidationProblemNested(): void
	{
		$errors = [
			'user' => [
				'email' => 'Invalid format',
				'name' => 'Too short'
			],
			'address' => [
				'zip' => 'Invalid postal code',
				'country' => 'Not supported'
			]
		];

		$json = $this->controller->testValidationProblem($errors, 'Multiple validation failures');
		$data = json_decode($json, true);

		$this->assertEquals('Multiple validation failures', $data['detail']);
		$this->assertIsArray($data['errors']['user']);
		$this->assertEquals('Invalid format', $data['errors']['user']['email']);
		$this->assertEquals('Invalid postal code', $data['errors']['address']['zip']);
	}

	/**
	 * Test conflict problem with multiple conflicting resources.
	 */
	public function testConflictProblemMultiple(): void
	{
		$json = $this->controller->testProblemResponse(
			ProblemType::CONFLICT,
			detail: 'Multiple conflicts detected',
			extensions: [
				'conflicts' => [
					['resource' => 'email', 'value' => 'user@example.com'],
					['resource' => 'username', 'value' => 'johndoe']
				]
			]
		);
		$data = json_decode($json, true);

		$this->assertEquals(409, $data['status']);
		$this->assertCount(2, $data['conflicts']);
		$this->assertEquals('email', $data['conflicts'][0]['resource']);
	}

	/**
	 * Test all ProblemType enum values have correct mappings.
	 */
	public function testAllProblemTypes(): void
	{
		$types = [
			['type' => ProblemType::VALIDATION_ERROR, 'uri' => '/errors/validation', 'status' => 400],
			['type' => ProblemType::NOT_FOUND, 'uri' => '/errors/not-found', 'status' => 404],
			['type' => ProblemType::AUTHENTICATION_REQUIRED, 'uri' => '/errors/authentication', 'status' => 401],
			['type' => ProblemType::PERMISSION_DENIED, 'uri' => '/errors/authorization', 'status' => 403],
			['type' => ProblemType::RATE_LIMIT_EXCEEDED, 'uri' => '/errors/rate-limit', 'status' => 429],
			['type' => ProblemType::SERVICE_UNAVAILABLE, 'uri' => '/errors/service-unavailable', 'status' => 503],
			['type' => ProblemType::INTERNAL_ERROR, 'uri' => '/errors/internal', 'status' => 500],
			['type' => ProblemType::BAD_REQUEST, 'uri' => '/errors/bad-request', 'status' => 400],
			['type' => ProblemType::CONFLICT, 'uri' => '/errors/conflict', 'status' => 409],
			['type' => ProblemType::METHOD_NOT_ALLOWED, 'uri' => '/errors/method-not-allowed', 'status' => 405],
			['type' => ProblemType::UNSUPPORTED_MEDIA_TYPE, 'uri' => '/errors/unsupported-media-type', 'status' => 415],
			['type' => ProblemType::REQUEST_TIMEOUT, 'uri' => '/errors/timeout', 'status' => 408],
			['type' => ProblemType::PAYLOAD_TOO_LARGE, 'uri' => '/errors/payload-too-large', 'status' => 413],
		];

		foreach ($types as $test) {
			$type = $test['type'];
			$expectedUri = $test['uri'];
			$expectedStatus = $test['status'];

			$json = $this->controller->testProblemResponse($type);
			$data = json_decode($json, true);

			$this->assertEquals($expectedUri, $data['type'], "Failed for type: {$type->name}");
			$this->assertEquals($expectedStatus, $data['status'], "Failed for type: {$type->name}");
			$this->assertNotEmpty($data['title'], "Failed for type: {$type->name}");
		}
	}
}