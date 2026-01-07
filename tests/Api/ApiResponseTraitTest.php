<?php

namespace Neuron\Mvc\Tests\Api;

use Neuron\Core\ProblemDetails\ProblemDetails;
use Neuron\Core\ProblemDetails\ProblemType;
use Neuron\Mvc\Api\ApiResponseTrait;
use Neuron\Mvc\Api\ProblemDetailsResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiResponseTrait.
 *
 * This test properly tests the trait by calling the actual trait methods
 * rather than reimplementing them. We override problemDetailsResponse()
 * to capture responses without sending headers.
 */
class ApiResponseTraitTest extends TestCase
{
	private $controller;
	private array $capturedResponses = [];

	protected function setUp(): void
	{
		// Clear captured responses
		$this->capturedResponses = [];

		// Create a mock controller that uses the trait
		$controller = new class {
			use ApiResponseTrait {
				// Make protected methods public for testing
				validationProblem as public;
				notFoundProblem as public;
				authenticationProblem as public;
				permissionProblem as public;
				rateLimitProblem as public;
				badRequestProblem as public;
				conflictProblem as public;
				serviceUnavailableProblem as public;
				internalErrorProblem as public;
				methodNotAllowedProblem as public;
				unsupportedMediaTypeProblem as public;
				problemResponse as public;
				problemDetailsResponse as public testProblemDetailsResponse;
			}

			// Storage for test assertions
			public array $lastProblemDetails = [];
			public array $lastHeaders = [];

			/**
			 * Override to capture responses without sending headers.
			 * This allows us to test the actual trait methods.
			 */
			protected function problemDetailsResponse(ProblemDetails $problem, array $headers = []): string
			{
				// Store for assertions
				$this->lastProblemDetails = $problem->toArray();
				$this->lastHeaders = $headers;

				// Return JSON without sending headers
				$response = new ProblemDetailsResponse($problem, $headers);
				return $response->toJson();
			}
		};

		$this->controller = $controller;
	}

	/**
	 * Helper to decode and assert JSON response.
	 */
	private function assertJsonResponse(string $json): array
	{
		$data = json_decode($json, true);
		$this->assertNotNull($data, 'Response should be valid JSON');
		return $data;
	}

	/**
	 * Test validation problem response.
	 * This tests the actual trait method, not a reimplementation.
	 */
	public function testValidationProblem(): void
	{
		$errors = [
			'email' => 'Invalid email format',
			'password' => 'Too short'
		];

		// Call the ACTUAL trait method
		$json = $this->controller->validationProblem($errors);
		$data = $this->assertJsonResponse($json);

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

		$json = $this->controller->validationProblem($errors, $detail);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals($detail, $data['detail']);
		$this->assertEquals($errors, $data['errors']);
	}

	/**
	 * Test not found problem.
	 */
	public function testNotFoundProblem(): void
	{
		$json = $this->controller->notFoundProblem('User', 123);
		$data = $this->assertJsonResponse($json);

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
		$json = $this->controller->notFoundProblem('Page');
		$data = $this->assertJsonResponse($json);

		$this->assertEquals("Page was not found.", $data['detail']);
	}

	/**
	 * Test authentication problem.
	 */
	public function testAuthenticationProblem(): void
	{
		$json = $this->controller->authenticationProblem();
		$data = $this->assertJsonResponse($json);

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
		$json = $this->controller->authenticationProblem($detail);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals($detail, $data['detail']);
	}

	/**
	 * Test authentication problem with realm.
	 */
	public function testAuthenticationProblemWithRealm(): void
	{
		$json = $this->controller->authenticationProblem('Token expired', 'api');
		$data = $this->assertJsonResponse($json);

		// Check that the WWW-Authenticate header was set
		$this->assertArrayHasKey('WWW-Authenticate', $this->controller->lastHeaders);
		$this->assertEquals('Bearer realm="api"', $this->controller->lastHeaders['WWW-Authenticate']);
		$this->assertEquals('Token expired', $data['detail']);
	}

	/**
	 * Test permission problem.
	 */
	public function testPermissionProblem(): void
	{
		$json = $this->controller->permissionProblem();
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/authorization', $data['type']);
		$this->assertEquals('Permission Denied', $data['title']);
		$this->assertEquals(403, $data['status']);
		$this->assertEquals('You do not have permission to perform this action.', $data['detail']);
	}

	/**
	 * Test permission problem with required permissions.
	 */
	public function testPermissionProblemWithRequiredPermissions(): void
	{
		$permissions = ['admin', 'editor'];
		$json = $this->controller->permissionProblem(null, $permissions);
		$data = $this->assertJsonResponse($json);

		$this->assertArrayHasKey('required_permissions', $data);
		$this->assertEquals($permissions, $data['required_permissions']);
	}

