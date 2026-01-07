<?php

namespace Neuron\Mvc\Tests\Api;

use Neuron\Core\ProblemDetails\ProblemDetails;
use Neuron\Core\ProblemDetails\ProblemType;
use Neuron\Mvc\Api\ProblemDetailsResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProblemDetailsResponse class.
 */
class ProblemDetailsResponseTest extends TestCase
{
	/**
	 * Test creating a basic problem details response.
	 */
	public function testCreateBasicResponse(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test Error',
			status: 400,
			detail: 'Test detail'
		);

		$response = new ProblemDetailsResponse($problem);

		$this->assertSame($problem, $response->getProblemDetails());
		$headers = $response->getHeaders();
		$this->assertArrayHasKey('Content-Type', $headers);
		$this->assertEquals('application/problem+json; charset=utf-8', $headers['Content-Type']);
		$this->assertArrayHasKey('Cache-Control', $headers);
		$this->assertEquals('no-cache, no-store, must-revalidate', $headers['Cache-Control']);
	}

	/**
	 * Test static create method.
	 */
	public function testStaticCreate(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 404
		);

		$response = ProblemDetailsResponse::create($problem);

		$this->assertInstanceOf(ProblemDetailsResponse::class, $response);
		$this->assertSame($problem, $response->getProblemDetails());
	}

	/**
	 * Test adding custom headers.
	 */
	public function testWithHeader(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 429
		);

		$response = ProblemDetailsResponse::create($problem)
			->withHeader('Retry-After', '60')
			->withHeader('X-Custom', 'value');

		$headers = $response->getHeaders();
		$this->assertEquals('60', $headers['Retry-After']);
		$this->assertEquals('value', $headers['X-Custom']);
		$this->assertArrayHasKey('Content-Type', $headers);
	}

	/**
	 * Test adding multiple headers at once.
	 */
	public function testWithHeaders(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 405
		);

		$customHeaders = [
			'Allow' => 'GET, POST',
			'X-Request-Id' => 'abc123'
		];

		$response = ProblemDetailsResponse::create($problem)
			->withHeaders($customHeaders);

		$headers = $response->getHeaders();
		$this->assertEquals('GET, POST', $headers['Allow']);
		$this->assertEquals('abc123', $headers['X-Request-Id']);
	}

	/**
	 * Test JSON encoding.
	 */
	public function testToJson(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/validation',
			title: 'Validation Failed',
			status: 400,
			detail: 'Invalid input',
			extensions: ['errors' => ['field' => 'error']]
		);

		$response = new ProblemDetailsResponse($problem);
		$json = $response->toJson();
		$decoded = json_decode($json, true);

		$this->assertEquals('/errors/validation', $decoded['type']);
		$this->assertEquals('Validation Failed', $decoded['title']);
		$this->assertEquals(400, $decoded['status']);
		$this->assertEquals('Invalid input', $decoded['detail']);
		$this->assertEquals(['field' => 'error'], $decoded['errors']);
	}

	/**
	 * Test JSON encoding with custom options.
	 */
	public function testToJsonWithOptions(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test & Error',
			status: 400,
			detail: 'Test with <special> chars'
		);

		$response = new ProblemDetailsResponse($problem);

		// Default options should not escape slashes or unicode
		$json = $response->toJson();
		$this->assertStringContainsString('/errors/test', $json);
		$this->assertStringContainsString('Test & Error', $json);

		// Test with different options
		$jsonEscaped = $response->toJson(JSON_HEX_TAG | JSON_HEX_AMP);
		$this->assertStringContainsString('\u0026', $jsonEscaped);
		$this->assertStringContainsString('\u003C', $jsonEscaped);
	}

	/**
	 * Test toArray method.
	 */
	public function testToArray(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 418
		);

		$response = ProblemDetailsResponse::create($problem)
			->withHeader('X-Test', 'value');

		$array = $response->toArray();

		$this->assertIsArray($array);
		$this->assertArrayHasKey('status', $array);
		$this->assertArrayHasKey('headers', $array);
		$this->assertArrayHasKey('body', $array);

		$this->assertEquals(418, $array['status']);
		$this->assertEquals('value', $array['headers']['X-Test']);
		$this->assertJson($array['body']);

		$body = json_decode($array['body'], true);
		$this->assertEquals('/errors/test', $body['type']);
	}

	/**
	 * Test rate limit exceeded helper.
	 */
	public function testRateLimitExceeded(): void
	{
		$response = ProblemDetailsResponse::rateLimitExceeded(60);

		$headers = $response->getHeaders();
		$this->assertEquals('60', $headers['Retry-After']);

		$problem = $response->getProblemDetails();
		$this->assertEquals('/errors/rate-limit', $problem->getType());
		$this->assertEquals('Rate Limit Exceeded', $problem->getTitle());
		$this->assertEquals(429, $problem->getStatus());
		$this->assertEquals(60, $problem->getExtension('retry_after'));
	}

	/**
	 * Test rate limit with custom detail.
	 */
	public function testRateLimitWithDetail(): void
	{
		$response = ProblemDetailsResponse::rateLimitExceeded(120, 'API limit reached');

		$problem = $response->getProblemDetails();
		$this->assertEquals('API limit reached', $problem->getDetail());
		$this->assertEquals(120, $problem->getExtension('retry_after'));
	}

	/**
	 * Test validation error helper.
	 */
	public function testValidationError(): void
	{
		$errors = [
			'email' => 'Invalid format',
			'password' => 'Too weak'
		];

		$response = ProblemDetailsResponse::validationError($errors);

		$problem = $response->getProblemDetails();
		$this->assertEquals('/errors/validation', $problem->getType());
		$this->assertEquals('Validation Failed', $problem->getTitle());
		$this->assertEquals(400, $problem->getStatus());
		$this->assertEquals($errors, $problem->getExtension('errors'));
	}

	/**
	 * Test validation error with custom detail.
	 */
	public function testValidationErrorWithDetail(): void
	{
		$errors = ['field' => 'error'];
		$detail = 'Custom validation message';

		$response = ProblemDetailsResponse::validationError($errors, $detail);

		$problem = $response->getProblemDetails();
		$this->assertEquals($detail, $problem->getDetail());
		$this->assertEquals($errors, $problem->getExtension('errors'));
	}

	/**
	 * Test not found helper.
	 */
	public function testNotFound(): void
	{
		$response = ProblemDetailsResponse::notFound('User', 123);

		$problem = $response->getProblemDetails();
		$this->assertEquals('/errors/not-found', $problem->getType());
		$this->assertEquals('Resource Not Found', $problem->getTitle());
		$this->assertEquals(404, $problem->getStatus());
		$this->assertEquals("User with identifier '123' was not found.", $problem->getDetail());
	}

	/**
	 * Test not found without identifier.
	 */
	public function testNotFoundWithoutIdentifier(): void
	{
		$response = ProblemDetailsResponse::notFound('Configuration');

		$problem = $response->getProblemDetails();
		$this->assertEquals("Configuration was not found.", $problem->getDetail());
	}

	/**
	 * Test not found with string identifier.
	 */
	public function testNotFoundWithStringIdentifier(): void
	{
		$response = ProblemDetailsResponse::notFound('Article', 'my-article-slug');

		$problem = $response->getProblemDetails();
		$this->assertEquals("Article with identifier 'my-article-slug' was not found.", $problem->getDetail());
	}

	/**
	 * Test send method returns JSON.
	 */
	public function testSendReturnsJson(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 400
		);

		$response = new ProblemDetailsResponse($problem);

		// Since we can't test actual header sending in unit tests,
		// we'll test that send() returns the JSON string
		$result = $response->send();

		$this->assertIsString($result);
		$this->assertJson($result);

		$decoded = json_decode($result, true);
		$this->assertEquals('/errors/test', $decoded['type']);
	}

	/**
	 * Test constructor with additional headers.
	 */
	public function testConstructorWithAdditionalHeaders(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 401
		);

		$additionalHeaders = [
			'WWW-Authenticate' => 'Bearer realm="api"',
			'X-Request-Id' => 'xyz789'
		];

		$response = new ProblemDetailsResponse($problem, $additionalHeaders);

		$headers = $response->getHeaders();
		$this->assertEquals('Bearer realm="api"', $headers['WWW-Authenticate']);
		$this->assertEquals('xyz789', $headers['X-Request-Id']);
		// Default headers should still be present
		$this->assertArrayHasKey('Content-Type', $headers);
		$this->assertArrayHasKey('Cache-Control', $headers);
	}

	/**
	 * Test that default headers can be overridden.
	 */
	public function testOverrideDefaultHeaders(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 400
		);

		$response = new ProblemDetailsResponse($problem, [
			'Cache-Control' => 'public, max-age=3600'
		]);

		$headers = $response->getHeaders();
		$this->assertEquals('public, max-age=3600', $headers['Cache-Control']);
	}

	/**
	 * Test complex problem with nested extensions.
	 */
	public function testComplexProblemResponse(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/business',
			title: 'Business Rule Violation',
			status: 422,
			detail: 'Order cannot be processed',
			instance: '/api/orders/12345',
			extensions: [
				'violations' => [
					['rule' => 'MIN_ORDER_AMOUNT', 'message' => 'Order too small'],
					['rule' => 'INVENTORY_CHECK', 'message' => 'Items out of stock']
				],
				'order_id' => 12345,
				'timestamp' => 1234567890
			]
		);

		$response = ProblemDetailsResponse::create($problem)
			->withHeader('X-Order-Id', '12345');

		$json = $response->toJson();
		$decoded = json_decode($json, true);

		$this->assertEquals('/errors/business', $decoded['type']);
		$this->assertEquals(422, $decoded['status']);
		$this->assertEquals('/api/orders/12345', $decoded['instance']);
		$this->assertCount(2, $decoded['violations']);
		$this->assertEquals(12345, $decoded['order_id']);

		$headers = $response->getHeaders();
		$this->assertEquals('12345', $headers['X-Order-Id']);
	}

	/**
	 * Test fluent interface chaining.
	 */
	public function testFluentInterface(): void
	{
		$problem = new ProblemDetails(
			type: '/errors/test',
			title: 'Test',
			status: 400
		);

		$response = ProblemDetailsResponse::create($problem)
			->withHeader('X-One', '1')
			->withHeader('X-Two', '2')
			->withHeaders(['X-Three' => '3', 'X-Four' => '4']);

		$headers = $response->getHeaders();
		$this->assertEquals('1', $headers['X-One']);
		$this->assertEquals('2', $headers['X-Two']);
		$this->assertEquals('3', $headers['X-Three']);
		$this->assertEquals('4', $headers['X-Four']);
	}
}