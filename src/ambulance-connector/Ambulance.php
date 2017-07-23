<?php declare(strict_types=1);

namespace wittenejdek\AmbulanceConnector;

use Nette\Caching\Cache;
use wittenejdek\AmbulanceConnector\Exception\NoMedicalCheckUpDatesException;
use wittenejdek\AmbulanceConnector\Exception\WorkplaceNotFoundException;

/**
 * Class Ambulance
 * @method afterCreateBooking(Ambulance $ambulance, int $reservationId)
 * @method afterCancelBooking(Ambulance $ambulance)
 * @method afterClientCreate(Ambulance $ambulance, int $clientId)
 */
class Ambulance extends Gateway implements IGateway
{

	/** @var array The output array */
	private $_output = [];

	protected $_days = [
		1 => "Pondělí",
		2 => "Úterý",
		3 => "Středa",
		4 => "Črvrtek",
		5 => "Pátek",
		6 => "Sobota",
		7 => "Neděle",
	];

	const
		RESULT_FULL = 3,
		RESULT_LIMIT = 2,
		RESULT_NO = 1,
		RESULT_NO_VISIT = 0;

	/** @var array|callable */
	public $afterCreateBooking = [];

	/** @var array|callable */
	public $afterCancelBooking = [];

	/** @var array|callable */
	public $afterClientCreate = [];

	/**
	 * @return array
	 */
	public function findWorkplaces()
	{
		// Cache
		$workplaceCache = $this->cache->load('workplaces');
		if ($workplaceCache === NULL) {
			$response = $this->call("vratSeznamPracovist");
			$workplaces = $response->getData("seznam");

			foreach ($workplaces as $workplace) {
				// Ignore the gynecology
				if (preg_match('/GYN/', $workplace->nazev)) {
					continue;
				}
				$examinations = [];
				foreach ($workplace->typyVysetreni as $t) {
					$examinations[] = new Examination($t["id"], $t["nazev"]);
				}
				$this->_output[] = new Workplace($workplace->id, $workplace->nazev, $examinations);
			}
			$this->cache->save('workplaces', $this->_output, [
				Cache::EXPIRE => "1 day",
			]);
		} else {
			$this->_output = $workplaceCache;
		}
		return $this->_output;
	}

	/**
	 * @param $id
	 * @return Workplace
	 * @throws WorkplaceNotFoundException
	 */
	public function getWorkplace($id)
	{

		/** @var Workplace $workplace */
		foreach ($this->findWorkplaces() as $workplace) {
			if ($workplace->getId() === $id) {
				return $workplace;
			}
		}
		throw new WorkplaceNotFoundException("Workplace " . $id . " not found");
	}

	/**
	 * @param Workplace $workplace
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @return Workplace
	 * @throws NoMedicalCheckUpDatesException
	 */
	public function findDatesByWorkplace(Workplace $workplace, \DateTime $startDate, \DateTime $endDate)
	{
		// Cache
		$hash = sha1($workplace->getId() . "." . $workplace->getControlType() . "." . $startDate->getTimestamp() . "." . $endDate->getTimestamp());
		$datesCache = $this->cache->load($hash);

		if ($datesCache === NULL) {
			$response = $this->call("vratSeznamTerminuPracoviste", $workplace->getId(), $workplace->getControlType(), $startDate->getTimestamp(), $endDate->getTimestamp());
			$dates = $response->getData("seznam");
			foreach ($dates as $date) {
				// Pouze dostupné (volné) termíny
				if ($date->stav === "V") {

					$dateFrom = $this->_createDateTimeFromTimestamp($date->datum_od);
					$dateTo = $this->_createDateTimeFromTimestamp($date->datum_do);

					$this->_output[$dateFrom->format("Y-m-d")]["title"] = $this->_days[$dateFrom->format("N")] . ", " . $dateFrom->format("d. m. Y");
					$this->_output[$dateFrom->format("Y-m-d")]["day"] = $this->_days[$dateFrom->format("N")];
					$this->_output[$dateFrom->format("Y-m-d")]["calendar"] = $date->rozden_id;
					$this->_output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["title"] = $dateFrom->format("H:i") . " - " . $dateTo->format("H:i") . " hodin";
					$this->_output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["calendar"] = $date->rozden_id;
					$this->_output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["startTime"] = $dateFrom;
					$this->_output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["endTime"] = $dateTo;
				}
			}
			if (count($this->_output) === 0) {
				throw new NoMedicalCheckUpDatesException("No medical check-up dates are planned in these date for workplace " . $workplace->getId() . " between " . $startDate->format("Y-m-d") . " to " . $endDate->format("Y-m-d"));
			}

			foreach ($this->_output as $date => $exa) {
				if (!$this->_output[$date] instanceof Workplace) {
					$this->_output[$date]["times"] = array_slice($exa["times"], 0, 3);
				}
			}
			$workplace->setDates($this->_output);

			$this->cache->save($hash, $this->_output, [
				Cache::EXPIRE => "5 minutes",
				Cache::TAGS => "dates",
			]);
		} else {
			$workplace->setDates($datesCache);
		}

		return $workplace;
	}

