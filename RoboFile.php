<?php
/**
 * @package     Joomla.Site
 * @subpackage  RoboFile
 *
 * @copyright   Copyright (C) 2005 - 2016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * This is joomla project's console command file for Robo.li task runner.
 *
 * Download robo.phar from http://robo.li/robo.phar and type in the root of the repo: $ php robo.phar
 * Or do: $ composer update, and afterwards you will be able to execute robo like $ php libraries/vendor/bin/robo
 *
 * @see         http://robo.li/
 */
require_once __DIR__ . '/vendor/autoload.php';

if (!defined('JPATH_BASE'))
{
	define('JPATH_BASE', __DIR__);
}

/**
 * Modern php task runner for Joomla! Browser Automated Tests execution
 *
 * @package  RoboFile
 *
 * @since    __DEPLOY_VERSION__
 */
class RoboFile extends \Robo\Tasks
{
	/**
	 * Path to the codeception tests folder
	 *
	 * @var   string
	 */
	private $testsPath = 'tests/';

	/**
	 * Local configuration parameters
	 *
	 * @var    array
	 * @since  __DEPLOY_VERSION__
	 */
	private $configuration = array();

	/**
	 * @var array | null
	 * @since  __DEPLOY_VERSION__
	 */
	private $suiteConfig;

	/**
	 * Path to the local CMS test folder
	 *
	 * @var    string
	 * @since  __DEPLOY_VERSION__
	 */
	protected $cmsPath = null;

	/**
	 * RoboFile constructor.
	 *
	 * @since   __DEPLOY_VERSION__
	 *
	 */
	public function __construct()
	{
		$this->configuration = $this->getConfiguration();
		$this->cmsPath       = $this->getTestingPath();

		// Set default timezone (so no warnings are generated if it is not set)
		date_default_timezone_set('UTC');
	}

	/**
	 * Get (optional) configuration from an external file
	 *
	 * @since   __DEPLOY_VERSION__
	 *
	 * @return  \stdClass|null
	 */
	public function getConfiguration()
	{
		$configurationFile = __DIR__ . '/RoboFile.ini';

		if (!file_exists($configurationFile))
		{
			$this->say("No local configuration file");

			return null;
		}

		$configuration = parse_ini_file($configurationFile);

		if ($configuration === false)
		{
			$this->say('Local configuration file is empty or wrong (check is it in correct .ini format');

			return null;
		}

		return json_decode(json_encode($configuration));
	}

	/**
	 * Get the correct CMS root path
	 *
	 * @since   __DEPLOY_VERSION__
	 *
	 * @return  string
	 */
	private function getTestingPath()
	{
		if (empty($this->configuration->cmsPath))
		{
			return $this->testsPath . 'joomla-cms';
		}

		if (!file_exists(dirname($this->configuration->cmsPath)))
		{
			$this->say("CMS path written in local configuration does not exists or is not readable");

			return $this->testsPath . 'joomla-cms';
		}

		return $this->configuration->cmsPath;
	}

	/**
	 * Creates a testing Joomla site for running the tests (use it before run:test)
	 *
	 * @param   bool $useHtaccess (1/0) Rename and enable embedded Joomla .htaccess file
	 *
	 * @since   __DEPLOY_VERSION__
	 *
	 * @return  void
	 */
	public function createTestingSite($useHtaccess = false)
	{
		// Clean old testing site
		if (is_dir($this->cmsPath))
		{
			try
			{
				$this->taskDeleteDir($this->cmsPath)->run();
			}
			catch (Exception $e)
			{
				// Sorry, we tried :(
				$this->say('Sorry, you will have to delete ' . $this->cmsPath . ' manually.');

				exit(1);
			}
		}

		$this->build();

		$exclude = ['tests', 'tests-phpunit', '.run', '.github', '.git'];

		$this->copyJoomla($this->cmsPath, $exclude);

		// Optionally change owner to fix permissions issues
		if (!empty($this->configuration->localUser))
		{
			$this->_exec('chown -R ' . $this->configuration->localUser . ' ' . $this->cmsPath);
		}

		// Optionally uses Joomla default htaccess file. Used by TravisCI
		if ($useHtaccess == true)
		{
			$this->say("Renaming htaccess.txt to .htaccess");
			$this->_copy('./htaccess.txt', $this->cmsPath . '/.htaccess');
			$this->_exec('sed -e "s,# RewriteBase /,RewriteBase joomla-cms/,g" -in-place joomla-cms/.htaccess');
		}
	}

