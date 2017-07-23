<?php declare(strict_types=1);

namespace wittenejdek\AmbulanceConnector;

class Workplace
{

	/** @var int */
	private $id;

	/** @var string */
	private $title;

	/** @var string */
	private $controlType;

	/** @var array */
	private $examinations = [];

	/** @var array */
	private $dates = [];

	public function __construct($id, $title = "", $examinations = [], $controlType = '001')
	{
		$this->id = (int)$id;
		$this->title = $title;
		$this->examinations = $examinations;
		$this->controlType = (string)$controlType;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getExaminations(): array
	{
		return $this->examinations;
	}

	public function getControlType(): string
	{
		return $this->controlType;
	}

	public function getDates(): array
	{
		return $this->dates;
	}

	public function setDates(array $dates = [])
	{
		$this->dates = $dates;
	}

}
