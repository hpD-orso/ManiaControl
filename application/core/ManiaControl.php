<?php

namespace ManiaControl;

use ManiaControl\Admin\ActionsMenu;
use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Bills\BillManager;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Callbacks\TimerManager;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Commands\CommandManager;
use ManiaControl\Configurators\Configurator;
use ManiaControl\Files\AsynchronousFileReader;
use ManiaControl\Files\FileUtil;
use ManiaControl\Manialinks\ManialinkManager;
use ManiaControl\Maps\MapManager;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\PluginManager;
use ManiaControl\Server\Server;
use ManiaControl\Settings\SettingManager;
use ManiaControl\Statistics\StatisticManager;
use ManiaControl\Update\UpdateManager;
use Maniaplanet\DedicatedServer\Connection;
use Maniaplanet\DedicatedServer\Transport\TransportException;
use Maniaplanet\DedicatedServer\Xmlrpc\Exception;
use Maniaplanet\DedicatedServer\Xmlrpc\NotInScriptModeException;

require_once __DIR__ . '/Libs/Maniaplanet/DedicatedServer/Connection.php';
require_once __DIR__ . '/Libs/GbxDataFetcher/gbxdatafetcher.inc.php';
require_once __DIR__ . '/Libs/FML/autoload.php';
require_once __DIR__ . '/Libs/Symfony/autoload.php';
require_once __DIR__ . '/Libs/curl-easy/autoload.php';

