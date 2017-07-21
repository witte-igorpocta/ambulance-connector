<?php

namespace wittenejdek\AmbulanceConnector;

use wittenejdek\AmbulanceConnector\Exception\InvalidArgumentException;

class Response implements IResponse
{
	/** @var int */
	private $_status;

	/** @var string */
	private $_message;

	/** @var array */
	private $_data = [];

	public function __construct($status = 0, $message = "", $data = [])
	{
		$this->_status = (int)$status;
		$this->_message = (string)$message;
		$this->_data = $data;
	}

	public function getStatus(): int
	{
		return $this->_status;
	}

	public function getMessage(): string
	{
		return $this->_message;
	}

	/**
	 * @param null $key
	 * @return array|mixed
	 * @throws InvalidArgumentException
	 */
	public function getData($key = NULL)
	{
		if($key) {

			if(array_key_exists($key, $this->_data)) {
				return $this->_data[$key];
			} else {
				throw new InvalidArgumentException("This key is not exists in data of response.");
			}
		} else {
			return $this->_data;
		}
	}

}
