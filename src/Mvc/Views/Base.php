<?php

namespace Neuron\Mvc\Views;

class Base
{
	private string $_Layout;
	private string $_Controller;
	private string $_Page;

	public function __construct()
	{}

	/**
	 * @return string
	 */
	public function getLayout(): string
	{
		return $this->_Layout;
	}

	/**
	 * @param string $Layout
	 * @return Html
	 */
	public function setLayout( string $Layout ): Base
	{
		$this->_Layout = $Layout;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getController(): string
	{
		return $this->_Controller;
	}

	/**
	 * @param string $Controller
	 * @return Html
	 */
	public function setController( string $Controller ): Base
	{
		$this->_Controller = $Controller;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPage(): string
	{
		return $this->_Page;
	}

	/**
	 * @param string $Page
	 * @return Html
	 */
	public function setPage( string $Page ): Base
	{
		$this->_Page = $Page;
		return $this;
	}

}
