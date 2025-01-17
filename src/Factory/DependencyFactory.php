<?php

namespace Friendica\Factory;

use Friendica\App;
use Friendica\Core\Config\Cache\PConfigCache;
use Friendica\Core\L10n\L10n;
use Friendica\Factory;
use Friendica\Util\BasePath;
use Friendica\Util\BaseURL;
use Friendica\Util\ConfigFileLoader;

class DependencyFactory
{
	/**
	 * Setting all default-dependencies of a friendica execution
	 *
	 * @param string $channel   The channel of this execution
	 * @param string $directory The base directory
	 * @param bool   $isBackend True, if it's a backend execution, otherwise false (Default true)
	 *
	 * @return App The application
	 *
	 * @throws \Exception
	 */
	public static function setUp($channel, $directory, $isBackend = true)
	{
		$basePath = BasePath::create($directory, $_SERVER);
		$mode = new App\Mode($basePath);
		$router = new App\Router();
		$configLoader = new ConfigFileLoader($basePath, $mode);
		$configCache = Factory\ConfigFactory::createCache($configLoader);
		$profiler = Factory\ProfilerFactory::create($configCache);
		$database = Factory\DBFactory::init($configCache, $profiler, $_SERVER);
		$configModel = new \Friendica\Model\Config\Config($database);
		$config = Factory\ConfigFactory::createConfig($configCache, $configModel);
		// needed to call PConfig::init()
		$pconfigModel = new \Friendica\Model\Config\PConfig($database);
		Factory\ConfigFactory::createPConfig($configCache, new PConfigCache(), $pconfigModel);
		$logger = Factory\LoggerFactory::create($channel, $database, $config, $profiler);
		Factory\LoggerFactory::createDev($channel, $config, $profiler);
		$baseURL = new BaseURL($config, $_SERVER);
		$l10n = new L10n(L10n::detectLanguage($config->get('system', 'language')),
			$database,
			$logger);

		return new App($database, $config, $mode, $router, $baseURL, $logger, $profiler, $l10n, $isBackend);
	}
}
