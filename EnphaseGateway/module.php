<?php
declare(strict_types=1);

class EnphaseAuthException extends Exception { }
class EnphaseCurlException extends Exception { }
class EnphaseHttpException extends Exception { }
class CurlException        extends Exception { }

require_once __DIR__ . '/EnphaseAPI.php';
require_once __DIR__ . '/../libs/ModuleRegistration.php';
require_once __DIR__ . '/../libs/JsonWebToken.php';

class EnphaseGateway extends IPSModule
{

	protected $api = null;

	protected $endpoints = [
		'production',
		'ivp/ensemble/inventory',
		'ivp/meters/readings',
		'ivp/livedata/status',
	];

	public function __construct($InstanceID)
	{
		parent::__construct($InstanceID);
		$this->api = new EnphaseAPI();
	}

	public function Create()
    {
        parent::Create();
		try {
			$moduleData = json_decode(file_get_contents(__DIR__ . '/module.json'), true);
			$this->SetBuffer('EnphaseGateway.ChildRequirements', $moduleData['childRequirements'][0]);
			$mr = new ModuleRegistration($this);
			$config = include __DIR__ . '/module.config.php';
			$mr->Register($config);
			$this->RegisterTimer('Update', 0, 'ENPHASE_Update($_IPS[\'TARGET\']);');	
		} catch (Exception $e) {
			$this->LogMessage(__CLASS__, "Error creating Enphase IO module: " . $e->getMessage(), KL_ERROR);
		}
    }

	public function Destroy() {
		parent::Destroy();
		$this->SetTimerInterval('Update', 0);
		$config = include __DIR__ . '/module.config.php';
		if (isset($config['profiles'])) {
			$mr = new ModuleRegistration($this);
			$mr->DeleteProfiles($config['profiles']);
		}
	}
	
	public function ApplyChanges()
	{
		parent::ApplyChanges();
		
		// reset the token and re-initialize the API
		$this->WriteAttributeString('token', '');
		
		// Initialize the Enphase API
		try {
			$this->initApi();
			$enabled = $this->ReadPropertyBoolean('enabled');
			$updateInterval = $enabled ? $this->ReadPropertyInteger('updateInterval') : 0;
			$this->SetTimerInterval('Update', $updateInterval * 1000);
			// remove legacy variables that are now attributes
			$this->cleanupLegacyVariables();
			$this->SetStatus(102); // Set status to active if successful
		} catch (Exception $e) {
			$this->SetStatus(104); // Set an error status if initialization fails
			$this->LogMessage("Error initializing Enphase Gateway: " . $e->getMessage(), KL_ERROR);
		}		
	}

