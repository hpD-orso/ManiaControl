<?php

namespace ManiaControl;

/**
 * Needed includes
 */
require_once __DIR__ . '/authentication.php';
require_once __DIR__ . '/callbacks.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/commands.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/server.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/pluginHandler.php';
require_once __DIR__ . '/manialinkIdHandler.php';
list($endiantest) = array_values(unpack('L1L', pack('V', 1)));
if ($endiantest == 1) {
	require_once __DIR__ . '/PhpRemote/GbxRemote.inc.php';
}
else {
	require_once __DIR__ . '/PhpRemote/GbxRemote.bem.php';
}

/**
 * ManiaControl Server Controller for ManiaPlanet Server
 *
 * @author steeffeen and Lukas
 */
class ManiaControl {
	/**
	 * Constants
	 */
	const VERSION = '0.1';
	const API_VERSION = '2013-04-16';
	const DATE = 'd-m-y h:i:sa T';

    /**
     * Private properties
     */
    private $version = 0;

	/**
	 * Public properties
	 */
	public $authentication = null;

	public $callbacks = null;

	public $client = null;

	public $chat = null;

	public $config = null;

	public $commands = null;

	public $database = null;

	public $debug = false;

	public $server = null;

	public $startTime = -1;

	public $stats = null;

    public $manialinkIdHandler = null;
    public $pluginHandler = null;

	/**
	 * Private properties
	 */
	private $plugins = array();

	private $shutdownRequested = false;

	/**
	 * Construct ManiaControl
	 */
	public function __construct() {
		// Load core
		$this->config = Tools::loadConfig('core.ManiaControl.xml');
		$this->startTime = time();
		
		// Load chat tool
		$this->chat = new Chat($this);
		
		// Load callbacks handler
		$this->callbacks = new Callbacks($this);
		
		// Load database
		$this->database = new Database($this);
		
		// Load server
		$this->server = new Server($this);
		
		// Load authentication
		$this->authentication = new Authentication($this);

        // Load manialinkidHandler
        $this->manialinkIdHandler = new ManialinkIdHandler();

        // Load pluginHandler
        $this->pluginHandler = new PluginHandler($this);

		// Load commands handler
		$this->commands = new Commands($this);
		
		// Load stats manager
		$this->stats = new Stats($this);

		// Register for core callbacks
		$this->callbacks->registerCallbackHandler(Callbacks::CB_MP_ENDMAP, $this, 'handleEndMap');

        // Set ManiaControl version
        $this->version = VERSION;
	}

	/**
	 * Return message composed of client error message and error code
	 *
	 * @param object $client        	
	 * @return string
	 */
	public function getClientErrorText($client = null) {
		if (is_object($client)) {
			return $client->getErrorMessage() . ' (' . $client->getErrorCode() . ')';
		}
		return $this->client->getErrorMessage() . ' (' . $this->client->getErrorCode() . ')';
	}

	/**
	 * Quit ManiaControl and log the given message
	 */
	public function quit($message = false) {
		if ($this->shutdownRequested) return;
		
		if ($this->client) {
			// Announce quit
			$this->chat->sendInformation('ManiaControl shutting down.');
			
			// Hide manialinks
			$this->client->query('SendHideManialinkPage');
		}
		
		// Log quit reason
		if ($message) {
			error_log($message);
		}
		
		// Shutdown
		if ($this->client) $this->client->Terminate();
		
		error_log("Quitting ManiaControl!");
		exit();
	}

	/**
	 * Run ManiaControl
	 */
	public function run($debug = false) {
		error_log('Starting ManiaControl v' . self::VERSION . '!');
		$this->debug = (bool) $debug;
		
		// Load plugins
		//$this->loadPlugins();
		$this->pluginHandler->loadPlugins();

		// Connect to server
		$this->connect();
		
		// Loading finished
		error_log("Loading completed!");
		
		// Announce ManiaControl
		if (!$this->chat->sendInformation('ManiaControl v' . self::VERSION . ' successfully started!')) {
			trigger_error("Couldn't announce ManiaControl. " . $this->getClientErrorText());
		}
		
		// OnInit
		$this->callbacks->onInit();
		
		// Main loop
		while (!$this->shutdownRequested) {
			$loopStart = microtime(true);
			
			// Disable script timeout
			set_time_limit(30);
			
			// Handle server callbacks
			$this->callbacks->handleCallbacks();
			
			// Loop plugins
			/*foreach ($this->plugins as $plugin) {
				if (!method_exists($plugin, 'loop')) {
					continue;
				}
				$plugin->loop();
			}*/
			
			// Yield for next tick
			$loopEnd = microtime(true);
			$sleepTime = 300000 - $loopEnd + $loopStart;
			if ($sleepTime > 0) {
				usleep($sleepTime);
			}
		}
		
		// Shutdown
		$this->client->Terminate();
	}

