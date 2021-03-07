<?php

/**
 * This file is part of the CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CodeIgniter\Test;

use CodeIgniter\CodeIgniter;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Events\Events;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\Mock\MockCodeIgniter;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\Mock\MockSession;
use Config\App;
use Config\Autoload;
use Config\Modules;
use Config\Services;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Framework test case for PHPUnit.
 */
abstract class CIUnitTestCase extends TestCase
{
	use ReflectionHelper;

	/**
	 * @var CodeIgniter
	 */
	protected $app;

	/**
	 * Methods to run during setUp.
	 *
	 * @var array of methods
	 */
	protected $setUpMethods = [
		'resetFactories',
		'mockCache',
		'mockEmail',
		'mockSession',
	];

	/**
	 * Methods to run during tearDown.
	 *
	 * @var array of methods
	 */
	protected $tearDownMethods = [];

	//--------------------------------------------------------------------
	// Database Properties
	//--------------------------------------------------------------------

	/**
	 * Should run db migration?
	 *
	 * @var boolean
	 */
	protected $migrate = true;

	/**
	 * Should run db migration only once?
	 *
	 * @var boolean
	 */
	protected $migrateOnce = false;

	/**
	 * Should run seeding only once?
	 *
	 * @var boolean
	 */
	protected $seedOnce = false;

	/**
	 * Should the db be refreshed before test?
	 *
	 * @var boolean
	 */
	protected $refresh = true;

	/**
	 * The seed file(s) used for all tests within this test case.
	 * Should be fully-namespaced or relative to $basePath
	 *
	 * @var string|array
	 */
	protected $seed = '';

	/**
	 * The path to the seeds directory.
	 * Allows overriding the default application directories.
	 *
	 * @var string
	 */
	protected $basePath = SUPPORTPATH . 'Database';

	/**
	 * The namespace(s) to help us find the migration classes.
	 * Empty is equivalent to running `spark migrate -all`.
	 * Note that running "all" runs migrations in date order,
	 * but specifying namespaces runs them in namespace order (then date)
	 *
	 * @var string|array|null
	 */
	protected $namespace = 'Tests\Support';

	/**
	 * The name of the database group to connect to.
	 * If not present, will use the defaultGroup.
	 *
	 * @var string
	 */
	protected $DBGroup = 'tests';

	/**
	 * Our database connection.
	 *
	 * @var BaseConnection
	 */
	protected $db;

	/**
	 * Migration Runner instance.
	 *
	 * @var MigrationRunner|mixed
	 */
	protected $migrations;

	/**
	 * Seeder instance
	 *
	 * @var Seeder
	 */
	protected $seeder;

	/**
	 * Stores information needed to remove any
	 * rows inserted via $this->hasInDatabase();
	 *
	 * @var array
	 */
	protected $insertCache = [];

	//--------------------------------------------------------------------
	// Staging
	//--------------------------------------------------------------------

	/**
	 * Load the helpers.
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		helper(['url', 'test']);
	}

	protected function setUp(): void
	{
		parent::setUp();

		if (! $this->app) // @phpstan-ignore-line
		{
			$this->app = $this->createApplication();
		}

		foreach ($this->setUpMethods as $method)
		{
			$this->$method();
		}

		// Check for methods added by DatabaseTestTrait
		if (isset($this->setUpMethodsDatabase) && is_array($this->setUpMethodsDatabase))
		{
			foreach ($this->setUpMethodsDatabase as $method)
			{
				$this->$method();
			}
		}
	}

	protected function tearDown(): void
	{
		parent::tearDown();

		foreach ($this->tearDownMethods as $method)
		{
			$this->$method();
		}

		// Check for methods added by DatabaseTestTrait
		if (isset($this->tearDownMethodsDatabase) && is_array($this->tearDownMethodsDatabase))
		{
			foreach ($this->tearDownMethodsDatabase as $method)
			{
				$this->$method();
			}
		}
	}

	//--------------------------------------------------------------------
	// Mocking
	//--------------------------------------------------------------------

	/**
	 * Resets shared instanced for all Factories components
	 */
	protected function resetFactories()
	{
		Factories::reset();
	}

	/**
	 * Resets shared instanced for all Services
	 */
	protected function resetServices()
	{
		Services::reset();
	}