	/**
	 * @param Workplace $workplace
	 * @param \DateTime $startTime
	 * @param \DateTime $endTime
	 * @param $client
	 * @param $calendar
	 * @param string $text
	 * @return bool|int
	 */
	public function createBooking(Workplace $workplace, \DateTime $startTime, \DateTime $endTime, $client, $calendar, $text = '')
	{

		$response = $this->call("rezervujTermin", $client, $workplace->getId(), $calendar, $startTime->getTimestamp(), $endTime->getTimestamp(), $text);

		// Clean the cache
		$this->cache->clean([Cache::TAGS => ["dates"]]);

		if ($reservationId = (int)$response->getData("rezervace_id")) {
			//$this->afterCreateBooking($this, $reservationId);
			return $reservationId;
		} else {
			return FALSE;
		}
	}

	/**
	 * @param $reservationId
	 * @return bool
	 */
	public function cancelBooking($reservationId)
	{
		$response = $this->call("zrusRezervaciTerminu", $reservationId);

		// Clean the cache
		$this->cache->clean([Cache::TAGS => ["dates"]]);

		if ($response) {
			//$this->afterCancelBooking($this);
			return TRUE;
		} else {
			return FALSE;
		}
	}

	/**
	 * @param string $firstName
	 * @param string $lastName
	 * @param string $email
	 * @param string $phone
	 * @return bool|int
	 */
	public function createClientId($firstName, $lastName, $email, $phone = "")
	{

		$response = $this->call("zalozPacienta", $firstName, $lastName, $phone, $email, NULL, NULL, NULL);
		if ($clientId = (int)$response->getData("pacient_id")) {
			return $clientId;
		} else {
			return FALSE;
		}
	}

	/**
	 * @param $reservationId
	 * @return bool|array
	 */
	public function getResultOfMedicalExamination($reservationId)
	{
		$response = $this->call("vratVysledekProhlidky", $reservationId);
		if ($response) {
			$examination = $response->getData("vysledek");
			if (array_key_exists("note", $response->getData())) {
				$this->_output["note"] = $response->getData("note");
			} else {
				$this->_output["note"] = "";
			}
			switch ($examination) {
				case 2:
					$this->_output["result"] = self::RESULT_FULL;
					break;
				case 4:
					$this->_output["result"] = self::RESULT_LIMIT;
					break;
				case 3:
					$this->_output["result"] = self::RESULT_NO;
					break;
				default:
					$this->_output["result"] = self::RESULT_NO_VISIT;
					break;
			}
			return $this->_output;
		} else {
			return FALSE;
		}
	}

	private function _createDateTimeFromTimestamp(int $timestamp): \DateTime
	{
		$dateTime = new \DateTime();
		$dateTime->setTimestamp((int)$timestamp);
		return $dateTime;
	}

}