	/**
	 * Connect to ManiaPlanet server
	 */
	private function connect() {
		$enable = $this->server->config->xpath('enable');
		$enable = Tools::toBool($enable[0]);
		if (!$enable) return;
		
		// Load remote client
		$this->client = new \IXR_ClientMulticall_Gbx();
		
		$host = $this->server->config->xpath('host');
		if (!$host) trigger_error("Invalid server configuration (host).", E_USER_ERROR);
		$host = (string) $host[0];
		$port = $this->server->config->xpath('port');
		if (!$host) trigger_error("Invalid server configuration (port).", E_USER_ERROR);
		$port = (string) $port[0];
		$timeout = $this->config->xpath('timeout');
		if (!$timeout) trigger_error("Invalid core configuration (timeout).", E_USER_ERROR);
		$timeout = (int) $timeout[0];
		
		error_log("Connecting to server at " . $host . ":" . $port . "...");
		
		// Connect
		if (!$this->client->InitWithIp($host, $port, $timeout)) {
			trigger_error(
					"Couldn't connect to server! " . $this->client->getErrorMessage() . "(" . $this->client->getErrorCode() . ")", 
					E_USER_ERROR);
		}
		
		$login = $this->server->config->xpath('login');
		if (!$login) trigger_error("Invalid server configuration (login).", E_USER_ERROR);
		$login = (string) $login[0];
		$pass = $this->server->config->xpath('pass');
		if (!$pass) trigger_error("Invalid server configuration (password).", E_USER_ERROR);
		$pass = (string) $pass[0];
		
		// Authenticate
		if (!$this->client->query('Authenticate', $login, $pass)) {
			trigger_error(
					"Couldn't authenticate on server with user '" . $login . "'! " . $this->client->getErrorMessage() . "(" .
							 $this->client->getErrorCode() . ")", E_USER_ERROR);
		}
		
		// Enable callback system
		if (!$this->client->query('EnableCallbacks', true)) {
			trigger_error("Couldn't enable callbacks! " . $this->client->getErrorMessage() . "(" . $this->client->getErrorCode() . ")", 
					E_USER_ERROR);
		}
		
		// Wait for server to be ready
		if (!$this->server->waitForStatus($this->client, 4)) {
			trigger_error("Server couldn't get ready!", E_USER_ERROR);
		}
		
		// Set api version
		if (!$this->client->query('SetApiVersion', self::API_VERSION)) {
			trigger_error(
					"Couldn't set API version '" . self::API_VERSION . "'! This might cause problems. " .
							 $this->getClientErrorText());
		}
		
		// Connect finished
		error_log("Server connection succesfully established!");
		
		// Enable service announces
		if (!$this->client->query("DisableServiceAnnounces", false)) {
			trigger_error("Couldn't enable service announces. " . $this->getClientErrorText());
		}
		
		// Enable script callbacks if needed
		if ($this->server->getGameMode() === 0) {
			if (!$this->client->query('GetModeScriptSettings')) {
				trigger_error("Couldn't get mode script settings. " . $this->getClientErrorText());
			}
			else {
				$scriptSettings = $this->client->getResponse();
				if (array_key_exists('S_UseScriptCallbacks', $scriptSettings)) {
					$scriptSettings['S_UseScriptCallbacks'] = true;
					if (!$this->client->query('SetModeScriptSettings', $scriptSettings)) {
						trigger_error(
								"Couldn't set mode script settings to enable script callbacks. " . $this->getClientErrorText());
					}
					else {
						error_log("Script callbacks successfully enabled.");
					}
				}
			}
		}
	}

	/**
	 * Load ManiaControl plugins
	 */
	//private function loadPlugins() {
	/*	$pluginsConfig = Tools::loadConfig('plugins.ManiaControl.xml');
		if (!$pluginsConfig || !isset($pluginsConfig->plugin)) {
			trigger_error('Invalid plugins config.');
			return;
		}
		
		// Load plugin classes
		$classes = get_declared_classes();
		foreach ($pluginsConfig->xpath('plugin') as $plugin) {
			$fileName = ManiaControlDir . '/plugins/' . $plugin;
			if (!file_exists($fileName)) {
				trigger_error("Couldn't load plugin '" . $plugin . "'! File doesn't exist. (/plugins/" . $plugin . ")");
			}
			else {
				require_once $fileName;
				error_log("Loading plugin: " . $plugin);
			}
		}
		$plugins = array_diff(get_declared_classes(), $classes);
		
		// Create plugins
		foreach ($plugins as $plugin) {
			$nameIndex = stripos($plugin, 'plugin');
			if ($nameIndex === false) continue;
			array_push($this->plugins, new $plugin($this));
		}*/
	//}

	/**
	 * Handle EndMap callback
	 */
	public function handleEndMap($callback) {
		// Autosave match settings
		$autosaveMatchsettings = $this->config->xpath('autosave_matchsettings');
		if ($autosaveMatchsettings) {
			$autosaveMatchsettings = (string) $autosaveMatchsettings[0];
			if ($autosaveMatchsettings) {
				if (!$this->client->query('SaveMatchSettings', 'MatchSettings/' . $autosaveMatchsettings)) {
					trigger_error("Couldn't autosave match settings. " . $this->getClientErrorText());
				}
			}
		}
	}

	/**
	 * Check config settings
	 */
	public function checkConfig($config, $settings, $name = 'Config XML') {
		if (!is_array($settings)) $settings = array($settings);
		foreach ($settings as $setting) {
			$settingTags = $config->xpath('//' . $setting);
			if (empty($settingTags)) {
				trigger_error("Missing property '" . $setting . "' in config '" . $name . "'!", E_USER_ERROR);
			}
		}
	}

    /**
     * @param mixed $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * @return mixed
     */
    public function getVersion()
    {
        return $this->version;
    }

}

?>
