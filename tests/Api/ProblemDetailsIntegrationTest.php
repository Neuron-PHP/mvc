<?php

namespace Neuron\Mvc\Tests\Api;

use Neuron\Core\ProblemDetails\ProblemDetails;
use Neuron\Core\ProblemDetails\ProblemType;
use Neuron\Mvc\Api\ApiResponseTrait;
use Neuron\Mvc\Api\ProblemDetailsResponse;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\IMvcApplication;
use Neuron\Mvc\Requests\Request;
use Neuron\Mvc\Responses\HttpResponseStatus;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests demonstrating real-world usage of Problem Details in API controllers.
 */
class ProblemDetailsIntegrationTest extends TestCase
{
	private $apiController;

	protected function setUp(): void
	{
		// Create a mock MVC application
		$mockApp = $this->createMock(IMvcApplication::class);

		// Create a realistic API controller
		$this->apiController = new class($mockApp) extends Base {
			use ApiResponseTrait;

			private array $users = [
				1 => ['id' => 1, 'email' => 'user1@example.com', 'name' => 'User One'],
				2 => ['id' => 2, 'email' => 'user2@example.com', 'name' => 'User Two'],
			];

			private array $rateLimits = [];

			/**
			 * GET /api/users/{id}
			 */
			public function show(int $id): string
			{
				if (!isset($this->users[$id])) {
					return $this->notFoundProblem('User', $id);
				}

				return $this->renderJson(HttpResponseStatus::OK, [
					'data' => $this->users[$id]
				]);
			}

			/**
			 * POST /api/users
			 */
			public function create(array $data): string
			{
				// Validate input
				$errors = $this->validate($data);
				if (!empty($errors)) {
					return $this->validationProblem($errors);
				}

				// Check for duplicate email
				foreach ($this->users as $user) {
					if ($user['email'] === $data['email']) {
						return $this->conflictProblem(
							'A user with this email already exists',
							'user-email'
						);
					}
				}

				// Create user
				$id = max(array_keys($this->users)) + 1;
				$this->users[$id] = [
					'id' => $id,
					'email' => $data['email'],
					'name' => $data['name']
				];

				return $this->renderJson(HttpResponseStatus::CREATED, [
					'data' => $this->users[$id]
				]);
			}

			/**
			 * PUT /api/users/{id}
			 */
			public function update(int $id, array $data): string
			{
				if (!isset($this->users[$id])) {
					return $this->notFoundProblem('User', $id);
				}

				// Validate input
				$errors = $this->validate($data, isUpdate: true);
				if (!empty($errors)) {
					return $this->validationProblem($errors);
				}

				// Update user
				$this->users[$id] = array_merge($this->users[$id], $data);

				return $this->renderJson(HttpResponseStatus::OK, [
					'data' => $this->users[$id]
				]);
			}

			/**
			 * DELETE /api/users/{id}
			 */
			public function delete(int $id, bool $hasPermission = true): string
			{
				if (!$hasPermission) {
					return $this->permissionProblem(
						'Admin privileges required to delete users',
						['admin', 'super_admin']
					);
				}

				if (!isset($this->users[$id])) {
					return $this->notFoundProblem('User', $id);
				}

				unset($this->users[$id]);

				return $this->renderJson(HttpResponseStatus::NO_CONTENT, []);
			}

			/**
			 * GET /api/search
			 */
			public function search(string $clientId): string
			{
				// Simple rate limiting
				if (!isset($this->rateLimits[$clientId])) {
					$this->rateLimits[$clientId] = ['count' => 0, 'reset' => time() + 60];
				}

				if (time() > $this->rateLimits[$clientId]['reset']) {
					$this->rateLimits[$clientId] = ['count' => 1, 'reset' => time() + 60];
				} else {
					$this->rateLimits[$clientId]['count']++;
				}

				if ($this->rateLimits[$clientId]['count'] > 3) {
					$retryAfter = $this->rateLimits[$clientId]['reset'] - time();
					return $this->rateLimitProblem(
						$retryAfter,
						'Search API rate limit exceeded',
						3,
						0
					);
				}

				return $this->renderJson(HttpResponseStatus::OK, [
					'data' => array_values($this->users)
				]);
			}

			/**
			 * POST /api/process
			 */
			public function process(bool $shouldFail = false): string
			{
				if ($shouldFail) {
					// Simulate an internal error
					return $this->internalErrorProblem(
						'Failed to process request due to internal error'
					);
				}

				return $this->renderJson(HttpResponseStatus::OK, [
					'data' => ['status' => 'processed']
				]);
			}

			/**
			 * Custom business logic error
			 */
			public function businessError(): string
			{
				return $this->problemResponse(
					type: '/errors/business/insufficient-inventory',
					title: 'Insufficient Inventory',
					status: 422,
					detail: 'Cannot complete order due to stock shortage',
					instance: '/api/orders/12345',
					extensions: [
						'order_id' => 12345,
						'items_unavailable' => [
							['sku' => 'ITEM-1', 'requested' => 10, 'available' => 5],
							['sku' => 'ITEM-2', 'requested' => 3, 'available' => 0]
						]
					]
				);
			}

			private function validate(array $data, bool $isUpdate = false): array
			{
				$errors = [];

				if (!$isUpdate || isset($data['email'])) {
					if (empty($data['email'])) {
						$errors['email'] = 'Email is required';
					} elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
						$errors['email'] = 'Invalid email format';
					}
				}

				if (!$isUpdate || isset($data['name'])) {
					if (empty($data['name'])) {
						$errors['name'] = 'Name is required';
					} elseif (strlen($data['name']) < 2) {
						$errors['name'] = 'Name must be at least 2 characters';
					}
				}

				return $errors;
			}

			// Override renderJson for testing
			public function renderJson(HttpResponseStatus $responseCode, array $data = []): string
			{
				return json_encode($data);
			}
		};
	}

	/**
	 * Test successful GET request.
	 */
	public function testSuccessfulGet(): void
	{
		$response = $this->apiController->show(1);
		$data = json_decode($response, true);

		$this->assertArrayHasKey('data', $data);
		$this->assertEquals(1, $data['data']['id']);
		$this->assertEquals('user1@example.com', $data['data']['email']);
	}

	/**
	 * Test GET with not found error.
	 */
	public function testGetNotFound(): void
	{
		$response = $this->apiController->show(999);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/not-found', $data['type']);
		$this->assertEquals('Resource Not Found', $data['title']);
		$this->assertEquals(404, $data['status']);
		$this->assertEquals("User with identifier '999' was not found.", $data['detail']);
	}

	/**
	 * Test POST with validation errors.
	 */
	public function testPostValidationError(): void
	{
		$response = $this->apiController->create([
			'email' => 'invalid-email',
			'name' => 'A'
		]);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/validation', $data['type']);
		$this->assertEquals(400, $data['status']);
		$this->assertArrayHasKey('errors', $data);
		$this->assertEquals('Invalid email format', $data['errors']['email']);
		$this->assertEquals('Name must be at least 2 characters', $data['errors']['name']);
	}

	/**
	 * Test POST with conflict error.
	 */
	public function testPostConflict(): void
	{
		$response = $this->apiController->create([
			'email' => 'user1@example.com',
			'name' => 'New User'
		]);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/conflict', $data['type']);
		$this->assertEquals(409, $data['status']);
		$this->assertEquals('A user with this email already exists', $data['detail']);
		$this->assertEquals('user-email', $data['conflicting_resource']);
	}

	/**
	 * Test successful POST.
	 */
	public function testSuccessfulPost(): void
	{
		$response = $this->apiController->create([
			'email' => 'newuser@example.com',
			'name' => 'New User'
		]);
		$data = json_decode($response, true);

		$this->assertArrayHasKey('data', $data);
		$this->assertEquals('newuser@example.com', $data['data']['email']);
		$this->assertEquals('New User', $data['data']['name']);
		$this->assertArrayHasKey('id', $data['data']);
	}

	/**
	 * Test PUT with not found.
	 */
	public function testPutNotFound(): void
	{
		$response = $this->apiController->update(999, ['name' => 'Updated']);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/not-found', $data['type']);
		$this->assertEquals(404, $data['status']);
	}

	/**
	 * Test PUT with validation error.
	 */
	public function testPutValidationError(): void
	{
		$response = $this->apiController->update(1, ['email' => 'not-an-email']);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/validation', $data['type']);
		$this->assertEquals(400, $data['status']);
		$this->assertArrayHasKey('errors', $data);
		$this->assertEquals('Invalid email format', $data['errors']['email']);
	}

	/**
	 * Test successful PUT.
	 */
	public function testSuccessfulPut(): void
	{
		$response = $this->apiController->update(1, ['name' => 'Updated Name']);
		$data = json_decode($response, true);

		$this->assertArrayHasKey('data', $data);
		$this->assertEquals('Updated Name', $data['data']['name']);
		$this->assertEquals(1, $data['data']['id']);
	}

	/**
	 * Test DELETE with permission denied.
	 */
	public function testDeletePermissionDenied(): void
	{
		$response = $this->apiController->delete(1, hasPermission: false);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/authorization', $data['type']);
		$this->assertEquals(403, $data['status']);
		$this->assertEquals('Admin privileges required to delete users', $data['detail']);
		$this->assertEquals(['admin', 'super_admin'], $data['required_permissions']);
	}

	/**
	 * Test rate limiting.
	 */
	public function testRateLimit(): void
	{
		$clientId = 'test-client';

		// First 3 requests should succeed
		for ($i = 0; $i < 3; $i++) {
			$response = $this->apiController->search($clientId);
			$data = json_decode($response, true);
			$this->assertArrayHasKey('data', $data);
		}

		// Fourth request should be rate limited
		$response = $this->apiController->search($clientId);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/rate-limit', $data['type']);
		$this->assertEquals(429, $data['status']);
		$this->assertEquals('Search API rate limit exceeded', $data['detail']);
		$this->assertArrayHasKey('retry_after', $data);
		$this->assertEquals(3, $data['limit']);
		$this->assertEquals(0, $data['remaining']);
	}

	/**
	 * Test internal server error.
	 */
	public function testInternalError(): void
	{
		$response = $this->apiController->process(shouldFail: true);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/internal', $data['type']);
		$this->assertEquals('Internal Server Error', $data['title']);
		$this->assertEquals(500, $data['status']);
		$this->assertEquals('Failed to process request due to internal error', $data['detail']);
		// Should not expose debug info in production mode
		$this->assertArrayNotHasKey('debug', $data);
	}

	/**
	 * Test custom business logic error.
	 */
	public function testBusinessError(): void
	{
		$response = $this->apiController->businessError();
		$data = json_decode($response, true);

		$this->assertEquals('/errors/business/insufficient-inventory', $data['type']);
		$this->assertEquals('Insufficient Inventory', $data['title']);
		$this->assertEquals(422, $data['status']);
		$this->assertEquals('Cannot complete order due to stock shortage', $data['detail']);
		$this->assertEquals('/api/orders/12345', $data['instance']);
		$this->assertEquals(12345, $data['order_id']);
		$this->assertCount(2, $data['items_unavailable']);
		$this->assertEquals('ITEM-1', $data['items_unavailable'][0]['sku']);
	}

	/**
	 * Test that problem details responses are distinguishable from success responses.
	 */
	public function testResponseDistinction(): void
	{
		// Success response structure
		$success = $this->apiController->show(1);
		$successData = json_decode($success, true);
		$this->assertArrayHasKey('data', $successData);
		$this->assertArrayNotHasKey('type', $successData);
		$this->assertArrayNotHasKey('status', $successData);

		// Error response structure (Problem Details)
		$error = $this->apiController->show(999);
		$errorData = json_decode($error, true);
		$this->assertArrayHasKey('type', $errorData);
		$this->assertArrayHasKey('title', $errorData);
		$this->assertArrayHasKey('status', $errorData);
		$this->assertArrayNotHasKey('data', $errorData);
	}

	/**
	 * Test multiple validation errors with nested structure.
	 */
	public function testComplexValidationErrors(): void
	{
		// Test nested validation errors through the POST endpoint
		$response = $this->apiController->create([
			'email' => '',  // Required field empty
			'name' => ''    // Required field empty
		]);
		$data = json_decode($response, true);

		$this->assertEquals('/errors/validation', $data['type']);
		$this->assertEquals(400, $data['status']);
		$this->assertArrayHasKey('errors', $data);
		$this->assertArrayHasKey('email', $data['errors']);
		$this->assertArrayHasKey('name', $data['errors']);
		$this->assertEquals('Email is required', $data['errors']['email']);
		$this->assertEquals('Name is required', $data['errors']['name']);
	}

	/**
	 * Test that all implemented problem types return valid structure.
	 */
	public function testProblemDetailsStructure(): void
	{
		// Test various error scenarios in the controller
		$scenarios = [
			'notFound' => $this->apiController->show(999),
			'validation' => $this->apiController->create(['email' => 'bad', 'name' => '']),
			'conflict' => $this->apiController->create(['email' => 'user1@example.com', 'name' => 'Test']),
			'permission' => $this->apiController->delete(1, false),
			'internal' => $this->apiController->process(true),
		];

		foreach ($scenarios as $name => $response) {
			$data = json_decode($response, true);

			// All problem details must have these fields
			$this->assertArrayHasKey('type', $data, "Missing 'type' for: $name");
			$this->assertArrayHasKey('title', $data, "Missing 'title' for: $name");
			$this->assertArrayHasKey('status', $data, "Missing 'status' for: $name");

			// Type should be a URI-like string
			$this->assertStringStartsWith('/errors/', $data['type'], "Invalid type format for: $name");

			// Status should be a 4xx or 5xx code
			$this->assertGreaterThanOrEqual(400, $data['status'], "Invalid status code for: $name");
			$this->assertLessThan(600, $data['status'], "Invalid status code for: $name");

			// Title should not be empty
			$this->assertNotEmpty($data['title'], "Empty title for: $name");
		}
	}
}