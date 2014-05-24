<?php

namespace ManiaControl\Settings;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\ManiaControl;
use ManiaControl\Plugins\PluginManager;
use ManiaControl\Utils\ClassUtil;

/**
 * Class managing Settings and Configurations
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class SettingManager implements CallbackListener {
	/*
	 * Constants
	 */
	const TABLE_SETTINGS     = 'mc_settings';
	const CB_SETTING_CHANGED = 'SettingManager.SettingChanged';
	/** @deprecated Use CB_SETTING_CHANGED */
	const CB_SETTINGS_CHANGED = 'SettingManager.SettingChanged';

	/*
	 * Private Properties
	 */
	private $maniaControl = null;
	private $storedSettings = array();

	/**
	 * Construct a new Setting Manager
	 *
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->initTables();

		$this->maniaControl->callbackManager->registerCallbackListener(Callbacks::AFTERINIT, $this, 'handleAfterInit');
	}

	/**
	 * Initialize the necessary Database Tables
	 *
	 * @return bool
	 */
	private function initTables() {
		$mysqli            = $this->maniaControl->database->mysqli;
		$settingTableQuery = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_SETTINGS . "` (
				`index` INT(11) NOT NULL AUTO_INCREMENT,
				`class` VARCHAR(100) NOT NULL,
				`setting` VARCHAR(150) NOT NULL,
				`type` VARCHAR(50) NOT NULL,
				`value` VARCHAR(100) NOT NULL,
				`default` VARCHAR(100) NOT NULL,
				`set` VARCHAR(100) NOT NULL,
				`changed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `settingId` (`class`,`setting`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Settings and Configurations' AUTO_INCREMENT=1;";
		$result1           = $mysqli->query($settingTableQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
		}
		// TODO: remove (added in 0.143)
		$alterTableQuery1 = "ALTER TABLE `" . self::TABLE_SETTINGS . "`
				CHANGE `type` `type` VARCHAR(50) NOT NULL;";
		$result2          = $mysqli->query($alterTableQuery1);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
		}
		$alterTableQuery2 = "ALTER TABLE `" . self::TABLE_SETTINGS . "`
				ADD `set` VARCHAR(100) NOT NULL;";
		$mysqli->query($alterTableQuery2);
		return ($result1 && $result2);
	}

	/**
	 * Handle After Init Callback
	 */
	public function handleAfterInit() {
		$this->deleteUnusedSettings();
	}

	/**
	 * Delete all unused Settings that haven't been initialized during the current Startup
	 *
	 * @return bool
	 */
	private function deleteUnusedSettings() {
		$mysqli       = $this->maniaControl->database->mysqli;
		$settingQuery = "DELETE FROM `" . self::TABLE_SETTINGS . "`
				WHERE `changed` < NOW() - INTERVAL 1 HOUR;";
		$result       = $mysqli->query($settingQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		if ($result) {
			$this->clearStorage();
			return true;
		}
		return false;
	}

	/**
	 * Clear the Settings Storage
	 */
	public function clearStorage() {
		$this->storedSettings = array();
	}

	/**
	 * @deprecated
	 * @see SettingManager::getSettingValueByIndex()
	 */
	public function getSettingByIndex($settingIndex, $defaultValue = null) {
		return $this->getSettingValueByIndex($settingIndex, $defaultValue);
	}

	/**
	 * Get a Setting Value by its Index
	 *
	 * @param int   $settingIndex
	 * @param mixed $defaultValue
	 * @return mixed
	 */
	public function getSettingValueByIndex($settingIndex, $defaultValue = null) {
		$setting = $this->getSettingObjectByIndex($settingIndex);
		if (!$setting) {
			return $defaultValue;
		}
		return $setting->value;
	}

	/**
	 * Get a Setting Object by its Index
	 *
	 * @param int $settingIndex
	 * @return Setting
	 */
	public function getSettingObjectByIndex($settingIndex) {
		$mysqli       = $this->maniaControl->database->mysqli;
		$settingQuery = "SELECT * FROM `" . self::TABLE_SETTINGS . "`
				WHERE `index` = {$settingIndex};";
		$result       = $mysqli->query($settingQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		if ($result->num_rows <= 0) {
			$result->free();
			return null;
		}

		/** @var Setting $setting */
		$setting = $result->fetch_object(Setting::CLASS_NAME, array(false, null, null));
		$result->free();

		$this->storeSetting($setting);

		return $setting;
	}

	/**
	 * Store the given Setting
	 *
	 * @param Setting $setting
	 */
	private function storeSetting(Setting $setting) {
		$this->storedSettings[$setting->class . $setting->setting] = $setting;
	}

	/**
	 * Set a Setting for the given Object
	 *
	 * @param mixed  $object
	 * @param string $settingName
	 * @param mixed  $value
	 * @return bool
	 */
	public function setSetting($object, $settingName, $value) {
		$setting = $this->getSettingObject($object, $settingName);
		if ($setting) {
			$setting->value = $value;
			$saved          = $this->saveSetting($setting);
			if (!$saved) {
				return false;
			}
		} else {
			$saved = $this->initSetting($object, $settingName, $value);
			if (!$saved) {
				return false;
			}
			$setting = $this->getSettingObject($object, $settingName, $value);
		}

		$this->storeSetting($setting);

		// Trigger Settings Changed Callback
		$this->maniaControl->callbackManager->triggerCallback(self::CB_SETTING_CHANGED, $setting);

		return true;
	}

	/**
	 * Get Setting by Name for the given Object
	 *
	 * @param mixed  $object
	 * @param string $settingName
	 * @param mixed  $default
	 * @return Setting
	 */
	public function getSettingObject($object, $settingName, $default = null) {
		$settingClass = ClassUtil::getClass($object);

		// Retrieve from Storage if possible
		$storedSetting = $this->getStoredSetting($object, $settingName);
		if ($storedSetting) {
			return $storedSetting;
		}

		// Fetch setting
		$mysqli       = $this->maniaControl->database->mysqli;
		$settingQuery = "SELECT * FROM `" . self::TABLE_SETTINGS . "`
				WHERE `class` = '" . $mysqli->escape_string($settingClass) . "'
				AND `setting` = '" . $mysqli->escape_string($settingName) . "';";
		$result       = $mysqli->query($settingQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		if ($result->num_rows <= 0) {
			$result->free();
			if ($default === null) {
				return null;
			}
			$saved = $this->initSetting($object, $settingName, $default);
			if ($saved) {
				return $this->getSettingObject($object, $settingName, $default);
			} else {
				return null;
			}
		}

		/** @var Setting $setting */
		$setting = $result->fetch_object(Setting::CLASS_NAME, array(false, null, null));
		$result->free();

		$this->storeSetting($setting);

		return $setting;
	}

	/**
	 * Retrieve a stored Setting
	 *
	 * @param mixed  $settingClass
	 * @param string $settingName
	 * @return Setting
	 */
	private function getStoredSetting($settingClass, $settingName) {
		$settingClass = ClassUtil::getClass($settingClass);
		if (isset($this->storedSettings[$settingClass . $settingName])) {
			return $this->storedSettings[$settingClass . $settingName];
		}
		return null;
	}

	/**
	 * Initialize a Setting for the given Object
	 *
	 * @param mixed  $object
	 * @param string $settingName
	 * @param mixed  $defaultValue
	 * @return bool
	 */
	public function initSetting($object, $settingName, $defaultValue) {
		$setting = new Setting($object, $settingName, $defaultValue);
		return $this->saveSetting($setting, true);
	}

	/**
	 * Save the given Setting in the Database
	 *
	 * @param Setting $setting
	 * @param bool    $init
	 * @return bool
	 */
	public function saveSetting(Setting $setting, $init = false) {
		$mysqli = $this->maniaControl->database->mysqli;
		if ($init) {
			// Init - Keep old value if the default didn't change
			$valueUpdateString = '`value` = IF(`default` = VALUES(`default`), `value`, VALUES(`default`))';
		} else {
			// Set - Update value in any case
			$valueUpdateString = '`value` = VALUES(`value`)';
		}
		$settingQuery     = "INSERT INTO `" . self::TABLE_SETTINGS . "` (
				`class`,
				`setting`,
				`type`,
				`value`,
				`default`,
				`set`
				) VALUES (
				?,
				?,
				?,
				?,
				?,
				?
				) ON DUPLICATE KEY UPDATE
				`index` = LAST_INSERT_ID(`index`),
				`type` = VALUES(`type`),
				{$valueUpdateString},
				`default` = VALUES(`default`),
				`set` = VALUES(`set`),
				`changed` = NOW();";
		$settingStatement = $mysqli->prepare($settingQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		$formattedValue   = $setting->getFormattedValue();
		$formattedDefault = $setting->getFormattedDefault();
		$formattedSet     = $setting->getFormattedSet();
		$settingStatement->bind_param('ssssss', $setting->class, $setting->setting, $setting->type, $formattedValue, $formattedDefault, $formattedSet);
		$settingStatement->execute();
		if ($settingStatement->error) {
			trigger_error($settingStatement->error);
			$settingStatement->close();
			return false;
		}
		$settingStatement->close();
		return true;
	}

	/**
	 * @deprecated
	 * @see SettingManager::getSettingValue()
	 */
	public function getSetting($object, $settingName, $default = null) {
		return $this->getSettingValue($object, $settingName, $default);
	}

	/**
	 * Get the Setting Value directly
	 *
	 * @param mixed  $object
	 * @param string $settingName
	 * @param mixed  $default
	 * @return mixed
	 */
	public function getSettingValue($object, $settingName, $default = null) {
		$setting = $this->getSettingObject($object, $settingName, $default);
		if ($setting) {
			return $setting->value;
		}
		return null;
	}

	/**
	 * Reset a Setting to its Default Value
	 *
	 * @param mixed  $object
	 * @param string $settingName
	 * @return bool
	 */
	public function resetSetting($object, $settingName = null) {
		if ($object instanceof Setting) {
			$className   = $object->class;
			$settingName = $object->setting;
		} else {
			$className = ClassUtil::getClass($object);
		}
		$mysqli       = $this->maniaControl->database->mysqli;
		$settingQuery = "UPDATE `" . self::TABLE_SETTINGS . "`
				SET `value` = `default`
				WHERE `class` = '" . $mysqli->escape_string($className) . "'
				AND `setting` = '" . $mysqli->escape_string($settingName) . "';";
		$result       = $mysqli->query($settingQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		if (isset($this->storedSettings[$className . $settingName])) {
			unset($this->storedSettings[$className . $settingName]);
		}
		return $result;
	}

	/**
	 * Delete a Setting
	 *
	 * @param mixed  $object
	 * @param string $settingName
	 * @return bool
	 */
	public function deleteSetting($object, $settingName = null) {
		if ($object instanceof Setting) {
			$className   = $object->class;
			$settingName = $object->setting;
		} else {
			$className = ClassUtil::getClass($object);
		}

		$mysqli       = $this->maniaControl->database->mysqli;
		$settingQuery = "DELETE FROM `" . self::TABLE_SETTINGS . "`
				WHERE `class` = '" . $mysqli->escape_string($className) . "'
				AND `setting` = '" . $mysqli->escape_string($settingName) . "';";
		$result       = $mysqli->query($settingQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}

		if (isset($this->storedSettings[$className . $settingName])) {
			unset($this->storedSettings[$className . $settingName]);
		}

		return $result;
	}

	/**
	 * Get all Settings for the given Class
	 *
	 * @param mixed $object
	 * @return array
	 */
	public function getSettingsByClass($object) {
		$className = ClassUtil::getClass($object);
		$mysqli    = $this->maniaControl->database->mysqli;
		$query     = "SELECT * FROM `" . self::TABLE_SETTINGS . "`
				WHERE `class` = '" . $mysqli->escape_string($className) . "'
				ORDER BY `setting` ASC;";
		$result    = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		$settings = array();
		while ($setting = $result->fetch_object(Setting::CLASS_NAME, array(false, null, null))) {
			$settings[$setting->index] = $setting;
		}
		$result->free();
		return $settings;
	}

	/**
	 * Get all Settings
	 *
	 * @return array
	 */
	public function getSettings() {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "SELECT * FROM `" . self::TABLE_SETTINGS . "`
				ORDER BY `class` ASC, `setting` ASC;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		$settings = array();
		while ($setting = $result->fetch_object(Setting::CLASS_NAME, array(false, null, null))) {
			$settings[$setting->index] = $setting;
		}
		$result->free();
		return $settings;
	}

	/**
	 * Get all Setting Classes
	 *
	 * @param bool $hidePluginClasses
	 * @return array
	 */
	public function getSettingClasses($hidePluginClasses = false) {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "SELECT DISTINCT `class` FROM `" . self::TABLE_SETTINGS . "`
				ORDER BY `class` ASC;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return null;
		}
		$settingClasses = array();
		while ($row = $result->fetch_object()) {
			if (!$hidePluginClasses || !PluginManager::isPluginClass($row->class)) {
				array_push($settingClasses, $row->class);
			}
		}
		$result->free();
		return $settingClasses;
	}
}
