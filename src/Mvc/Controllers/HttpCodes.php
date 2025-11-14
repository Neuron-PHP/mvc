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
}
