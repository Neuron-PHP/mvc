<?php
namespace Neuron\Mvc\Requests;
use Neuron\Log\Log;

class Parameter
{
	private array $_Errors;
	private string $_Name;
	private bool $_Required;
	private string $_Type;
	private int $_MinLength;
	private int $_MaxLength;
	private int $_MinValue;
	private int $_MaxValue;
	private string $_Pattern;
	private mixed $_Value = '';


	public function __construct()
	{
		$this->_Errors		= [];
		$this->_Name		= '';
		$this->_Required	= false;
		$this->_Type		= '';
		$this->_MinLength	= 0;
		$this->_MaxLength	= 0;
		$this->_MinValue	= 0;
		$this->_MaxValue	= 0;
		$this->_Pattern	= '';
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->_Name;
	}

	/**
	 * @param string $Name
	 * @return Parameter
	 */
	public function setName( string $Name ): Parameter
	{
		$this->_Name = $Name;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function isRequired(): bool
	{
		return $this->_Required;
	}

	/**
	 * @param bool $Required
	 * @return Parameter
	 */
	public function setRequired( bool $Required ): Parameter
	{
		$this->_Required = $Required;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getType(): string
	{
		return $this->_Type;
	}

	/**
	 * @param string $Type
	 * @return Parameter
	 */
	public function setType( string $Type ): Parameter
	{
		$this->_Type = $Type;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMinLength(): int
	{
		return $this->_MinLength;
	}

	/**
	 * @param int $MinLength
	 * @return Parameter
	 */
	public function setMinLength( int $MinLength ): Parameter
	{
		$this->_MinLength = $MinLength;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMaxLength(): int
	{
		return $this->_MaxLength;
	}

	/**
	 * @param int $MaxLength
	 * @return Parameter
	 */
	public function setMaxLength( int $MaxLength ): Parameter
	{
		$this->_MaxLength = $MaxLength;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMinValue(): int
	{
		return $this->_MinValue;
	}

	/**
	 * @param int $MinValue
	 * @return Parameter
	 */
	public function setMinValue( int $MinValue ): Parameter
	{
		$this->_MinValue = $MinValue;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMaxValue(): int
	{
		return $this->_MaxValue;
	}

	/**
	 * @param int $MaxValue
	 * @return Parameter
	 */
	public function setMaxValue( int $MaxValue ): Parameter
	{
		$this->_MaxValue = $MaxValue;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPattern(): string
	{
		return $this->_Pattern;
	}

	/**
	 * @param string $Pattern
	 * @return Parameter
	 */
	public function setPattern( string $Pattern ): Parameter
	{
		$this->_Pattern = $Pattern;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getValue(): mixed
	{
		return $this->_Value;
	}

	/**
	 * @param mixed $Value
	 * @return Parameter
	 */
	public function setValue( mixed $Value ): Parameter
	{
		$this->_Value = $Value;
		return $this;
	}

	/**
	 * @throws ValidationException
	 */
	public function validate(): void
	{
		if( $this->validateRequired() && $this->_Value )
		{
			$this->validateType();
			$this->validateLength();
			$this->validateRange();
			$this->validatePattern();
		}

		if( $this->_Errors )
		{
			foreach( $this->_Errors as $Error )
			{
				Log::warning( $Error );
			}
			throw new ValidationException( $this->_Name, $this->_Errors );
		}
	}

	private function validateRequired(): bool
	{
		if( $this->_Required && !$this->_Value )
		{
			$this->_Errors[] = 'Missing required parameter: ' . $this->_Name;
			return false;
		}

		return true;
	}

	private function validateType(): bool
	{
		if( $this->_Type === 'string' && is_string( $this->_Value ) )
		{
			return true;
		}

		if( $this->_Type === 'integer' && is_int( $this->_Value ) )
		{
			return true;
		}

		if( $this->_Type === 'object' && is_object( $this->_Value ) )
		{
			return true;
		}

		$this->_Errors[] = $this->_Name.':'.$this->_Type.' Invalid type '.$this->getType( $this->_Value );

		return false;
	}

	private function validateLength(): bool
	{
		if( $this->_MinLength > 0 && strlen( $this->_Value ) < $this->_MinLength )
		{
			$this->_Errors[] = $this->_Name.':'.$this->_MinLength.' Invalid min length '.strlen( $this->_Value );
			return false;
		}

		if( $this->_MaxLength > 0 && strlen( $this->_Value ) > $this->_MaxLength )
		{
			$this->_Errors[] = $this->_Name.':'.$this->_MaxLength.' Invalid max length '.strlen( $this->_Value );
			return false;
		}

		return true;
	}

	private function validateRange(): bool
	{
		if( $this->_MinValue > 0 && $this->_Value < $this->_MinValue )
		{
			$this->_Errors[] = $this->_Name.':'.$this->_MinValue.' Invalid min value '.$this->_Value;
			return false;
		}
		elseif( $this->_MaxValue > 0 && $this->_Value > $this->_MaxValue )
		{
			$this->_Errors[] = $this->_Name.':'.$this->_MaxValue.' Invalid max value '.$this->_Value;
			return false;
		}

		return true;
	}

	private function validatePattern(): bool
	{
		if( $this->_Pattern && !preg_match( $this->_Pattern, $this->_Value ) )
		{
			$this->_Errors[] = $this->_Name.':'.$this->_Pattern.' Invalid pattern '.$this->_Value;
			return false;
		}

		return true;
	}
}