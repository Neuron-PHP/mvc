<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Mvc\Requests\Request;
use Neuron\Mvc\Responses\HttpResponseStatus;

class HttpCodes extends Base
{
	public function code404( Request $request ) : string
	{
		return $this->renderHtml(
			HttpResponseStatus::NOT_FOUND,
			array_merge(
				$request->getRouteParameters(),
				[
					"title" => "Resource Not Found",
				]
			),
			'404'
		);
	}

	public function code401( Request $request ) : string
	{
		return $this->renderHtml(
			HttpResponseStatus::UNAUTHORIZED,
			array_merge(
				$request->getRouteParameters(),
				[
					"title" => "Authentication Required",
					"realm" => $request->getRouteParameter( "realm" )
				]
			),
			'401'
		);
	}

	public function code403( Request $request ) : string
	{
		return $this->renderHtml(
			HttpResponseStatus::FORBIDDEN,
			array_merge(
				$request->getRouteParameters(),
				[
					"title" => "Access Forbidden",
					"resource" => $request->getRouteParameter( "resource" ),
					"permission" => $request->getRouteParameter( "permission" )
				]
			),
			'403'
		);
	}

	public function code500( Request $request ) : string
	{
		return $this->renderHtml(
			HttpResponseStatus::INTERNAL_SERVER_ERROR,
			array_merge(
				$request->getRouteParameters(),
				[
					"title" => "Internal Server Error",
					"error" => $request->getRouteParameter( "error" )
				]
			),
			'500'
		);
	}
}
