<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../../vendor/autoload.php';

class myaudi extends eqLogic {
	use MipsEqLogicTrait;

	// private static $PICTURES_DIR = __DIR__ . "/../../data/pictures/";
	public static $_encryptConfigKey = array('user', 'password', 'spin', 'googleMapsAPIKey');

	public static function dependancy_install() {
		log::remove(__CLASS__ . '_update');
		return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependency', 'log' => log::getPathToLog(__CLASS__ . '_update'));
	}

	public static function dependancy_info() {
		$return = array();
		$return['log'] = log::getPathToLog(__CLASS__ . '_update');
		$return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependency';
		if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependency')) {
			$return['state'] = 'in_progress';
		} else {
			if (exec(system::getCmdSudo() . system::get('cmd_check') . '-Ec "python3\-requests|python3\-bs4"') < 2) {
				$return['state'] = 'nok';
			} elseif (exec(system::getCmdSudo() . 'pip3 list | grep -Ewc "aiohttp"') < 1) {
				$return['state'] = 'nok';
			} else {
				$return['state'] = 'ok';
			}
		}
		return $return;
	}

	public static function deamon_info() {
		$return = array();
		$return['log'] = __CLASS__;
		$return['state'] = 'nok';

		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
		if (file_exists($pid_file)) {
			if (@posix_getsid(trim(file_get_contents($pid_file)))) {
				$return['state'] = 'ok';
			} else {
				shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
			}
		}
		$return['launchable'] = 'ok';
		$user = config::byKey('user', __CLASS__);
		$pswd = config::byKey('password', __CLASS__);
		if ($user == '') {
			$return['launchable'] = 'nok';
			$return['launchable_message'] = __('Le nom d\'utilisateur n\'est pas configuré', __FILE__);
		} elseif ($pswd == '') {
			$return['launchable'] = 'nok';
			$return['launchable_message'] = __('Le mot de passe n\'est pas configuré', __FILE__);
		}
		return $return;
	}

	public static function deamon_start() {
		self::deamon_stop();
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}

		$path = realpath(dirname(__FILE__) . '/../../resources/myaudid');
		$cmd = 'sudo python3 ' . $path . '/myaudid.py';
		$cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
		$cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, '55066');
		$cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/myaudi/core/php/jeeMyAudi.php';
		$cmd .= ' --user "' . trim(str_replace('"', '\"', config::byKey('user', __CLASS__))) . '"';
		$cmd .= ' --pswd "' . trim(str_replace('"', '\"', config::byKey('password', __CLASS__))) . '"';
		$cmd .= ' --spin "' . trim(str_replace('"', '\"', config::byKey('spin', __CLASS__))) . '"';
		$cmd .= ' --country ' . config::byKey('country', __CLASS__, 'DE');
		$cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
		$cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
		log::add(__CLASS__, 'info', 'Lancement démon MyAudi');
		$result = exec($cmd . ' >> ' . log::getPathToLog('myaudi_daemon') . ' 2>&1 &');
		$i = 0;
		while ($i < 10) {
			$deamon_info = self::deamon_info();
			if ($deamon_info['state'] == 'ok') {
				break;
			}
			sleep(1);
			$i++;
		}
		if ($i >= 10) {
			log::add(__CLASS__, 'error', __('Impossible de lancer le démon MyAudi, vérifiez le log', __FILE__), 'unableStartDeamon');
			return false;
		}
		message::removeAll(__CLASS__, 'unableStartDeamon');
		return true;
	}

	public static function deamon_stop() {
		$pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
		if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			system::kill($pid);
		}
		system::kill('myaudid.py');
		// system::fuserk(config::byKey('socketport', __CLASS__));
		sleep(1);
	}

	public static function cron() {
		foreach (eqLogic::byType(__CLASS__, true) as $eqLogic) {
			$autorefresh = $eqLogic->getConfiguration('autorefresh', '');
			$cronIsDue = false;
			if ($autorefresh == '')  continue;
			try {
				$cron = new Cron\CronExpression($autorefresh, new Cron\FieldFactory);
				$cronIsDue = $cron->isDue();
			} catch (Exception $e) {
				log::add(__CLASS__, 'error', __('Expression cron non valide: ', __FILE__) . $autorefresh);
			}
			try {
				if ($cronIsDue) {
					$eqLogic->refresh();
				}
			} catch (\Throwable $th) {
				log::add(__CLASS__, 'debug', $th->getMessage());
			}
		}
	}

	public static function sendToDaemon($params) {
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] != 'ok') {
			throw new Exception("Le démon n'est pas démarré");
		}
		$params['apikey'] = jeedom::getApiKey(__CLASS__);
		$payLoad = json_encode($params);
		$socket = socket_create(AF_INET, SOCK_STREAM, 0);
		socket_connect($socket, '127.0.0.1', config::byKey('socketport', __CLASS__, '55066'));
		socket_write($socket, $payLoad, strlen($payLoad));
		socket_close($socket);
	}

	public static function syncVehicle($vehicle) {
		$eqLogic = eqLogic::byLogicalId($vehicle['vehicle'], __CLASS__);
		if (!is_object($eqLogic)) {
			log::add(__CLASS__, 'info', 'Creating new vehicle with vin="' . $vehicle['vehicle'] . '" and csid="' . $vehicle['csid'] . '"');
			$eqLogic = new self();
			$eqLogic->setLogicalId($vehicle['vehicle']);
			$eqLogic->setName($vehicle['model_full']);
			$eqLogic->setEqType_name(__CLASS__);
			$eqLogic->setIsEnable(1);
		}
		$eqLogic->setConfiguration('csid', $vehicle['csid']);
		$eqLogic->setConfiguration('model_year', $vehicle['model_year']);
		$eqLogic->setConfiguration('brand', $vehicle['brand']);
		$eqLogic->setConfiguration('model_family', $vehicle['model_family']);
		$eqLogic->setConfiguration('model_full', $vehicle['model_full']);
		$eqLogic->setConfiguration('type', $vehicle['type']);
		$eqLogic->save();

		//if (!file_exists(myaudi::$PICTURES_DIR)) {
		//mkdir(myaudi::$PICTURES_DIR, 0777, true);
		//}
		//$filepath = myaudi::$PICTURES_DIR.$vehicle['vehicle'].'-'.$vehicle['csid'].'.png';
		//if (!file_exists($filepath)) {
		//file_put_contents($filepath, file_get_contents($vehicle['data']['Vehicle']['LifeData']['MediaData'][0]['URL']));
		//}
		$commandsConfig = $eqLogic->getCommandsFileContent(__DIR__ . '/../config/commands.json');
		$eqLogic->createCommandsFromConfig($commandsConfig['vehicle']);
		if ($vehicle['support_status_report']) $eqLogic->createCommandsFromConfig($commandsConfig['status']);
		if ($vehicle['support_ac']) $eqLogic->createCommandsFromConfig($commandsConfig['climatisation']);
		if ($vehicle['support_position']) $eqLogic->createCommandsFromConfig($commandsConfig['position']);
		if ($vehicle['support_preheater']) $eqLogic->createCommandsFromConfig($commandsConfig['preheater']);
		if ($vehicle['support_charger']) $eqLogic->createCommandsFromConfig($commandsConfig['charger']);
		$eqLogic->updateVehicleData($vehicle);
	}

	public function updateVehicleData($vehicle) {
		$vehicleLockState = 1;
		$vehicleOpenState = 1;
		foreach ($vehicle['data'] as $key => $value) {
			switch ($key) {
				case 'TEMPERATURE_OUTSIDE':
					$celcius = ($value / 10) - 273.15;
					$this->checkAndUpdateCmd($key, $celcius);
					break;
				case 'MAINTENANCE_INTERVAL_DISTANCE_TO_OIL_CHANGE':
				case 'MAINTENANCE_INTERVAL_TIME_TO_OIL_CHANGE':
				case 'MAINTENANCE_INTERVAL_DISTANCE_TO_INSPECTION':
				case 'MAINTENANCE_INTERVAL_TIME_TO_INSPECTION':
					$this->checkAndUpdateCmd($key, $value * -1);
					break;
				case 'LOCK_STATE_LEFT_FRONT_DOOR':
				case 'LOCK_STATE_LEFT_REAR_DOOR':
				case 'LOCK_STATE_RIGHT_FRONT_DOOR':
				case 'LOCK_STATE_RIGHT_REAR_DOOR':
				case 'LOCK_STATE_TRUNK_LID':
					log::add(__CLASS__, 'debug', "{$key}={$value}");
					$doorLockState = $value == 2 ? 1 : 0;
					$vehicleLockState &= $doorLockState;
					$this->checkAndUpdateCmd($key, $doorLockState);
					break;
				case 'OPEN_STATE_LEFT_FRONT_DOOR':
				case 'OPEN_STATE_LEFT_REAR_DOOR':
				case 'OPEN_STATE_RIGHT_FRONT_DOOR':
				case 'OPEN_STATE_RIGHT_REAR_DOOR':
				case 'OPEN_STATE_TRUNK_LID':
					log::add(__CLASS__, 'debug', "{$key}={$value}");
					$doorOpenState = $value == 3 ? 1 : 0;
					$vehicleOpenState &= $doorOpenState;
					$this->checkAndUpdateCmd($key, $doorOpenState);
					break;
				default:
					$this->checkAndUpdateCmd($key, $value);
					break;
			}
		}
		log::add(__CLASS__, 'debug', "LOCK_STATE_VEHICLE: {$vehicleLockState}");
		$this->checkAndUpdateCmd('LOCK_STATE_VEHICLE', $vehicleLockState);
		log::add(__CLASS__, 'debug', "OPEN_STATE_VEHICLE: {$vehicleOpenState}");
		$this->checkAndUpdateCmd('OPEN_STATE_VEHICLE', $vehicleOpenState);

		$latitude = isset($vehicle['position']['carCoordinate']['latitude']) ? $vehicle['position']['carCoordinate']['latitude'] : '0';
		$longitude = isset($vehicle['position']['carCoordinate']['longitude']) ? $vehicle['position']['carCoordinate']['longitude'] : '0';
		$this->checkAndUpdateCmd('LOCATION', "{$latitude},{$longitude}");
	}

	public function postSave() {
		$this->refreshWidget();
	}

	public static function postConfig_googleMapsAPIKey($value) {
		foreach (eqLogic::byType(__CLASS__, true) as $eqLogic) {
			$eqLogic->refreshWidget();
		}
	}

	// public function getImage($returnPluginIcon = true) {
	// 	$file = "{$this->getLogicalId()}-{$this->getConfiguration('csid')}.png";
	// 	log::add(__CLASS__, 'debug', "get image {$file}");
	// 	if (file_exists(myaudi::$PICTURES_DIR . $file)) {
	// 		return "plugins/myaudi/data/pictures/{$file}";
	// 	}
	// 	log::add(__CLASS__, 'debug', "not found?");
	// 	if ($returnPluginIcon) {
	// 		return parent::getImage();
	// 	}
	// 	return '';
	// }

	public function refresh() {
		$params = array('method' => 'getVehicleData', 'vin' => $this->getLogicalId());
		myaudi::sendToDaemon($params);
	}

	public function execute_vehicle_action($state) {
		$params = array(
			'method' => $state,
			'vin' => $this->getLogicalId()
		);
		myaudi::sendToDaemon($params);
	}
}

