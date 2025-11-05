<?php

namespace Neuron\Mvc\Responses;

/**
 * HTTP response status code enumeration for the MVC framework.
 * 
 * This enum provides a comprehensive collection of standard HTTP response status
 * codes as defined in RFC 7231 and related specifications. It enables type-safe
 * status code handling throughout the MVC framework and ensures consistent
 * HTTP response behavior across all controllers and views.
 * 
 * Status code categories:
 * - 2xx Success: Request successfully received, understood, and accepted
 * - 3xx Redirection: Further action needs to be taken to complete the request
 * - 4xx Client Error: Request contains bad syntax or cannot be fulfilled
 * - 5xx Server Error: Server failed to fulfill an apparently valid request
 * 
 * Key benefits:
 * - Type safety for HTTP status codes
 * - IDE auto-completion support
 * - Prevention of invalid status code usage
 * - Clear semantic meaning for response states
 * - Integration with framework response handling
 * 
 * @package Neuron\Mvc\Responses
 * 
 * @example
 * ```php
 * // Using in controller responses
 * public function create(): Response
 * {
 *     try {
 *         $user = $this->userService->create($userData);
 *         return $this->response($user, HttpResponseStatus::CREATED);
 *     } catch (ValidationException $e) {
 *         return $this->errorResponse('Validation failed', HttpResponseStatus::BAD_REQUEST);
 *     }
 * }
 * 
 * // Setting response status
 * public function show(int $id): Response
 * {
 *     $user = $this->userService->find($id);
 *     if (!$user) {
 *         return $this->errorResponse('User not found', HttpResponseStatus::NOT_FOUND);
 *     }
 *     return $this->response($user, HttpResponseStatus::OK);
 * }
 * ```
 */
enum HttpResponseStatus: int
{
	case OK = 200;
	case CREATED = 201;
	case ACCEPTED = 202;
	case NO_CONTENT = 204;
	case MOVED_PERMANENTLY = 301;
	case FOUND = 302;
	case SEE_OTHER = 303;
	case NOT_MODIFIED = 304;
	case TEMPORARY_REDIRECT = 307;
	case PERMANENT_REDIRECT = 308;
	case BAD_REQUEST = 400;
	case UNAUTHORIZED = 401;
	case FORBIDDEN = 403;
	case NOT_FOUND = 404;
	case METHOD_NOT_ALLOWED = 405;
	case NOT_ACCEPTABLE = 406;
	case REQUEST_TIMEOUT = 408;
	case CONFLICT = 409;
	case GONE = 410;
	case LENGTH_REQUIRED = 411;
	case PRECONDITION_FAILED = 412;
	case PAYLOAD_TOO_LARGE = 413;
	case UNSUPPORTED_MEDIA_TYPE = 415;
	case UPGRADE_REQUIRED = 426;
	case TOO_MANY_REQUESTS = 429;
	case INTERNAL_SERVER_ERROR = 500;
	case NOT_IMPLEMENTED = 501;
	case BAD_GATEWAY = 502;
	case SERVICE_UNAVAILABLE = 503;
	case GATEWAY_TIMEOUT = 504;
	case HTTP_VERSION_NOT_SUPPORTED = 505;
	case NETWORK_AUTHENTICATION_REQUIRED = 511;
}