	/**
	 * Copy the joomla installation excluding folders
	 *
	 * @param   string $dst     Target folder
	 * @param   array  $exclude Exclude list of folders
	 *
	 * @throws  Exception
	 *
	 * @since   __DEPLOY_VERSION__
	 *
	 * @return  void
	 */
	protected function copyJoomla($dst, $exclude = array())
	{
		$dir = @opendir(".");

		if (false === $dir)
		{
			throw new Exception($this, "Cannot open source directory");
		}

		if (!is_dir($dst))
		{
			mkdir($dst, 0755, true);
		}

		while (false !== ($file = readdir($dir)))
		{
			if (in_array($file, $exclude))
			{
				continue;
			}

			if (($file !== '.') && ($file !== '..'))
			{
				$srcFile  = "." . '/' . $file;
				$destFile = $dst . '/' . $file;

				if (is_dir($srcFile))
				{
					$this->_copyDir($srcFile, $destFile);
				}
				else
				{
					copy($srcFile, $destFile);
				}
			}
		}

		closedir($dir);
	}

	/**
	 * Downloads Composer
	 *
	 * @since   __DEPLOY_VERSION__
	 *
	 * @return  void
	 */
	private function getComposer()
	{
		// Make sure we have Composer
		if (!file_exists($this->testsPath . 'composer.phar'))
		{
			$this->_exec('curl -o ' . $this->testsPath . 'composer.phar  --retry 3 --retry-delay 5 -sS https://getcomposer.org/installer | php');
		}
	}

	/**
	 * Runs Selenium Standalone Server.
	 *
	 * @since   __DEPLOY_VERSION__
	 *
	 * @return  void
	 */
	public function runSelenium()
	{
		if (!$this->isWindows())
		{
			$this->_exec( "vendor/bin/selenium-server-standalone " . $this->getWebDriver() . ' >> selenium.log 2>&1 &');
		}
		else
		{
			$this->_exec("START java.exe -jar " . $this->getWebDriver() . ' vendor\joomla-projects\selenium-server-standalone\bin\selenium-server-standalone.jar ');
		}

		if ($this->isWindows())
		{
			sleep(3);
		}
		else
		{
            sleep(3);
		}
	}

	public function screenshots($opts = ['use-htaccess' => false, 'env' => 'desktop'])
	{
		$this->say("Creating Screenshots");

		$this->createScreenshotsSite();

		$this->runSelenium();

		// Make sure to run the build command to generate AcceptanceTester
		$this->_exec('php vendor/bin/codecept build');

		$pathToCodeception = 'vendor/bin/codecept';

		$this->taskCodecept($pathToCodeception)
			->arg('--steps')
			->arg('--debug')
			->arg('--fail-fast')
			->env($opts['env'])
			->arg($this->testsPath . 'screenshots/')
			->run()
			->stopOnFail();
	}

	public function screenshotsNoinstall($opts = ['use-htaccess' => false, 'env' => 'desktop'])
	{
		// Make sure to run the build command to generate AcceptanceTester
		$this->_exec('php vendor/bin/codecept build');

		$pathToCodeception = 'vendor/bin/codecept';

		$this->taskCodecept($pathToCodeception)
			->arg('--steps')
			->arg('--debug')
			->arg('--fail-fast')
			->env($opts['env'])
			->arg($this->testsPath . 'screenshots/')
			->run()
			->stopOnFail();
	}

