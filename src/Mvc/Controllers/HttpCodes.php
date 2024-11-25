<?php

namespace Neuron\Mvc\Controllers;

class HttpCodes extends Base
{
	public function _404( array $Parameters )
	{
		return $this->renderHtml(
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