class myaudiCmd extends cmd {

	public static $_widgetPossibility = array(
		'custom' => array(
			'widget' => false,
			'visibility' => true,
			'displayName' => true,
			'displayIconAndName' => true,
			'optionalParameters' => true
		)
	);

	public function execute($_options = array()) {

		$eqlogic = $this->getEqLogic();

		switch ($this->getLogicalId()) {
			case 'refresh':
				$eqlogic->refresh();
				break;

			case 'lock':
				log::add("myaudi", 'debug', "Locking");
				$eqlogic->execute_vehicle_action('lock');
				break;
			case 'unlock':
				log::add("myaudi", 'debug', "Unlocking");
				$eqlogic->execute_vehicle_action('unlock');
				break;

			case 'preheater_start':
				log::add("myaudi", 'debug', "Preheater start");
				$eqlogic->execute_vehicle_action('start_preheater');
				break;
			case 'preheater_stop':
				log::add("myaudi", 'debug', "Preheater stop");
				$eqlogic->execute_vehicle_action('stop_preheater');
				break;

			case 'charger_start':
				log::add("myaudi", 'debug', "Charger start");
				$eqlogic->execute_vehicle_action('start_charger');
				break;
			case 'charger_stop':
				log::add("myaudi", 'debug', "Charger stop");
				$eqlogic->execute_vehicle_action('stop_charger');
				break;

			case 'ac_start':
				log::add("myaudi", 'debug', "Air conditionning start");
				$eqlogic->execute_vehicle_action('start_climatisation');
				break;
			case 'ac_stop':
				log::add("myaudi", 'debug', "Air conditionning stop");
				$eqlogic->execute_vehicle_action('stop_climatisation');
				break;

			case 'defrost_start':
				log::add("myaudi", 'debug', "Windows defrost start");
				$eqlogic->execute_vehicle_action('start_window_heating');
				break;
			case 'defrost_stop':
				log::add("myaudi", 'debug', "Windows defrost stop");
				$eqlogic->execute_vehicle_action('stop_window_heating');
				break;
			default:
				log::add("myaudi", 'debug', "No action attached to " . $this->getLogicalId());
		}
	}

