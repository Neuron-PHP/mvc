<?php

namespace Neuron\Mvc\Requests;

use Neuron\Log\Log;
use Neuron\Routing\RequestMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Request
 */
class Request
{
	private string $_Name;
	private int   $_RequestMethod;
	private array $_Headers;
	private array $_Parameters;
	private array $_Errors = [];

	/**
	 * Request constructor.
	 */
	public function __construct()
	{
	}

	public function getErrors(): array
	{
		return $this->_Errors;
	}

	/**
	 * @return array
	 */
	public function getJsonPayload(): array
	{
		$Input = file_get_contents( 'php://input' );
		$Result = json_decode( $Input, true ) ?? [];

		if( json_last_error() !== JSON_ERROR_NONE )
		{
			return [];
		}

		return $Result;
	}

	/**
	 * @return int
	 */
	public function getRequestMethod(): int
	{
		return $this->_RequestMethod;
	}

	/**
	 * @return array
	 */
	public function getHeaders(): array
	{
		return $this->_Headers;
	}

	/**
	 * @return array
	 */
	public function getParameters(): array
	{
		return $this->_Parameters;
	}

	/**
	 * @param string $Name
	 * @return Parameter|null
	 */
	public function getParameter( string $Name ): ?Parameter
	{
		return $this->_Parameters[ $Name ] ?? null;
	}

	/**
	 * @param string $FileName
	 * @return void
	 */
	public function loadFile( string $FileName ): void
	{
		$Name = pathinfo( $FileName )[ 'filename' ];
		$Data = Yaml::parseFile( $FileName );

		$this->_RequestMethod = RequestMethod::getType( $Data[ 'request' ][ 'method' ] );
		$this->_Headers       = $Data[ 'request' ][ 'headers' ];

		$this->loadData( $Name, $Data[ 'request' ] );
	}

	/**
	 * @param string $Name
	 * @param array $Request
	 */
	protected function loadData( string $Name, array $Request ) : void
	{
		$this->_Name = $Name;
		$this->_Parameters = [];

		foreach( $Request[ 'properties' ] as $Name => $Parameter )
		{
			$P = new Parameter();
			$P->setName( $Name );

			if( isset( $Parameter[ 'required' ] ) )
			{
				$P->setRequired( $Parameter[ 'required' ] );
			}

			$P->setType( $Parameter[ 'type' ] );

			if( $P->getType() === 'object' )
			{
				$Request = new Request();
				$Request->loadData( $this->_Name.':'.$Name, $Parameter );
				$P->setValue( $Request );
			}

			if( isset( $Parameter[ 'minLength' ] ) )
			{
				$P->setMinLength( $Parameter[ 'minLength' ] );
			}

			if( isset( $Parameter[ 'maxLength' ] ) )
			{
				$P->setMaxLength( $Parameter[ 'maxLength' ] );
			}

			if( isset( $Parameter[ 'minimum' ] ) )
			{
				$P->setMinValue( $Parameter[ 'minimum' ] );
			}

			if( isset( $Parameter[ 'maximum' ] ) )
			{
				$P->setMaxValue( $Parameter[ 'maximum' ] );
			}

			if( isset( $Parameter[ 'pattern' ] ) )
			{
				$P->setPattern( $Parameter[ 'pattern' ] );
			}

			$this->_Parameters[ $P->getName() ] = $P;
		}
	}

	/**
	 * @param array $Payload
	 * @throws ValidationException
	 */
	public function processPayload( array $Payload ): void
	{
		$this->_Errors = [];

		foreach( $this->_Parameters as $Parameter )
		{
			if( isset( $Payload[ $Parameter->getName() ] ) )
			{
				if( $Parameter->getType() === 'object' )
				{
					try
					{
						$Parameter->getValue()->processPayload( $Payload[ $Parameter->getName() ] );
					}
					catch( ValidationException $Exception )
					{
						Log::warning( $Exception->getMessage() );
						$this->_Errors = array_merge( $this->_Errors, $Exception->getErrors() );
					}
				}
				else
				{
					$Parameter->setValue( $Payload[ $Parameter->getName() ] );
				}
			}

			try
			{
				$Parameter->validate();
			}
			catch( \Exception $Exception )
			{
				Log::warning( $Exception->getMessage() );
				$this->_Errors[] = $Exception->getMessage();
			}
		}

		if( !empty( $this->_Errors ) )
		{
			throw new ValidationException( $this->_Name, $this->_Errors );
		}
	}
}
