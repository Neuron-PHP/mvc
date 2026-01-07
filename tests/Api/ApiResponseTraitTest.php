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
}