	/**
	 * Test rate limit problem.
	 */
	public function testRateLimitProblem(): void
	{
		$json = $this->controller->rateLimitProblem(60);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/rate-limit', $data['type']);
		$this->assertEquals('Rate Limit Exceeded', $data['title']);
		$this->assertEquals(429, $data['status']);
		$this->assertEquals(60, $data['retry_after']);

		// Check Retry-After header
		$this->assertArrayHasKey('Retry-After', $this->controller->lastHeaders);
		$this->assertEquals('60', $this->controller->lastHeaders['Retry-After']);
	}

	/**
	 * Test rate limit problem with all parameters.
	 */
	public function testRateLimitProblemComplete(): void
	{
		$json = $this->controller->rateLimitProblem(
			60,
			'API rate limit reached',
			100,
			0
		);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals(429, $data['status']);
		$this->assertEquals('API rate limit reached', $data['detail']);
		$this->assertEquals(60, $data['retry_after']);
		$this->assertEquals(100, $data['limit']);
		$this->assertEquals(0, $data['remaining']);
	}

	/**
	 * Test bad request problem.
	 */
	public function testBadRequestProblem(): void
	{
		$json = $this->controller->badRequestProblem();
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/bad-request', $data['type']);
		$this->assertEquals('Bad Request', $data['title']);
		$this->assertEquals(400, $data['status']);
		$this->assertEquals('The request is invalid or malformed.', $data['detail']);
	}

	/**
	 * Test bad request with errors.
	 */
	public function testBadRequestProblemWithErrors(): void
	{
		$errors = ['field1' => 'error1', 'field2' => 'error2'];
		$json = $this->controller->badRequestProblem(null, $errors);
		$data = $this->assertJsonResponse($json);

		$this->assertArrayHasKey('errors', $data);
		$this->assertEquals($errors, $data['errors']);
	}

	/**
	 * Test conflict problem.
	 */
	public function testConflictProblem(): void
	{
		$json = $this->controller->conflictProblem();
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/conflict', $data['type']);
		$this->assertEquals('Conflict', $data['title']);
		$this->assertEquals(409, $data['status']);
		$this->assertEquals('The request conflicts with the current state.', $data['detail']);
	}

	/**
	 * Test conflict problem with conflicting resource.
	 */
	public function testConflictProblemWithResource(): void
	{
		$resource = 'user-email';
		$json = $this->controller->conflictProblem(null, $resource);
		$data = $this->assertJsonResponse($json);

		$this->assertArrayHasKey('conflicting_resource', $data);
		$this->assertEquals($resource, $data['conflicting_resource']);
	}

	/**
	 * Test service unavailable problem.
	 */
	public function testServiceUnavailableProblem(): void
	{
		$json = $this->controller->serviceUnavailableProblem();
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/service-unavailable', $data['type']);
		$this->assertEquals('Service Unavailable', $data['title']);
		$this->assertEquals(503, $data['status']);
		$this->assertEquals('The service is temporarily unavailable.', $data['detail']);
	}

	/**
	 * Test service unavailable with retry after.
	 */
	public function testServiceUnavailableWithRetryAfter(): void
	{
		$json = $this->controller->serviceUnavailableProblem('Maintenance in progress', 300);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('Maintenance in progress', $data['detail']);
		$this->assertEquals(300, $data['retry_after']);

		// Check Retry-After header
		$this->assertArrayHasKey('Retry-After', $this->controller->lastHeaders);
		$this->assertEquals('300', $this->controller->lastHeaders['Retry-After']);
	}

	/**
	 * Test internal error problem.
	 */
	public function testInternalErrorProblem(): void
	{
		$json = $this->controller->internalErrorProblem();
		$data = $this->assertJsonResponse($json);

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
		$json = $this->controller->internalErrorProblem(null, true, $exception);
		$data = $this->assertJsonResponse($json);

		$this->assertArrayHasKey('debug', $data);
		$this->assertEquals('Exception', $data['debug']['exception']);
		$this->assertEquals('Test exception', $data['debug']['message']);
		$this->assertArrayHasKey('file', $data['debug']);
		$this->assertArrayHasKey('line', $data['debug']);
	}

	/**
	 * Test method not allowed problem.
	 */
	public function testMethodNotAllowedProblem(): void
	{
		$methods = ['GET', 'POST', 'PUT'];
		$json = $this->controller->methodNotAllowedProblem($methods);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/method-not-allowed', $data['type']);
		$this->assertEquals('Method Not Allowed', $data['title']);
		$this->assertEquals(405, $data['status']);
		$this->assertStringContainsString('GET, POST, PUT', $data['detail']);
		$this->assertEquals($methods, $data['allowed_methods']);

		// Check Allow header
		$this->assertArrayHasKey('Allow', $this->controller->lastHeaders);
		$this->assertEquals('GET, POST, PUT', $this->controller->lastHeaders['Allow']);
	}

