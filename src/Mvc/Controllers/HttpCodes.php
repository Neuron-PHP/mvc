<?php

namespace Neuron\Mvc\Controllers;

class HttpCodes extends Base
{
	public function code404( array $Parameters ) : string
	{
		return $this->renderHtml(
			404,
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