	public function Update() {
		// Poll Enphase endpoints and normalize data into a vendor-neutral payload
		// The following endpoints are used:
		// - production: provides PV production, total consumption, and grid power
		//   Note: Enphase returns arrays of arrays, e.g. production['production'][1]['wNow']
		//         and consumption indices for total vs grid; we map them explicitly below.
		// - ivp/meters/readings: alternative structured meter readings (types/channels)
		// - ivp/livedata/status: provides live values (typically in mW) and battery flags
		// - ivp/ensemble/inventory: includes battery SoC, sleep_enabled, and led_status
		$tokenRefreshed = false;
		try {
			$data = [ 'DataID' => $this->GetBuffer('EnphaseGateway.ChildRequirements') ] ;
			foreach ($this->endpoints as $endpoint) {
				$result = $this->api->apiRequest($this->getConfig(), $endpoint);
				if ($result['httpStatus'] === 401) {
					if (!$tokenRefreshed && $this->refreshToken()) {
						$tokenRefreshed = true;
						$result = $this->api->apiRequest($this->getConfig(), $endpoint);
					}

					private function cleanupLegacyVariables(): void
					{
						foreach (['user','serial','access','issue','expiration'] as $ident) {
							try { if (@$this->GetIDForIdent($ident)) { @$this->UnregisterVariable($ident); } } catch (Throwable $t) { /* ignore */ }
						}
					}
					if ($result['httpStatus'] === 401) {
						$this->LogMessage(sprintf('API endpoint %s returned unauthorized (401).', $endpoint), KL_WARNING);
						$this->SetStatus(104);
						$data['data'][$endpoint] = null;
						continue;
					}
				}
				if ($result['httpStatus'] !== 200) {
					$this->LogMessage(sprintf('API endpoint %s returned status %s', $endpoint, (string) $result['httpStatus']), KL_WARNING);
					$data['data'][$endpoint] = null;
					continue;
				}
				$data['data'][$endpoint] = json_decode($result['response'], true);
			}

			// Build normalized energy payload (com.maxence.energy.v1)
			// This payload is vendor-neutral and sent to children (e.g., SolarCharger).
			// Field mapping:
			// - pv.pv_power_w:        production['production'][1]['wNow'] (W)
			// - site.total_consumption_w: production['consumption'][0]['wNow'] (W)
			// - site.grid_power_w:    production['consumption'][1]['wNow'] (W)
			// - site.house_power_w:   prefer livedata mW paths (converted to W), then meters
			// - battery: soc_percent (inventory), battery_power_w (livedata/meters),
			//            sleep_enabled + led_status (inventory), status (derived via computeBatteryStatus)
			$prod = $data['data']['production'] ?? null;
			$meters = $data['data']['ivp/meters/readings'] ?? null;
			$livedata = $data['data']['ivp/livedata/status'] ?? null;
			$inventory = $data['data']['ivp/ensemble/inventory'] ?? null;
			$pvPower = is_array($prod) && isset($prod['production'][1]['wNow']) ? (float)$prod['production'][1]['wNow'] : null;
			$totalConsumption = is_array($prod) && isset($prod['consumption'][0]['wNow']) ? (float)$prod['consumption'][0]['wNow'] : null;
			$gridPower = is_array($prod) && isset($prod['consumption'][1]['wNow']) ? (float)$prod['consumption'][1]['wNow'] : null;
			$houseLoad = $this->extractHouseFromLivedata($livedata);
			if ($houseLoad === null) { $houseLoad = $this->extractHouseFromMeters($meters); }
			if ($houseLoad === null && is_numeric($totalConsumption)) {
				// last resort: use totalConsumption
				$houseLoad = $totalConsumption;
			}
			$battFlow = $this->extractBatteryFromLivedata($livedata);
			if ($battFlow === null) { $battFlow = $this->extractBatteryFromMeters($meters); }
			$soc = $this->extractSocFromInventory($inventory);
			list($sleepEnabled, $ledStatus) = $this->extractBatteryFlagsFromInventory($inventory);
			$statusNeutral = $this->computeBatteryStatus($soc, $sleepEnabled, $ledStatus);
			$data['energy'] = [
				'protocol' => 'com.maxence.energy.v1',
				'type' => 'energy.update',
				'timestamp' => date('c'),
				'source' => [ 'vendor' => 'Enphase', 'model' => 'Envoy' ],
				'data' => [
					'pv' => [ 'pv_power_w' => $pvPower ],
					'site' => [ 'house_power_w' => $houseLoad, 'grid_power_w' => $gridPower, 'total_consumption_w' => $totalConsumption ],
					'battery' => [ 'soc_percent' => $soc, 'battery_power_w' => $battFlow, 'sleep_enabled' => $sleepEnabled, 'led_status' => $ledStatus, 'status' => $statusNeutral ],
				],
			];
			$data = json_encode($data);
			$this->WriteAttributeString('pvdata', json_encode($data));
			$this->SendDataToChildren($data);
			$this->SetStatus(102);
		} catch (Exception $e) {
			$this->LogMessage("API Error: " . $e->getMessage(), KL_ERROR);
		}
	}

	private function findPath($arr, $path) {
		$ref = $arr;
		foreach ($path as $seg) {
			if (!is_array($ref) || !array_key_exists($seg, $ref)) return null;
			$ref = $ref[$seg];
		}
		return is_numeric($ref) ? (float)$ref : null;
	}

	private function extractHouseFromLivedata($livedata) {
		// Livedata values are typically reported in milli-watts (mW).
		// We try several known paths and convert found values to Watts (divide by 1000).
		if (!is_array($livedata)) return null;
		$paths = [ ['meters','load','p_mw'], ['meters','consumption','p_mw'], ['total','load','p_mw'], ['site','load','p_mw'], ['site','p_mw'], ['consumption','p_mw'], ['load','p_mw'] ];
		foreach ($paths as $p) { $v = $this->findPath($livedata, $p); if ($v !== null) return $v/1000.0; }
		return null;
	}

	private function extractBatteryFromLivedata($livedata) {
		// Similar to house load above, battery flow is in mW in livedata and converted to W.
		if (!is_array($livedata)) return null;
		$paths = [ ['meters','storage','p_mw'], ['total','storage','p_mw'], ['site','storage','p_mw'], ['storage','p_mw'] ];
		foreach ($paths as $p) { $v = $this->findPath($livedata, $p); if ($v !== null) return $v/1000.0; }
		return null;
	}

