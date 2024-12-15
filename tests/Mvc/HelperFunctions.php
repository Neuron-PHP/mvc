<?php
use Mvc\MockPhpInputStream;

require_once 'tests/Mvc/MockPhpInputStream.php';

function setInputStream($data)
{
	stream_wrapper_unregister("php");
	MockPhpInputStream::setData($data);
	stream_wrapper_register("php", "Mvc\MockPhpInputStream");
	file_put_contents("php://input", $data);
}

function setHeaders( array $headers )
{
	foreach( $headers as $key => $value )
	{
		$name = 'HTTP_' . strtoupper( str_replace('-', '_', $key ) );
		$_SERVER[$name] = $value;
	}
}