	/**
	 * Injects the mock Cache driver to prevent filesystem collisions
	 */
	protected function mockCache()
	{
		Services::injectMock('cache', new MockCache());
	}

	/**
	 * Injects the mock email driver so no emails really send
	 */
	protected function mockEmail()
	{
		Services::injectMock('email', new MockEmail(config('Email')));
	}

	/**
	 * Injects the mock session driver into Services
	 */
	protected function mockSession()
	{
		$_SESSION = [];

		$config  = config('App');
		$session = new MockSession(new ArrayHandler($config, '0.0.0.0'), $config);

		Services::injectMock('session', $session);
	}

	//--------------------------------------------------------------------
	// Assertions
	//--------------------------------------------------------------------

	/**
	 * Custom function to hook into CodeIgniter's Logging mechanism
	 * to check if certain messages were logged during code execution.
	 *
	 * @param string      $level
	 * @param string|null $expectedMessage
	 *
	 * @return boolean
	 * @throws Exception
	 */
	public function assertLogged(string $level, $expectedMessage = null)
	{
		$result = TestLogger::didLog($level, $expectedMessage);

		$this->assertTrue($result);
		return $result;
	}

	/**
	 * Hooks into CodeIgniter's Events system to check if a specific
	 * event was triggered or not.
	 *
	 * @param string $eventName
	 *
	 * @return boolean
	 * @throws Exception
	 */
	public function assertEventTriggered(string $eventName): bool
	{
		$found     = false;
		$eventName = strtolower($eventName);

		foreach (Events::getPerformanceLogs() as $log)
		{
			if ($log['event'] !== $eventName)
			{
				continue;
			}

			$found = true;
			break;
		}

		$this->assertTrue($found);
		return $found;
	}

	/**
	 * Hooks into xdebug's headers capture, looking for a specific header
	 * emitted
	 *
	 * @param string  $header     The leading portion of the header we are looking for
	 * @param boolean $ignoreCase
	 *
	 * @throws Exception
	 */
	public function assertHeaderEmitted(string $header, bool $ignoreCase = false): void
	{
		$found = false;

		if (! function_exists('xdebug_get_headers'))
		{
			$this->markTestSkipped('XDebug not found.');
		}

		foreach (xdebug_get_headers() as $emitted)
		{
			$found = $ignoreCase ?
					(stripos($emitted, $header) === 0) :
					(strpos($emitted, $header) === 0);
			if ($found)
			{
				break;
			}
		}

		$this->assertTrue($found, "Didn't find header for {$header}");
	}

	/**
	 * Hooks into xdebug's headers capture, looking for a specific header
	 * emitted
	 *
	 * @param string  $header     The leading portion of the header we don't want to find
	 * @param boolean $ignoreCase
	 *
	 * @throws Exception
	 */
	public function assertHeaderNotEmitted(string $header, bool $ignoreCase = false): void
	{
		$found = false;

		if (! function_exists('xdebug_get_headers'))
		{
			$this->markTestSkipped('XDebug not found.');
		}

		foreach (xdebug_get_headers() as $emitted)
		{
			$found = $ignoreCase ?
					(stripos($emitted, $header) === 0) :
					(strpos($emitted, $header) === 0);
			if ($found)
			{
				break;
			}
		}

		$success = ! $found;
		$this->assertTrue($success, "Found header for {$header}");
	}

	/**
	 * Custom function to test that two values are "close enough".
	 * This is intended for extended execution time testing,
	 * where the result is close but not exactly equal to the
	 * expected time, for reasons beyond our control.
	 *
	 * @param integer $expected
	 * @param mixed   $actual
	 * @param string  $message
	 * @param integer $tolerance
	 *
	 * @throws Exception
	 */
	public function assertCloseEnough(int $expected, $actual, string $message = '', int $tolerance = 1)
	{
		$difference = abs($expected - (int) floor($actual));

		$this->assertLessThanOrEqual($tolerance, $difference, $message);
	}

