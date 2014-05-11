<?php

namespace MCTeam;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Files\FileUtil;
use ManiaControl\ManiaControl;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Settings\SettingManager;

/**
 * ManiaControl Chatlog Plugin
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class ChatlogPlugin implements CallbackListener, Plugin {
	/*
	 * Constants
	 */
	const ID                        = 26;
	const VERSION                   = 0.2;
	const NAME                      = 'Chatlog Plugin';
	const AUTHOR                    = 'MCTeam';
	const DATE                      = 'd-m-y h:i:sa T';
	const SETTING_FOLDERNAME        = 'Log-Folder Name';
	const SETTING_FILENAME          = 'Log-File Name';
	const SETTING_USEPID            = 'Use Process-Id for File Name';
	const SETTING_LOGSERVERMESSAGES = 'Log Server Messages';

	/**
	 * Private Properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;
	private $fileName = null;

	/**
	 * @see \ManiaControl\Plugins\Plugin::prepare()
	 */
	public static function prepare(ManiaControl $maniaControl) {
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getId()
	 */
	public static function getId() {
		return self::ID;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getName()
	 */
	public static function getName() {
		return self::NAME;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getVersion()
	 */
	public static function getVersion() {
		return self::VERSION;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getAuthor()
	 */
	public static function getAuthor() {
		return self::AUTHOR;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getDescription()
	 */
	public static function getDescription() {
		return 'Plugin logging the Chat Messages of the Server for later Checks and Controlling.';
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::load()
	 */
	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;

		// Init settings
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_FOLDERNAME, 'logs');
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_FILENAME, 'ChatLog.log');
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_USEPID, false);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_LOGSERVERMESSAGES, true);

		// Register for callbacks
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_PLAYERCHAT, $this, 'handlePlayerChatCallback');
		$this->maniaControl->callbackManager->registerCallbackListener(SettingManager::CB_SETTINGS_CHANGED, $this, 'handleSettingsChangedCallback');

		$this->buildLogFileName();

		return true;
	}

	/**
	 * Build the Log File Name and Folder
	 */
	private function buildLogFileName() {
		$folderName = $this->maniaControl->settingManager->getSetting($this, self::SETTING_FOLDERNAME);
		$folderName = FileUtil::getClearedFileName($folderName);
		$folderDir  = ManiaControlDir . $folderName;
		if (!is_dir($folderDir)) {
			$success = mkdir($folderDir);
			if (!$success) {
				trigger_error("Couldn't create ChatlogPlugin Log-Folder '{$folderName}'!");
			}
		}
		$fileName = $this->maniaControl->settingManager->getSetting($this, self::SETTING_FILENAME);
		$fileName = FileUtil::getClearedFileName($fileName);
		$usePId   = $this->maniaControl->settingManager->getSetting($this, self::SETTING_USEPID);
		if ($usePId) {
			$dotIndex = strripos($fileName, '.');
			$pIdPart  = '_' . getmypid();
			if ($dotIndex !== false && $dotIndex >= 0) {
				$fileName = substr($fileName, 0, $dotIndex) . $pIdPart . substr($fileName, $dotIndex);
			} else {
				$fileName .= $pIdPart;
			}
		}
		$this->fileName = $folderDir . DIRECTORY_SEPARATOR . $fileName;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::unload()
	 */
	public function unload() {
	}

	/**
	 * Handle PlayerChat callback
	 *
	 * @param array $chatCallback
	 */
	public function handlePlayerChatCallback(array $chatCallback) {
		$data = $chatCallback[1];
		if ($data[0] <= 0) {
			// Server message
			$logServerMessages = $this->maniaControl->settingManager->getSetting($this, self::SETTING_LOGSERVERMESSAGES);
			if (!$logServerMessages) {
				// Skip it
				return;
			}
		}
		$this->logText($data[2], $data[1]);
	}

	/**
	 * Log the given message
	 *
	 * @param string $text
	 * @param string $login
	 */
	private function logText($text, $login = null) {
		$message = date(self::DATE) . ' >> ';;
		if ($login) {
			$message .= $login . ': ';
		}
		$message .= $text . PHP_EOL;
		file_put_contents($this->fileName, $message, FILE_APPEND);
	}

	/**
	 * Handle Settings Changed Callback
	 *
	 * @param string $settingClass
	 */
	public function handleSettingsChangedCallback($settingClass) {
		if ($settingClass !== get_class()) {
			return;
		}
		$this->buildLogFileName();
	}
}