	/**
	 * @return string
	 */
	private function createScreenshotsSite()
	{
		// Caching cloned installations locally
		$this->say('Creating joomla-cms site');

		if (!is_dir('cache') || (time() - filemtime('cache') > 60 * 60 * 24))
		{
			if (file_exists('cache'))
			{
				$this->taskDeleteDir('cache')->run();
			}

			$branch = empty($this->configuration->branch) ? 'staging' : $this->configuration->branch;

			$this->_exec("git clone -b $branch --single-branch --depth 1 https://github.com/joomla/joomla-cms.git cache");
		}

		$snapshotInstallationDir = "joomla-cms";

		// Get Joomla Clean Testing sites
		if (is_dir($snapshotInstallationDir))
		{
			try
			{
				$this->taskDeleteDir($snapshotInstallationDir)->run();
			}
			catch (Exception $e)
			{
				// Sorry, we tried :(
				$this->say('Sorry, you will have to delete ' . $snapshotInstallationDir . ' manually. ');
				exit(1);
			}
		}
		$this->_copyDir('cache', $snapshotInstallationDir);

		$this->say('Joomla snapshot site created at ' . $snapshotInstallationDir);
	}

	/**
	 * Check if local OS is Windows
	 *
	 * @return bool
	 */
	private function isWindows()
	{
		return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
	}

	/**
	 * Return the correct path for Windows
	 *
	 * @param   string  $path  - The linux path
	 *
	 * @return string
	 */
	private function getWindowsPath($path)
	{
		return str_replace('/', DIRECTORY_SEPARATOR, $path);
	}

	/**
	 * Detect the correct driver for selenium
	 *
	 * @return  string the webdriver string to use with selenium
	 *
	 * @since version
	 */
	public function getWebdriver()
	{
		$suiteConfig        = $this->getSuiteConfig();
		$codeceptMainConfig = \Codeception\Configuration::config();
		$browser            = $suiteConfig['modules']['config']['JoomlaBrowser']['browser'];

		if ($browser == 'chrome')
		{
			$driver['type'] = 'webdriver.chrome.driver';
		}
		elseif ($browser == 'firefox')
		{
			$driver['type'] = 'webdriver.gecko.driver';
		}
		elseif ($browser == 'MicrosoftEdge')
		{
			$driver['type'] = 'webdriver.edge.driver';

			// Check if we are using Windows Insider builds
			if ($suiteConfig['modules']['config']['AcceptanceHelper']['MicrosoftEdgeInsiders'])
			{
				$browser = 'MicrosoftEdgeInsiders';
			}
		}
		elseif ($browser == 'internet explorer')
		{
			$driver['type'] = 'webdriver.ie.driver';
		}

		// Check if we have a path for this browser and OS in the codeception settings
		if (isset($codeceptMainConfig['webdrivers'][$browser][$this->getOs()]))
		{
			$driverPath = $codeceptMainConfig['webdrivers'][$browser][$this->getOs()];
		}
		else
		{
			$this->yell('No driver for your browser. Check your browser in acceptance.suite.yml and the webDrivers in codeception.yml');

			// We can't do anything without a driver, exit
			exit(1);
		}

		$driver['path'] = $driverPath;

		return '-D' . implode('=', $driver);
	}

	/**
	 * Return the os name
	 *
	 * @return string
	 *
	 * @since version
	 */
	private function getOs()
	{
		$os = php_uname('s');

		if (strpos(strtolower($os), 'windows') !== false)
		{
			return 'windows';
		}

		if (strpos(strtolower($os), 'darwin') !== false)
		{
			return 'mac';
		}

		return 'linux';
	}

	/**
	 * Get the suite configuration
	 *
	 * @param string $suite
	 *
	 * @return array
	 */
	private function getSuiteConfig($suite = 'acceptance')
	{
		if (!$this->suiteConfig)
		{
			$this->suiteConfig = Symfony\Component\Yaml\Yaml::parse(file_get_contents("tests/{$suite}.suite.yml"));
		}

		return $this->suiteConfig;
	}
}
