<?php
namespace Neuron\Mvc\Requests;
use Neuron\Core\Exceptions\Validation;
use Neuron\Log\Log;
use Neuron\Validation\IsArray;
use Neuron\Validation\IsBoolean;
use Neuron\Validation\IsCurrency;
use Neuron\Validation\IsDate;
use Neuron\Validation\IsDateTime;
use Neuron\Validation\IsEin;
use Neuron\Validation\IsEmail;
use Neuron\Validation\IsFloatingPoint;
use Neuron\Validation\IsInteger;
use Neuron\Validation\IsName;
use Neuron\Validation\IsObject;
use Neuron\Validation\IsPhoneNumber;
use Neuron\Validation\IsString;
use Neuron\Validation\IsUpc;
use Neuron\Validation\IsUrl;
use Neuron\Validation\IsUuid;

class Parameter
{
	private array  $_errors;
	private string $_name;
	private bool   $_required;
	private string $_type;
	private int    $_minLength;
	private int    $_maxLength;
	private int    $_minValue;
	private int    $_maxValue;
	private string $_pattern;
	private mixed  $_value = '';
	private array  $_validators;


	public function __construct()
	{
		$this->_errors		= [];
		$this->_name		= '';
		$this->_required	= false;
		$this->_type		= '';
		$this->_minLength	= 0;
		$this->_maxLength	= 0;
		$this->_minValue	= 0;
		$this->_maxValue	= 0;
		$this->_pattern	= '';

		$intlPhoneNumber = new IsPhoneNumber();
		$intlPhoneNumber->setType( IsPhoneNumber::INTERNATIONAL );
		
		$this->_validators = [
			'array'					=> new IsArray(),
			'boolean'				=> new IsBoolean(),
			'currency'				=> new IsCurrency(),
			'date'					=> new IsDate(),
			'date_time'				=> new IsDateTime(),
			'ein'						=> new IsEin(),
			'email'					=> new IsEmail(),
			'float'					=> new IsFloatingPoint(),
			'integer'				=> new IsInteger(),
			'ip_address'			=> new IsInteger(),
			'name'					=> new IsName(),
			'numeric'				=> new IsPhoneNumber(),
			'object'					=> new IsObject(),
			'string'					=> new IsString(),
			'time'					=> new IsDateTime(),
			'upc'						=> new IsUpc(),
			'uuid'					=> new IsUuid(),
			'url'						=> new IsUrl(),
			'us_phone_number'		=> new IsPhoneNumber(),
			'intl_phone_number' 	=> $intlPhoneNumber
		];
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * @param string $name
	 * @return Parameter
	 */
	public function setName( string $name ): Parameter
	{
		$this->_name = $name;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function isRequired(): bool
	{
		return $this->_required;
	}

	/**
	 * @param bool $required
	 * @return Parameter
	 */
	public function setRequired( bool $required ): Parameter
	{
		$this->_required = $required;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * @param string $type
	 * @return Parameter
	 */
	public function setType( string $type ): Parameter
	{
		$this->_type = $type;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMinLength(): int
	{
		return $this->_minLength;
	}

	/**
	 * @param int $minLength
	 * @return Parameter
	 */
	public function setMinLength( int $minLength ): Parameter
	{
		$this->_minLength = $minLength;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMaxLength(): int
	{
		return $this->_maxLength;
	}

	/**
	 * @param int $maxLength
	 * @return Parameter
	 */
	public function setMaxLength( int $maxLength ): Parameter
	{
		$this->_maxLength = $maxLength;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMinValue(): int
	{
		return $this->_minValue;
	}

	/**
	 * @param int $minValue
	 * @return Parameter
	 */
	public function setMinValue( int $minValue ): Parameter
	{
		$this->_minValue = $minValue;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getMaxValue(): int
	{
		return $this->_maxValue;
	}

	/**
	 * @param int $maxValue
	 * @return Parameter
	 */
	public function setMaxValue( int $maxValue ): Parameter
	{
		$this->_maxValue = $maxValue;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPattern(): string
	{
		return $this->_pattern;
	}

	/**
	 * @param string $pattern
	 * @return Parameter
	 */
	public function setPattern( string $pattern ): Parameter
	{
		$this->_pattern = $pattern;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getValue(): mixed
	{
		return $this->_value;
	}

	/**
	 * @param mixed $value
	 * @return Parameter
	 */
	public function setValue( mixed $value ): Parameter
	{
		$this->_value = $value;
		return $this;
	}

	/**
	 * @throws Validation
	 */
	public function validate(): void
	{
		if( $this->validateRequired() && $this->_value )
		{
			$this->validateType();
			$this->validateLength();
			$this->validateRange();
			$this->validatePattern();
		}

		if( $this->_errors )
		{
			foreach( $this->_errors as $error )
			{
				Log::warning( $error );
			}
			throw new Validation( $this->_name, $this->_errors );
		}
	}

	private function validateRequired(): bool
	{
		if( $this->_required && !$this->_value )
		{
			$this->_errors[] = 'Missing required parameter: ' . $this->_name;
			return false;
		}

		return true;
	}

	private function validateType(): bool
	{
		$validator = $this->_validators[ $this->_type ];

		if( !$validator )
		{
			$this->_errors[] = $this->_name.':'.$this->_type.' Invalid type specified '.$this->getType();
			return false;
		}

		if( !$validator->isValid( $this->_value ) )
		{
			$value = is_string( $this->_value ) ? $this->_value : gettype( $this->_value );
			$message = $this->_name.':'.$this->_type.' Invalid value '.$value;
			$this->_errors[] = $message;
			return false;
		}

		return true;
	}

	private function validateLength(): bool
	{
		if( $this->_minLength > 0 && strlen( $this->_value ) < $this->_minLength )
		{
			$this->_errors[] = $this->_name.':'.$this->_minLength.' Invalid min length '.strlen( $this->_value );
			return false;
		}

		if( $this->_maxLength > 0 && strlen( $this->_value ) > $this->_maxLength )
		{
			$this->_errors[] = $this->_name.':'.$this->_maxLength.' Invalid max length '.strlen( $this->_value );
			return false;
		}

		return true;
	}

	private function validateRange(): bool
	{
		if( $this->_minValue > 0 && $this->_value < $this->_minValue )
		{
			$this->_errors[] = $this->_name.':'.$this->_minValue.' Invalid min value '.$this->_value;
			return false;
		}
		elseif( $this->_maxValue > 0 && $this->_value > $this->_maxValue )
		{
			$this->_errors[] = $this->_name.':'.$this->_maxValue.' Invalid max value '.$this->_value;
			return false;
		}

		return true;
	}

	private function validatePattern(): bool
	{
		if( $this->_pattern && !preg_match( $this->_pattern, $this->_value ) )
		{
			$this->_errors[] = $this->_name.':'.$this->_pattern.' Invalid pattern '.$this->_value;
			return false;
		}

		return true;
	}
}