	private function extractHouseFromMeters($meters) {
		if (!is_array($meters)) return null;
		$collections = [];
		if (isset($meters['meters']) && is_array($meters['meters'])) $collections[] = $meters['meters']; else $collections[] = $meters;
		foreach (['consumption','totalConsumption','total_consumption','load','siteConsumption','site_consumption','site-consumption'] as $k) {
			if (isset($meters[$k]) && is_array($meters[$k])) $collections[] = $meters[$k];
		}
		foreach ($collections as $entries) {
			if (!is_array($entries)) continue;
			foreach ($entries as $entry) {
				$type = strtolower((string)($entry['measurementType'] ?? $entry['type'] ?? $entry['name'] ?? ''));
				if (str_contains($type,'net-consumption') || str_contains($type,'net_consumption')) continue;
				if (!(str_contains($type,'load') || str_contains($type,'consumption'))) continue;
				$val = $this->extractPowerValue($entry); if ($val !== null) return $val;
				if (isset($entry['channels'])) {
					foreach ($entry['channels'] as $ch) {
						$ct = strtolower((string)($ch['measurementType'] ?? $ch['type'] ?? $ch['name'] ?? ''));
						if (!(str_contains($ct,'load') || str_contains($ct,'consumption'))) continue;
						$val = $this->extractPowerValue($ch); if ($val !== null) return $val;
					}
				}
			}
		}
		$type = strtolower((string)($meters['measurementType'] ?? $meters['type'] ?? $meters['name'] ?? ''));
		if ($type !== '' && (str_contains($type,'load') || str_contains($type,'consumption'))) {
			$val = $this->extractPowerValue($meters); if ($val !== null) return $val;
		}
		return null;
	}

	private function extractBatteryFromMeters($meters) {
		if (!is_array($meters)) return null;
		$collections = [];
		if (isset($meters['meters']) && is_array($meters['meters'])) $collections[] = $meters['meters']; else $collections[] = $meters;
		foreach (['storage','battery','batteryStorage','storageSummary','encharge'] as $k) {
			if (isset($meters[$k]) && is_array($meters[$k])) $collections[] = $meters[$k];
		}
		foreach ($collections as $entries) {
			if (!is_array($entries)) continue;
			foreach ($entries as $entry) {
				$type = strtolower((string)($entry['measurementType'] ?? $entry['type'] ?? $entry['name'] ?? ''));
				if (!(str_contains($type,'storage') || str_contains($type,'battery') || str_contains($type,'encharge'))) continue;
				$val = $this->extractPowerValue($entry); if ($val !== null) return $val;
				if (isset($entry['channels'])) {
					foreach ($entry['channels'] as $ch) {
						$ct = strtolower((string)($ch['measurementType'] ?? $ch['type'] ?? $ch['name'] ?? ''));
						if (!(str_contains($ct,'storage') || str_contains($ct,'battery') || str_contains($ct,'encharge'))) continue;
						$val = $this->extractPowerValue($ch); if ($val !== null) return $val;
					}
				}
			}
		}
		$type = strtolower((string)($meters['measurementType'] ?? $meters['type'] ?? $meters['name'] ?? ''));
		if ($type !== '' && (str_contains($type,'storage') || str_contains($type,'battery') || str_contains($type,'encharge'))) {
			$val = $this->extractPowerValue($meters); if ($val !== null) return $val;
		}
		return null;
	}

	private function extractPowerValue($source) {
		$keys = ['activePower','averagePower','wNow','power'];
		foreach ($keys as $k) { if (isset($source[$k]) && is_numeric($source[$k])) return (float)$source[$k]; }
		return null;
	}

	private function extractSocFromInventory($inv) {
		// Inventory shape (typical): inventory[0]['devices'][0]['percentFull'] (SoC in %)
		if (!is_array($inv)) return null;
		try { return (float)$inv[0]['devices'][0]['percentFull']; } catch (Throwable $t) { return null; }
	}

	private function extractBatteryFlagsFromInventory($inv): array {
		// Returns [sleep_enabled|null, led_status|null]
		// Battery flags used to compute human-readable status. LED codes observed:
		// 12: Slowly flashing green  -> lädt
		// 13: Slowly flashing blue   -> entlädt
		// 14: Solid green            -> voll
		// 15: Solid green+blue       -> inaktiv (undocumented)
		// 16: Solid blue+green       -> inaktiv (undocumented)
		// 17: Solid blue             -> leer
		if (!is_array($inv)) return [null, null];
		try {
			$dev = $inv[0]['devices'][0] ?? null;
			if (!is_array($dev)) return [null, null];
			$sleep = isset($dev['sleep_enabled']) ? (bool)$dev['sleep_enabled'] : null;
			$led = isset($dev['led_status']) && is_numeric($dev['led_status']) ? (int)$dev['led_status'] : null;
			return [$sleep, $led];
		} catch (Throwable $t) {
			return [null, null];
		}
	}

