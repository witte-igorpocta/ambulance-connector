<?php

namespace wittenejdek\AmbulanceConnector\DI;

use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use wittenejdek\AmbulanceConnector\Ambulance;
use wittenejdek\AmbulanceConnector\Exception\Exception;
use wittenejdek\AmbulanceConnector\Gateway;

class Extension extends CompilerExtension
{
	/** @var array */
	private $defaults = [
		'tempDir' => '%tempDir%',
		'location' => 'http://www.nemosnet.cz/webobj-ws/',
		'uri' => 'http://www.nemosnet.cz/webobj-ws/',
		'token' => NULL,
	];

	public function loadConfiguration()
	{
		$config = $this->getConfig($this->defaults);
		$builder = $this->getContainerBuilder();

		if ($config['token'] === NULL) {
			throw new Exception('Token must be set');
		}

		$builder->addDefinition($this->prefix('gateway'))
			->setClass(Gateway::class, [
				$config['tempDir'],
				$config['token'],
				$config['location'],
				$config['uri'],
			]);

		$builder->addDefinition($this->prefix('gateway'))
			->setClass(Ambulance::class, [
				$config['tempDir'],
				$config['token'],
				$config['location'],
				$config['uri'],
			]);
	}

	/**
	 * @param Configurator $configurator
	 */
	public static function register(Configurator $configurator)
	{
		$configurator->onCompile[] = function ($config, Compiler $compiler) {
			$compiler->addExtension('AmbulanceConnector', new Extension());
		};
	}

}
