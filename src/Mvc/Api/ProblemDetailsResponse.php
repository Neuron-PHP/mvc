<?php

namespace Neuron\Mvc\Api;

use Neuron\Core\ProblemDetails\ProblemDetails;

/**
 * Helper class for creating RFC 9457 Problem Details API responses.
 *
 * This class handles the formatting and headers for Problem Details responses,
 * ensuring that API errors are returned with the correct content type and
 * structure as defined in RFC 9457.
 *
 * The class sets the appropriate Content-Type header (application/problem+json)
 * to distinguish error responses from regular JSON responses, allowing clients
 * to handle them differently.
 *
 * @package Neuron\Mvc\Api
 *
 * @example
 * ```php
 * // In an API controller
 * $problem = new ProblemDetails(
 *     type: '/errors/validation',
 *     title: 'Validation Failed',
 *     status: 400,
 *     detail: 'Invalid email format'
 * );
 *
 * $response = new ProblemDetailsResponse($problem);
 * return $response->send();
 *
 * // Or use the static helper
 * return ProblemDetailsResponse::create($problem)->send();
 * ```
 */
class ProblemDetailsResponse
{
	private ProblemDetails $problemDetails;
	private array $headers;

	/**
	 * Create a new Problem Details response.
	 *
	 * @param ProblemDetails $problemDetails The problem details to send
	 * @param array $additionalHeaders Optional additional HTTP headers
	 */
	public function __construct(ProblemDetails $problemDetails, array $additionalHeaders = [])
	{
		$this->problemDetails = $problemDetails;

		// Set default headers
		$this->headers = array_merge([
			'Content-Type' => 'application/problem+json; charset=utf-8',
			'Cache-Control' => 'no-cache, no-store, must-revalidate',
		], $additionalHeaders);
	}

	/**
	 * Static factory method for creating a response.
	 *
	 * @param ProblemDetails $problemDetails The problem details
	 * @param array $additionalHeaders Optional additional headers
	 * @return self
	 */
	public static function create(ProblemDetails $problemDetails, array $additionalHeaders = []): self
	{
		return new self($problemDetails, $additionalHeaders);
	}

	/**
	 * Add an additional header to the response.
	 *
	 * @param string $name Header name
	 * @param string $value Header value
	 * @return self
	 */
	public function withHeader(string $name, string $value): self
	{
		$this->headers[$name] = $value;
		return $this;
	}

	/**
	 * Add multiple headers to the response.
	 *
	 * @param array $headers Associative array of headers
	 * @return self
	 */
	public function withHeaders(array $headers): self
	{
		$this->headers = array_merge($this->headers, $headers);
		return $this;
	}

	/**
	 * Get the problem details.
	 *
	 * @return ProblemDetails
	 */
	public function getProblemDetails(): ProblemDetails
	{
		return $this->problemDetails;
	}

	/**
	 * Get the response headers.
	 *
	 * @return array
	 */
	public function getHeaders(): array
	{
		return $this->headers;
	}

	/**
	 * Get the JSON representation of the problem details.
	 *
	 * @param int $options JSON encoding options
	 * @return string JSON string
	 */
	public function toJson(int $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
	{
		return json_encode($this->problemDetails, $options);
	}

	/**
	 * Send the response with appropriate headers and status code.
	 *
	 * This method sets the HTTP response code, sends headers, and returns
	 * the JSON-encoded problem details. It's designed to be the final
	 * return value from a controller action.
	 *
	 * @return string The JSON response body
	 */
	public function send(): string
	{
		// Set HTTP response code
		if (!headers_sent()) {
			http_response_code($this->problemDetails->getStatus());

			// Send headers
			foreach ($this->headers as $name => $value) {
				header("$name: $value");
			}
		}

		return $this->toJson();
	}

	/**
	 * Get the response without sending headers.
	 *
	 * This is useful when the framework handles header sending separately,
	 * or for testing purposes.
	 *
	 * @return array Array with 'status', 'headers', and 'body' keys
	 */
	public function toArray(): array
	{
		return [
			'status' => $this->problemDetails->getStatus(),
			'headers' => $this->headers,
			'body' => $this->toJson(),
		];
	}

	/**
	 * Create a response for specific rate limiting scenarios.
	 *
	 * @param int $retryAfter Seconds until the client should retry
	 * @param string|null $detail Specific details about the rate limit
	 * @return self
	 */
	public static function rateLimitExceeded(int $retryAfter, ?string $detail = null): self
	{
		$problem = new ProblemDetails(
			type: '/errors/rate-limit',
			title: 'Rate Limit Exceeded',
			status: 429,
			detail: $detail ?? "Rate limit exceeded. Please retry after $retryAfter seconds.",
			extensions: ['retry_after' => $retryAfter]
		);

		return (new self($problem))
			->withHeader('Retry-After', (string) $retryAfter);
	}

	/**
	 * Create a response for validation errors.
	 *
	 * @param array $errors Field-specific validation errors
	 * @param string|null $detail Overall error description
	 * @return self
	 */
	public static function validationError(array $errors, ?string $detail = null): self
	{
		$problem = new ProblemDetails(
			type: '/errors/validation',
			title: 'Validation Failed',
			status: 400,
			detail: $detail ?? 'The request contains invalid fields.',
			extensions: ['errors' => $errors]
		);

		return new self($problem);
	}

	/**
	 * Create a response for not found errors.
	 *
	 * @param string $resource The type of resource that was not found
	 * @param string|int|null $identifier The identifier that was searched for
	 * @return self
	 */
	public static function notFound(string $resource, string|int|null $identifier = null): self
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

		return new self($problem);
	}
}