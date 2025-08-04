<?php

namespace Mvc;

class MockPhpInputStream
{
	public $context;
	private static $data;
	private $position;

	public function __construct()
	{
		$this->position = 0;
	}

	public static function setData($data)
	{
		self::$data = $data;
	}

	public function stream_open($path, $mode, $options, &$opened_path)
	{
		return true;
	}

	public function stream_read($count)
	{
		$result = substr(self::$data, $this->position, $count);
		$this->position += strlen($result);
		return $result;
	}

	public function stream_write($data)
	{
		if (self::$data === null) {
			self::$data = "";
		}

		$left = substr(self::$data, 0, $this->position);
		$right = substr(self::$data, $this->position + strlen($data));
		self::$data = $left . $data . $right;
		$this->position += strlen($data);
		return strlen($data);
	}

	public function stream_eof()
	{
		return $this->position >= strlen(self::$data);
	}

	public function stream_stat()
	{
		return [];
	}

	public function stream_seek($offset, $whence)
	{
		if ($whence === SEEK_SET) {
			$this->position = $offset;
			return true;
		}
		return false;
	}
}