	private function computeBatteryStatus(?float $soc, ?bool $sleep, ?int $led): ?string {
		if ($sleep === true) return 'Schlafmodus';
		if ($led === null) return null;
		// LED code mapping derived from observed inverter behavior
		$map = [
			12 => 'lädt',       // Slowly flashing green
			13 => 'entlädt',    // Slowly flashing blue
			14 => 'voll',       // Solid green
			15 => 'inaktiv',    // Solid green+blue (undocumented)
			16 => 'inaktiv',    // Solid blue+green (undocumented)
			17 => 'leer',       // Solid blue
		];
		$status = $map[$led] ?? ('unbekannt (' . (string)$led . ')');
		// Guard against inconsistent LED vs. SoC readings
		if ($status === 'voll' && $soc !== null && $soc < 100) {
			$status = 'inaktiv';
		} elseif ($status === 'leer' && $soc !== null && $soc > 5) {
			$status = 'inaktiv';
		}
		return $status;
	}

	protected function initApi()
	{
		$this->clearVariables();
		$config = $this->api->init($this->getConfig());
		$token = $config['token'];
		$this->setVariables($token);
		$this->WriteAttributeString('token', $token);
	}

	protected function clearVariables() {
		// no-op: token details are kept in attributes now
		$this->WriteAttributeString('token_serial', '');
		$this->WriteAttributeString('token_user', '');
		$this->WriteAttributeString('token_expiration', '');
		$this->WriteAttributeString('token_issue', '');
		$this->WriteAttributeString('token_access', '');
	}

	protected function setVariables($token) {
		$jwt = new JsonWebToken();
		$data = $jwt->getJwtData($token);
		$this->WriteAttributeString('token_serial', isset($data['serial']) ? (string)$data['serial'] : '');
		$this->WriteAttributeString('token_user', isset($data['user']) ? (string)$data['user'] : '');
		$this->WriteAttributeString('token_expiration', isset($data['expiration']) ? (string)$data['expiration'] : '');
		$this->WriteAttributeString('token_issue', isset($data['issue']) ? (string)$data['issue'] : '');
		$this->WriteAttributeString('token_access', isset($data['access']) ? (string)$data['access'] : '');
	}
	
	protected function GetConfig()
	{
		$config = [
			"host"     => $this->ReadPropertyString('host'),
			"serial"   => $this->ReadPropertyString('serial'),
			"user"     => $this->ReadPropertyString('user'),
			"password" => $this->ReadPropertyString('password'),
			"token"    => $this->ReadAttributeString('token'),
		];
		return $config;
	}

	protected function refreshToken(): bool
	{
		$this->LogMessage('Refreshing Enphase API token after unauthorized response.', KL_MESSAGE);
		$this->WriteAttributeString('token', '');
		try {
			$this->initApi();
			return true;
		} catch (Exception $e) {
			$this->LogMessage('Failed to refresh Enphase API token: ' . $e->getMessage(), KL_ERROR);
			return false;
		}
	}

	public function ForwardData($json) {
		try {
			$data = json_decode($json);
			$result = [];
			$result = $this->api->apiRequest($this->getConfig(), $data->endpoint);
			return $result['response'];
		} catch (Exception $e) {
			$this->LogMessage("API Error: " . $e->getMessage(), KL_ERROR);
			return '{}';
		}
	}
	
	public function GetConfigurationForm()
	{
		$form = [
			"elements" => [
				[
					"type" => "CheckBox",
					"name" => "enabled",
					"caption" => "Gateway enabled",
					"required" => true
				],
				[
					"type" => "ValidationTextBox",
					"name" => "host",
					"caption" => "Gateway Host",
					"required" => true
				],
				[
					"type" => "ValidationTextBox",
					"name" => "serial",
					"caption" => "Gateway Serial Number",
					"required" => true
				],
				[
					"type" => "ValidationTextBox",
					"name" => "user",
					"caption" => "Enphase User",
					"required" => true
				],
				[
					"type" => "PasswordTextBox",
					"name" => "password",
					"caption" => "Enphase Password",
					"required" => true
				],
				[
					"type" => "NumberSpinner",
					"name" => "updateInterval",
					"caption" => "Update Interval (seconds)",
					"required" => true
				],
				[ "type" => "Label", "caption" => "Token Details" ],
				[ "type" => "Label", "caption" => "User: " . $this->ReadAttributeString('token_user') ],
				[ "type" => "Label", "caption" => "Serial: " . $this->ReadAttributeString('token_serial') ],
				[ "type" => "Label", "caption" => "Access: " . $this->ReadAttributeString('token_access') ],
				[ "type" => "Label", "caption" => "Issue: " . $this->ReadAttributeString('token_issue') ],
				[ "type" => "Label", "caption" => "Expiration: " . $this->ReadAttributeString('token_expiration') ],
			],
			"actions" => [],
			"status" => [
				[
					"code" => 102,
					"icon" => "active",
					"caption" => "Enphase Gateway connected"
				],
				[
					"code" => 104,
					"icon" => "inactive",
					"caption" => "Enphase Gateway diconnected"
				]
			],
		];
	
		return json_encode($form);
	}
}
