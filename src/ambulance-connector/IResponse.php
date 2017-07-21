<?php

namespace igorpocta\AmbulanceConnector;

use igorpocta\AmbulanceConnector\Exception\InvalidArgumentException;

interface IResponse {

	/**
	 * @return int
	 */
	public function getStatus(): int;

	/**
	 * @return string
	 */
	public function getMessage(): string;

	/**
	 * @param null $key
	 * @return array|mixed
	 * @throws InvalidArgumentException
	 */
	public function getData($key);

}
