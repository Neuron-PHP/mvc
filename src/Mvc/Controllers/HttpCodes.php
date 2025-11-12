<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Mvc\Responses\HttpResponseStatus;

class HttpCodes extends Base
{
	public function code404( array $parameters ) : string
	{
		return $this->renderHtml(
			HttpResponseStatus::NOT_FOUND,
			array_merge(
				$parameters,
				[
					"title" => "Resource Not Found",
				]
			),
			'404'
		);
	}
}