	/**
	 * Test unsupported media type problem.
	 */
	public function testUnsupportedMediaTypeProblem(): void
	{
		$types = ['application/json', 'application/xml'];
		$json = $this->controller->unsupportedMediaTypeProblem($types);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/unsupported-media-type', $data['type']);
		$this->assertEquals('Unsupported Media Type', $data['title']);
		$this->assertEquals(415, $data['status']);
		$this->assertStringContainsString('application/json, application/xml', $data['detail']);
		$this->assertEquals($types, $data['supported_types']);
	}

	/**
	 * Test problem response with ProblemType enum.
	 */
	public function testProblemResponseWithEnum(): void
	{
		$json = $this->controller->problemResponse(
			ProblemType::RATE_LIMIT_EXCEEDED,
			detail: 'Too many requests'
		);
		$data = $this->assertJsonResponse($json);

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
		$json = $this->controller->problemResponse(
			'/custom/error',
			'Custom Error',
			418,
			'I am a teapot'
		);
		$data = $this->assertJsonResponse($json);

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
		$json = $this->controller->problemResponse(
			ProblemType::VALIDATION_ERROR,
			'Custom Title',
			422,
			'Custom detail',
			'/api/test',
			['custom' => 'extension']
		);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/validation', $data['type']);
		$this->assertEquals('Custom Title', $data['title']);
		$this->assertEquals(422, $data['status']);
		$this->assertEquals('Custom detail', $data['detail']);
		$this->assertEquals('/api/test', $data['instance']);
		$this->assertEquals('extension', $data['custom']);
	}

	/**
	 * Test problem response with minimal parameters.
	 */
	public function testProblemResponseMinimal(): void
	{
		$json = $this->controller->problemResponse('/custom/minimal');
		$data = $this->assertJsonResponse($json);

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

		$json = $this->controller->validationProblem($errors, 'Multiple validation failures');
		$data = $this->assertJsonResponse($json);

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
		$json = $this->controller->problemResponse(
			ProblemType::CONFLICT,
			detail: 'Multiple conflicts detected',
			extensions: [
				'conflicts' => [
					['resource' => 'email', 'value' => 'user@example.com'],
					['resource' => 'username', 'value' => 'johndoe']
				]
			]
		);
		$data = $this->assertJsonResponse($json);

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

			$json = $this->controller->problemResponse($type);
			$data = $this->assertJsonResponse($json);

			$this->assertEquals($expectedUri, $data['type'], "Failed for type: {$type->name}");
			$this->assertEquals($expectedStatus, $data['status'], "Failed for type: {$type->name}");
			$this->assertNotEmpty($data['title'], "Failed for type: {$type->name}");
		}
	}

	/**
	 * Test request timeout problem.
	 */
	public function testRequestTimeoutProblem(): void
	{
		$json = $this->controller->problemResponse(
			ProblemType::REQUEST_TIMEOUT,
			detail: 'Request processing timed out'
		);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/timeout', $data['type']);
		$this->assertEquals('Request Timeout', $data['title']);
		$this->assertEquals(408, $data['status']);
		$this->assertEquals('Request processing timed out', $data['detail']);
	}

	/**
	 * Test payload too large problem.
	 */
	public function testPayloadTooLargeProblem(): void
	{
		$json = $this->controller->problemResponse(
			ProblemType::PAYLOAD_TOO_LARGE,
			detail: 'Request body exceeds 10MB limit',
			extensions: ['max_size' => '10MB', 'received_size' => '15MB']
		);
		$data = $this->assertJsonResponse($json);

		$this->assertEquals('/errors/payload-too-large', $data['type']);
		$this->assertEquals('Payload Too Large', $data['title']);
		$this->assertEquals(413, $data['status']);
		$this->assertEquals('10MB', $data['max_size']);
		$this->assertEquals('15MB', $data['received_size']);
	}

	/**
	 * Test that trait methods actually call ProblemDetailsResponse.
	 * This ensures we're testing the real trait implementation.
	 */
	public function testTraitMethodsUseProblemDetailsResponse(): void
	{
		// validationProblem should use ProblemDetailsResponse::validationError()
		$json = $this->controller->validationProblem(['test' => 'error']);
		$this->assertStringContainsString('validation', $json);

		// notFoundProblem should use ProblemDetailsResponse::notFound()
		$json = $this->controller->notFoundProblem('Test');
		$this->assertStringContainsString('not-found', $json);

		// These methods should all go through problemResponse() -> problemDetailsResponse()
		$json = $this->controller->authenticationProblem();
		$this->assertStringContainsString('authentication', $json);

		$json = $this->controller->permissionProblem();
		$this->assertStringContainsString('authorization', $json);

		$json = $this->controller->rateLimitProblem(60);
		$this->assertStringContainsString('rate-limit', $json);

		// Verify captured problem details structure
		$this->assertArrayHasKey('type', $this->controller->lastProblemDetails);
		$this->assertArrayHasKey('status', $this->controller->lastProblemDetails);
		$this->assertArrayHasKey('title', $this->controller->lastProblemDetails);
	}
}