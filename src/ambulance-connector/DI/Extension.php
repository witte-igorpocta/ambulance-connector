<?php

namespace igorpocta\AmbulanceConnector\DI;

use igorpocta\AmbulanceConnector\Exception\Exception;
use igorpocta\AmbulanceConnector\Gateway;
use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;

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
