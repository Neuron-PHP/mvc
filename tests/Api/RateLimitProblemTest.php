<?php

namespace Tests\Api;

use Neuron\Mvc\Api\ApiResponseTrait;
use PHPUnit\Framework\TestCase;

class RateLimitProblemTest extends TestCase
{
	private $controller;

	protected function setUp(): void
	{
		// Create a test controller that uses the trait
		$this->controller = new class {
			use ApiResponseTrait;

			public function testRateLimitProblem(
				int $retryAfter,
				?string $detail = null,
				?int $limit = null,
				?int $remaining = null
			): string {
				return $this->rateLimitProblem($retryAfter, $detail, $limit, $remaining);
			}
		};
	}

	public function testRateLimitProblemWithAllParameters(): void
	{
		$result = $this->controller->testRateLimitProblem(
			retryAfter: 60,
			detail: 'You have exceeded the rate limit',
			limit: 100,
			remaining: 0
		);

		$data = json_decode($result, true);

		// Check standard fields
		$this->assertEquals('/errors/rate-limit', $data['type']);
		$this->assertEquals('Rate Limit Exceeded', $data['title']);
		$this->assertEquals(429, $data['status']);
		$this->assertEquals('You have exceeded the rate limit', $data['detail']);

		// Check all extensions are included
		$this->assertArrayHasKey('retry_after', $data);
		$this->assertEquals(60, $data['retry_after']);

		$this->assertArrayHasKey('limit', $data);
		$this->assertEquals(100, $data['limit']);

		$this->assertArrayHasKey('remaining', $data);
		$this->assertEquals(0, $data['remaining']);
	}

	public function testRateLimitProblemWithOnlyRetryAfter(): void
	{
		$result = $this->controller->testRateLimitProblem(
			retryAfter: 30
		);

		$data = json_decode($result, true);

		// Check standard fields
		$this->assertEquals('/errors/rate-limit', $data['type']);
		$this->assertEquals('Rate Limit Exceeded', $data['title']);
		$this->assertEquals(429, $data['status']);

		// Check only retry_after is included in extensions
		$this->assertArrayHasKey('retry_after', $data);
		$this->assertEquals(30, $data['retry_after']);

		$this->assertArrayNotHasKey('limit', $data);
		$this->assertArrayNotHasKey('remaining', $data);
	}

	public function testRateLimitProblemWithPartialExtensions(): void
	{
		$result = $this->controller->testRateLimitProblem(
			retryAfter: 45,
			detail: 'API rate limit reached',
			limit: 50
			// remaining is null
		);

		$data = json_decode($result, true);

		// Check standard fields
		$this->assertEquals('API rate limit reached', $data['detail']);

		// Check extensions
		$this->assertArrayHasKey('retry_after', $data);
		$this->assertEquals(45, $data['retry_after']);

		$this->assertArrayHasKey('limit', $data);
		$this->assertEquals(50, $data['limit']);

		// remaining should not be included when null
		$this->assertArrayNotHasKey('remaining', $data);
	}

	public function testRateLimitProblemWithZeroRemaining(): void
	{
		$result = $this->controller->testRateLimitProblem(
			retryAfter: 120,
			detail: null,
			limit: null,
			remaining: 0
		);

		$data = json_decode($result, true);

		// Check extensions - 0 should be included
		$this->assertArrayHasKey('retry_after', $data);
		$this->assertEquals(120, $data['retry_after']);

		$this->assertArrayNotHasKey('limit', $data);

		$this->assertArrayHasKey('remaining', $data);
		$this->assertEquals(0, $data['remaining']);
	}
}