/**
 * ManiaControl Server Controller for ManiaPlanet Server
 *
 * @author    steeffeen & kremsy
 * @copyright ManiaControl Copyright © 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class ManiaControl implements CommandListener, TimerListener {
	/*
	 * Constants
	 */
	const VERSION                     = '0.02';
	const API_VERSION                 = '2013-04-16';
	const MIN_DEDIVERSION             = '2014-04-02_18_00';
	const OS_UNIX                     = 'Unix';
	const OS_WIN                      = 'Windows';
	const CONNECT_TIMEOUT             = 50;
	const SCRIPT_TIMEOUT              = 20;
	const URL_WEBSERVICE              = 'http://ws.maniacontrol.com/';
	const SETTING_PERMISSION_SHUTDOWN = 'Shutdown ManiaControl';
	const SETTING_PERMISSION_RESTART  = 'Restart ManiaControl';

	/*
	 * Public Properties
	 */
	public $actionsMenu = null;
	public $authenticationManager = null;
	public $callbackManager = null;
	public $chat = null;
	public $config = null;
	public $configurator = null;
	/** @var Connection $client */
	public $client = null;
	public $commandManager = null;
	public $database = null;
	public $manialinkManager = null;
	public $mapManager = null;
	public $playerManager = null;
	public $pluginManager = null;
	public $server = null;
	public $settingManager = null;
	public $statisticManager = null;
	public $updateManager = null;
	public $errorHandler = null;
	public $timerManager = null;
	public $fileReader = null;
	public $billManager = null;

	/*
	 * Private Properties
	 */
	private $shutdownRequested = false;

	/**
	 * Construct ManiaControl
	 */
	public function __construct() {
		// Construct Error Handler
		$this->errorHandler = new ErrorHandler($this);

		$this->log('Loading ManiaControl v' . self::VERSION . '...');

		// Load config
		$this->config = FileUtil::loadConfig('server.xml');

		// Load ManiaControl Modules
		$this->callbackManager       = new CallbackManager($this);
		$this->timerManager          = new TimerManager($this);
		$this->database              = new Database($this);
		$this->fileReader            = new AsynchronousFileReader($this);
		$this->billManager           = new BillManager($this);
		$this->settingManager        = new SettingManager($this);
		$this->statisticManager      = new StatisticManager($this);
		$this->manialinkManager      = new ManialinkManager($this);
		$this->actionsMenu           = new ActionsMenu($this);
		$this->chat                  = new Chat($this);
		$this->commandManager        = new CommandManager($this);
		$this->server                = new Server($this);
		$this->authenticationManager = new AuthenticationManager($this);
		$this->playerManager         = new PlayerManager($this);
		$this->mapManager            = new MapManager($this);
		$this->configurator          = new Configurator($this);
		$this->pluginManager         = new PluginManager($this);
		$this->updateManager         = new UpdateManager($this);

		// Define Permission Levels
		$this->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_SHUTDOWN, AuthenticationManager::AUTH_LEVEL_SUPERADMIN);
		$this->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_RESTART, AuthenticationManager::AUTH_LEVEL_SUPERADMIN);

		// Register for commands
		$this->commandManager->registerCommandListener('version', $this, 'command_Version');
		$this->commandManager->registerCommandListener('restart', $this, 'command_Restart', true);
		$this->commandManager->registerCommandListener('shutdown', $this, 'command_Shutdown', true);

		//Check connection every 30 seconds
		$this->timerManager->registerTimerListening($this, 'checkConnection', 1000 * 30);
	}

	/**
	 * Checks connection every xxx Minutes
	 *
	 * @param $time
	 */
	public function checkConnection($time) {
		if ($this->client->getIdleTime() > 180) {
			$this->client->getServerName();
		}
	}

	/**
	 * Print a message to console and log
	 *
	 * @param string $message
	 */
	public function log($message, $stripCodes = false) {
		$date = date("d.M y H:i:s");
		if ($stripCodes) {
			$message = Formatter::stripCodes($message);
		}
		logMessage($date . ' ' . $message);
	}

	/**
	 * Get the Operating System on which ManiaControl is running
	 *
	 * @param string $compareOS
	 * @return string
	 */
	public function getOS($compareOS = null) {
		$windows = defined('PHP_WINDOWS_VERSION_MAJOR');
		if ($compareOS) {
			// Return bool whether OS equals $compareOS
			if ($compareOS == self::OS_WIN) {
				return $windows;
			}
			return !$windows;
		}
		// Return OS
		if ($windows) {
			return self::OS_WIN;
		}
		return self::OS_UNIX;
	}

	/**
	 * Handle Version Command
	 *
	 * @param array  $chatCallback
	 * @param Player $player
	 */
	public function command_Version(array $chatCallback, Player $player) {
		$message = 'This server is using ManiaControl v' . ManiaControl::VERSION . '!';
		$this->chat->sendInformation($message, $player->login);
	}

	/**
	 * Handle Restart AdminCommand
	 *
	 * @param array  $chatCallback
	 * @param Player $player
	 */
	public function command_Restart(array $chatCallback, Player $player) {
		if (!$this->authenticationManager->checkPermission($player, self::SETTING_PERMISSION_RESTART)) {
			$this->authenticationManager->sendNotAllowed($player);
			return;
		}
		$this->restart("ManiaControl Restart requested by '{$player->login}'!");
	}

	/**
	 * Handle //shutdown command
	 *
	 * @param array  $chat
	 * @param Player $player
	 */
	public function command_Shutdown(array $chat, Player $player) {
		if (!$this->authenticationManager->checkPermission($player, self::SETTING_PERMISSION_SHUTDOWN)) {
			$this->authenticationManager->sendNotAllowed($player);
			return;
		}
		$this->quit("ManiaControl Shutdown requested by '{$player->login}'!");
	}

	/**
	 * Quit ManiaControl and log the given message
	 *
	 * @param string $message
	 */
	public function quit($message = null) {
		if ($message) {
			$this->log($message);
		}

		exit();
	}

	/**
	 * Handle PHP Process Shutdown
	 */
	public function handleShutdown() {
		// OnShutdown callback
		$this->callbackManager->triggerCallback(CallbackManager::CB_ONSHUTDOWN);

		// Announce quit
		$this->chat->sendInformation('ManiaControl shutting down.');

		if ($this->client) {
			try {
				// Hide manialinks
				$this->client->sendHideManialinkPage();
				// Close the client connection
				$this->client->delete($this->server->ip, $this->server->port);
			} catch(TransportException $e) {
				$this->errorHandler->triggerDebugNotice($e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
			}
		}

		// Check and Trigger Fatal Errors
		$error = error_get_last();
		if ($error && ($error['type'] & E_FATAL)) {
			$this->errorHandler->errorHandler($error['type'], $error['message'], $error['file'], $error['line']);
		}

		// Disable Garbage Collector
		$this->collectGarbage();
		gc_disable();

		$this->log('Quitting ManiaControl!');
		exit();
	}

	/**
	 * Restart ManiaControl
	 *
	 * @param string $message
	 */
	public function restart($message = null) {
		// Shutdown callback
		$this->callbackManager->triggerCallback(CallbackManager::CB_ONSHUTDOWN);

		// Announce restart
		$this->chat->sendInformation('Restarting ManiaControl...');
		if ($message) {
			$this->log($message);
		}

		// Hide widgets
		$this->client->sendHideManialinkPage();

		$this->log('Restarting ManiaControl!');

		// Execute start script in background
		if ($this->getOS(self::OS_UNIX)) {
			$command = 'sh ' . escapeshellarg(ManiaControlDir . '/ManiaControl.sh') . ' > /dev/null &';
			exec($command);
		} else {
			$command = escapeshellarg(ManiaControlDir . "\ManiaControl.bat");
			system($command); // TODO, windows stucks here as long controller is running
		}
		exit();
	}

	/**
	 * Run ManiaControl
	 */
	public function run() {
		$this->log('Starting ManiaControl v' . self::VERSION . '!');

		// Register shutdown handler
		register_shutdown_function(array($this, 'handleShutdown'));

		// Connect to server
		$this->connect();

		/* - Commented for now, because of updates towards MP2 MCs
		// Check if the version of the server is high enough
		$version = $this->client->getVersion();
		if($version->build < self::MIN_DEDIVERSION) {
			trigger_error("The server has version ".$version->build.", while at least ".self::MIN_DEDIVERSION." is required!", E_USER_ERROR);
		}*/

		// OnInit callback
		$this->callbackManager->triggerCallback(CallbackManager::CB_ONINIT);

		// Load plugins
		$this->pluginManager->loadPlugins();
		$this->updateManager->checkPluginsUpdate();

		// AfterInit callback
		$this->callbackManager->triggerCallback(CallbackManager::CB_AFTERINIT);

		// Enable Garbage Collecting
		gc_enable();
		$this->timerManager->registerTimerListening($this, 'collectGarbage', 1000 * 60);

		// Announce ManiaControl
		$this->chat->sendInformation('ManiaControl v' . self::VERSION . ' successfully started!');

		// Loading finished
		$this->log('Loading completed!');
		$this->log('Link: maniaplanet://#join=' . $this->server->login . '@' . $this->server->titleId);

		// Main loop
		while(!$this->shutdownRequested) {
			$loopStart = microtime(true);

			// Disable script timeout
			set_time_limit(self::SCRIPT_TIMEOUT);

			try {
				// Manager callbacks
				$this->callbackManager->manageCallbacks();
			} catch(TransportException $e) {
				$this->log("Connection interrupted!");
				// TODO remove
				if ($this->errorHandler) {
					$this->errorHandler->triggerDebugNotice("Fatal Exception: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
				}
				$this->quit($e->getMessage());
			}


			// Manage FileReader
			$this->fileReader->appendData();

			// Yield for next tick
			$loopEnd = microtime(true);

			$sleepTime = (int)(2000 - ($loopEnd - $loopStart) * 1000000);
			if ($sleepTime > 0) {
				usleep($sleepTime);
			}
		}

		// Shutdown
		$this->quit();
	}

	/**
	 * Collect Garbage
	 */
	public function collectGarbage() {
		gc_collect_cycles();
	}

	/**
	 * Connect to ManiaPlanet server
	 */
	private function connect() {
		// Load remote client
		$success = $this->server->loadConfig();

		$this->log("Connecting to server at {$this->server->config->host}:{$this->server->config->port}...");

		try {
			$this->client = Connection::factory($this->server->config->host, $this->server->config->port, self::CONNECT_TIMEOUT, $this->server->config->login, $this->server->config->pass);
		} catch(Exception $e) {
			trigger_error("Couldn't authenticate on server with user '{$this->server->config->login}'! " . $e->getMessage(), E_USER_ERROR);
		}

		// Enable callback system
		$this->client->enableCallbacks(true);

		// Wait for server to be ready
		try {
			if (!$this->server->waitForStatus(4)) {
				trigger_error("Server couldn't get ready!", E_USER_ERROR);
			}
		} catch(Exception $e) {
			// TODO remove
			if ($this->errorHandler) {
				$this->errorHandler->triggerDebugNotice("Fatal Exception: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
			}
			$this->quit($e->getMessage());
		}

		// Connect finished
		$this->log("Server Connection successfully established!");

		// Hide old widgets
		$this->client->sendHideManialinkPage();

		// Enable script callbacks if needed
		if ($this->server->getGameMode() != 0) {
			return;
		}

		try {
			$scriptSettings = $this->client->getModeScriptSettings();
		} catch(NotInScriptModeException $e) {
			return;
		}

		if (!array_key_exists('S_UseScriptCallbacks', $scriptSettings)) {
			return;
		}

		$scriptSettings['S_UseScriptCallbacks'] = true;
		try {
			$this->client->setModeScriptSettings($scriptSettings);
		} catch(Exception $e) {
			//TODO temp added 19.04.2014
			$this->maniaControl->errorHandler->triggerDebugNotice("Exception line 437 ManiaControl.php " . $e->getMessage());

			trigger_error("Couldn't set mode script settings to enable script callbacks. " . $e->getMessage());
			return;
		}
		$this->log('Script Callbacks successfully enabled!');
	}
}