	private function locationToHtml($_version = 'dashboard', $_options = '', $_cmdColor = null) {
		$version2 = jeedom::versionAlias($_version, false);
		if ($this->getDisplay('showOn' . $version2, 1) == 0) {
			return '';
		}

		$hideCoordinates = 'hidden';
		$showMap = 1;
		$mapWidth = 240;
		$mapHeight = 180;
		$parameters = $this->getDisplay('parameters');
		if (is_array($parameters)) {
			if (isset($parameters['showMap'])) {
				$showMap = $parameters['showMap'];
			}
			if (isset($parameters['showCoordinates']) && $parameters['showCoordinates'] == 1) {
				$hideCoordinates = '';
			}
			if (isset($parameters['mapWidth']) && is_numeric($parameters['mapWidth'])) {
				$mapWidth = $parameters['mapWidth'];
			}
			if (isset($parameters['mapHeight']) && is_numeric($parameters['mapHeight'])) {
				$mapHeight = $parameters['mapHeight'];
			}
		}

		if ($showMap == 0) {
			log::add('myaudi', 'info', "map not active, default widget used");
			return parent::toHtml($_version, $_options, $_cmdColor);
		}

		$apiKey = config::byKey('googleMapsAPIKey', 'myaudi');
		if ($apiKey == '') {
			log::add('myaudi', 'info', "no google Maps API Key configured, default widget used");
			return parent::toHtml($_version, $_options, $_cmdColor);
		}

		log::add('myaudi', 'debug', "hideCoordinates:{$hideCoordinates} - showMap:{$showMap} - mapWidth:{$mapWidth} - mapHeight:{$mapHeight}");



		$replace = array(
			'#id#' => $this->getId(),
			'#name#' => $this->getName(),
			'#location#' => $this->execCmd(),
			'#name_display#' => ($this->getDisplay('icon') != '') ? $this->getDisplay('icon') : $this->getName(),
			'#history#' => ($this->getIsHistorized() == 1) ? 'history cursor' : '',
			'#logicalId#' => $this->getLogicalId(),
			'#uid#' => 'cmd' . $this->getId() . eqLogic::UIDDELIMITER . mt_rand() . eqLogic::UIDDELIMITER,
			'#version#' => $_version,
			'#eqLogic_id#' => $this->getEqLogic_id(),
			'#hideCmdName#' => ($this->getDisplay('showNameOn' . $version2, 1) == 0) ? 'display:none;' : '',
			'#collectDate#' => $this->getCollectDate(),
			'#valueDate#' => $this->getValueDate(),
			'#apiKey#' => $apiKey,
			'#mapsWidth#' => $mapWidth,
			'#mapsHeight#' => $mapHeight,
			'#hideCoordinates#' => $hideCoordinates
		);
		if ($this->getDisplay('showIconAndName' . $version2, 0) == 1) {
			$replace['#name_display#'] = $this->getDisplay('icon') . ' ' . $this->getName();
		}

		$version = jeedom::versionAlias($_version);
		$template = getTemplate('core', $version, 'locationCmd', 'myaudi');

		return template_replace($replace, $template);
	}

	public function toHtml($_version = 'dashboard', $_options = '', $_cmdColor = null) {
		if ($this->getLogicalId() == 'LOCATION') {
			return $this->locationToHtml($_version, $_options, $_cmdColor);
		}
		return parent::toHtml($_version, $_options, $_cmdColor);
	}
}
