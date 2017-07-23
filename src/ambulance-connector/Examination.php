<?php declare(strict_types=1);

namespace wittenejdek\AmbulanceConnector;

class Examination
{

	/** @var string */
	private $id;

	/** @var string */
	private $title;

	public function __construct($id, $title)
	{
		$this->id = $id;
		$this->title = (string)$title;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

}
