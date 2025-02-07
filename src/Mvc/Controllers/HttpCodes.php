<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Mvc\Responses\HttpResponseStatus;

class HttpCodes extends Base
{
	public function code404( array $Parameters ) : string
	{
		return $this->renderHtml(
			HttpResponseStatus::NOT_FOUND,
			array_merge(
				$Parameters,
				[
					"Title" => "Resource Not Found",
				]
			),
			'404'
		);
	}
}
