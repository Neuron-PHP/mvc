<?php
/**
 * Creates an HTML based view.
 */
namespace Neuron\Mvc\Views;

use Neuron\Patterns\Registry;

/**
 * Generate html output by combining
 * a layout with a view and data.
 */
class Html implements IView
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
	public function setLayout( string $Layout ): Html
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
	public function setController( string $Controller ): Html
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
	public function setPage( string $Page ): Html
	{
		$this->_Page = $Page;
		return $this;
	}

	/**
	 * @param array $Data
	 * @return string html output
	 * @throws NotFoundException
	 *
	 * Outputs the html data from the layout and view.
	 */
	public function render( array $Data ): string
	{
		$Path = Registry::getInstance()
									->get( "Views.Path" );

		if( !$Path )
		{
			$Path = "../resources/views";
		}

		$View = "$Path/{$this->getController()}/{$this->getPage()}.php";

		if( !file_exists( $View ) )
		{
			throw new NotFoundException( "View notfound: $View" );
		}

		extract( $Data );

		$Layout = "$Path/layouts/{$this->getLayout()}.php";

		if( !file_exists( $Layout ) )
		{
			throw new NotFoundException( "View notfound: $Layout" );
		}

		ob_start();
		require( $Layout );
		$Page = ob_get_contents();
		ob_end_clean();

		return $Page;
	}
}
