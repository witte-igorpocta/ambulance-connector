<?php declare(strict_types=1);

namespace wittenejdek\AmbulanceConnector;

use Nette\Caching\Cache;
use Nette\Caching\IStorage;
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

	/** @var array|callable */
	public $nonReducesWorkplaces = [
		256, // Nejdek
	];

	/** @var IStorage */
	protected $storage;

	/** @var Cache */
	protected $cache;

	public function __construct($tempDir = NULL, $token = NULL, $location = NULL, $uri = NULL, IStorage $storage)
	{
		parent::__construct($tempDir, $token, $location, $uri);
		$this->storage = $storage;
		$this->cache = new Cache($this->storage, 'ambulance-connector');
	}

	/**
	 * @return array
	 */
	public function findWorkplaces()
	{
		$output = [];

		// Cache
		$workplaceCache = $this->cache->load('workplaces');
		if ($workplaceCache === NULL) {
			if ($response = $this->call("vratSeznamPracovist")) {
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
					$wp = new Workplace($workplace->id, $workplace->nazev, $examinations);
					$wp->setReduce(!in_array($workplace->id, $this->nonReducesWorkplaces));
					$output[] = $wp;
				}
				$this->cache->save('workplaces', $output, [
					Cache::EXPIRE => "1 day",
				]);
			}
		} else {
			$output = $workplaceCache;
		}
		return $output;
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
			if ($workplace->getId() === (int)$id) {
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
		$output = [];
		// Cache
		$hash = sha1($workplace->getId() . "." . $workplace->getControlType() . "." . $startDate->getTimestamp() . "." . $endDate->getTimestamp());
		$datesCache = $this->cache->load($hash);

		if ($datesCache === NULL) {
			if ($response = $this->call("vratSeznamTerminuPracoviste", $workplace->getId(), $workplace->getControlType(), $startDate->getTimestamp(), $endDate->getTimestamp())) {
				$dates = $response->getData("seznam");
				foreach ($dates as $date) {
					// Pouze dostupné (volné) termíny
					if ($date->stav === "V") {

						$dateFrom = $this->_createDateTimeFromTimestamp($date->datum_od);
						$dateTo = $this->_createDateTimeFromTimestamp($date->datum_do);

						$output[$dateFrom->format("Y-m-d")]["title"] = $this->_days[$dateFrom->format("N")] . ", " . $dateFrom->format("d. m. Y");
						$output[$dateFrom->format("Y-m-d")]["day"] = $this->_days[$dateFrom->format("N")];
						$output[$dateFrom->format("Y-m-d")]["calendar"] = (int)$date->rozden_id;
						$output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["title"] = $dateFrom->format("H:i") . " - " . $dateTo->format("H:i") . " hodin";
						$output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["calendar"] = (int)$date->rozden_id;
						$output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["startTime"] = $dateFrom;
						$output[$dateFrom->format("Y-m-d")]["times"][$dateFrom->format("H-i")]["endTime"] = $dateTo;
					}
				}
				if (count($output) === 0) {
					throw new NoMedicalCheckUpDatesException("No medical check-up dates are planned in these date for workplace " . $workplace->getId() . " between " . $startDate->format("Y-m-d") . " to " . $endDate->format("Y-m-d"));
				}

				foreach ($output as $date => $exa) {
					if (!$output[$date] instanceof Workplace) {
						$output[$date]["times"] = $workplace->isReduce() ? array_slice($exa["times"], 0, 3) : $exa["times"];
					}
				}
				$workplace->setDates($output);

				$this->cache->save($hash, $output, [
					Cache::EXPIRE => "5 minutes",
					Cache::TAGS => "dates",
				]);
			}
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
	public function createBooking(Workplace $workplace, $startTime, $endTime, $client, $calendar, $text = '')
	{
		$startTime = $startTime instanceof \DateTime ? $startTime->getTimestamp() : $startTime;
		$endTime = $endTime instanceof \DateTime ? $endTime->getTimestamp() : $endTime;

		if ($response = $this->call("rezervujTermin", $client, $workplace->getId(), $calendar, $startTime, $endTime, $text)) {
			// Clean the cache
			$this->cache->clean([Cache::TAGS => ["dates"]]);

			if ($reservationId = (int)$response->getData("rezervace_id")) {
				//$this->afterCreateBooking($this, $reservationId);
				return $reservationId;
			}
		}
		return FALSE;
	}

	/**
	 * @param $reservationId
	 * @return bool
	 */
	public function cancelBooking($reservationId)
	{
		if ($response = $this->call("zrusRezervaciTerminu", $reservationId)) {
			// Clean the cache
			$this->cache->clean([Cache::TAGS => ["dates"]]);

			if ($response) {
				//$this->afterCancelBooking($this);
				return TRUE;
			}
		}
		return FALSE;
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
		if ($response = $this->call("zalozPacienta", $firstName, $lastName, $phone, $email, NULL, NULL, NULL)) {
			if ($clientId = (int)$response->getData("pacient_id")) {
				return $clientId;
			}
		}
		return FALSE;
	}

	/**
	 * @param $reservationId
	 * @return bool|array
	 */
	public function getResultOfMedicalExamination($reservationId)
	{
		$output = [];
		$response = $this->call("vratVysledekProhlidky", $reservationId);
		if ($response) {
			$examination = $response->getData("vysledek");
			if (array_key_exists("note", $response->getData())) {
				$output["note"] = $response->getData("note");
			} else {
				$output["note"] = "";
			}
			switch ($examination) {
				case 2:
					$output["result"] = self::RESULT_FULL;
					break;
				case 4:
					$output["result"] = self::RESULT_LIMIT;
					break;
				case 3:
					$output["result"] = self::RESULT_NO;
					break;
				default:
					$output["result"] = self::RESULT_NO_VISIT;
					break;
			}
			return $output;
		}
		return FALSE;
	}

	private function _createDateTimeFromTimestamp(int $timestamp): \DateTime
	{
		$dateTime = new \DateTime();
		$dateTime->setTimestamp((int)$timestamp);
		return $dateTime;
	}

}
