<?php

namespace ManiaControl;

/**
 * ManiaControl Obstacle Plugin
 *
 * @author steeffeen
 */
class Plugin_Obstacle extends Plugin {
	/**
	 * Constants
	 */
	const CB_JUMPTO = 'Obstacle.JumpTo';
	const VERSION = '1.0';

	/**
	 * Private properties
	 */
	private $mc = null;

	private $config = null;

	/**
	 * Constuct obstacle plugin
	 */
	public function __construct($mc) {
		$this->mc = $mc;
		
		// Load config
		$this->config = Tools::loadConfig('obstacle.plugin.xml');
		
		// Check for enabled setting
		if (!Tools::toBool($this->config->enabled)) return;
		
		// Register for jump command
		$this->mc->commands->registerCommandHandler('jumpto', $this, 'command_jumpto');
		
		error_log('Obstacle Pugin v' . self::VERSION . ' ready!');
	}

	/**
	 * Handle jumpto command
	 */
	public function command_jumpto($chat) {
		$login = $chat[1][1];
		$rightLevel = (string) $this->config->jumps_rightlevel;
		if (!$this->mc->authentication->checkRight($login, $rightLevel)) {
			// Not allowed
			$this->mc->authentication->sendNotAllowed($login);
		}
		else {
			// Send jump callback
			$params = explode(' ', $chat[1][2], 2);
			$param = $login . ";" . $params[1] . ";";
			if (!$this->mc->client->query('TriggerModeScriptEvent', self::CB_JUMPTO, $param)) {
				trigger_error("Couldn't send jump callback for '" . $login . "'. " . $this->mc->getClientErrorText());
			}
		}
	}
}

?>