	/**
	 * Custom function to test that two values are "close enough".
	 * This is intended for extended execution time testing,
	 * where the result is close but not exactly equal to the
	 * expected time, for reasons beyond our control.
	 *
	 * @param mixed   $expected
	 * @param mixed   $actual
	 * @param string  $message
	 * @param integer $tolerance
	 *
	 * @return void|boolean
	 * @throws Exception
	 */
	public function assertCloseEnoughString($expected, $actual, string $message = '', int $tolerance = 1)
	{
		$expected = (string) $expected;
		$actual   = (string) $actual;
		if (strlen($expected) !== strlen($actual))
		{
			return false;
		}

		try
		{
			$expected   = (int) substr($expected, -2);
			$actual     = (int) substr($actual, -2);
			$difference = abs($expected - $actual);

			$this->assertLessThanOrEqual($tolerance, $difference, $message);
		}
		catch (Exception $e)
		{
			return false;
		}
	}

	//--------------------------------------------------------------------
	// Database
	//--------------------------------------------------------------------

	/**
	 * Asserts that records that match the conditions in $where do
	 * not exist in the database.
	 *
	 * @param string $table
	 * @param array  $where
	 *
	 * @return void
	 */
	public function dontSeeInDatabase(string $table, array $where)
	{
		$count = $this->db->table($table)
						  ->where($where)
						  ->countAllResults();

		$this->assertTrue($count === 0, 'Row was found in database');
	}

	/**
	 * Asserts that records that match the conditions in $where DO
	 * exist in the database.
	 *
	 * @param string $table
	 * @param array  $where
	 *
	 * @return void
	 * @throws DatabaseException
	 */
	public function seeInDatabase(string $table, array $where)
	{
		$count = $this->db->table($table)
						  ->where($where)
						  ->countAllResults();

		$this->assertTrue($count > 0, 'Row not found in database: ' . $this->db->showLastQuery());
	}

	/**
	 * Fetches a single column from a database row with criteria
	 * matching $where.
	 *
	 * @param string $table
	 * @param string $column
	 * @param array  $where
	 *
	 * @return boolean
	 * @throws DatabaseException
	 */
	public function grabFromDatabase(string $table, string $column, array $where)
	{
		$query = $this->db->table($table)
						  ->select($column)
						  ->where($where)
						  ->get();

		$query = $query->getRow();

		return $query->$column ?? false;
	}

	/**
	 * Inserts a row into to the database. This row will be removed
	 * after the test has run.
	 *
	 * @param string $table
	 * @param array  $data
	 *
	 * @return boolean
	 */
	public function hasInDatabase(string $table, array $data)
	{
		$this->insertCache[] = [
			$table,
			$data,
		];

		return $this->db->table($table)
						->insert($data);
	}

	/**
	 * Asserts that the number of rows in the database that match $where
	 * is equal to $expected.
	 *
	 * @param integer $expected
	 * @param string  $table
	 * @param array   $where
	 *
	 * @return void
	 * @throws DatabaseException
	 */
	public function seeNumRecords(int $expected, string $table, array $where)
	{
		$count = $this->db->table($table)
						  ->where($where)
						  ->countAllResults();

		$this->assertEquals($expected, $count, 'Wrong number of matching rows in database.');
	}

	//--------------------------------------------------------------------
	// Utility
	//--------------------------------------------------------------------

	/**
	 * Loads up an instance of CodeIgniter
	 * and gets the environment setup.
	 *
	 * @return CodeIgniter
	 */
	protected function createApplication()
	{
		// Initialize the autoloader.
		Services::autoloader()->initialize(new Autoload(), new Modules());

		$app = new MockCodeIgniter(new App());
		$app->initialize();

		return $app;
	}

	/**
	 * Return first matching emitted header.
	 *
	 * @param string  $header     Identifier of the header of interest
	 * @param boolean $ignoreCase
	 *
	 * @return string|null The value of the header found, null if not found
	 */
	protected function getHeaderEmitted(string $header, bool $ignoreCase = false): ?string
	{
		$found = false;

		if (! function_exists('xdebug_get_headers'))
		{
			$this->markTestSkipped('XDebug not found.');
		}

		foreach (xdebug_get_headers() as $emitted)
		{
			$found = $ignoreCase ?
					(stripos($emitted, $header) === 0) :
					(strpos($emitted, $header) === 0);
			if ($found)
			{
				return $emitted;
			}
		}

		return null;
	}
}
