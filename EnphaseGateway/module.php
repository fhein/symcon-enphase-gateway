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
			$mr = new EnphaseModuleRegistration($this);
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
			$mr = new EnphaseModuleRegistration($this);
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

	private function cleanupLegacyVariables(): void
	{
		foreach (['user','serial','access','issue','expiration','energyPayload','batterySleepEnabled'] as $ident) {
			try {
				if (@$this->GetIDForIdent($ident)) {
					@$this->UnregisterVariable($ident);
				}
			} catch (Throwable $t) {
				// ignore legacy cleanup issues
			}
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
					if ($result['httpStatus'] === 401) {
						$this->LogMessage(sprintf('API endpoint %s returned unauthorized (401).', $endpoint), KL_WARNING);
						$this->SetStatus(104);
						$data['data'][$endpoint] = $this->loadCachedEndpointPayload($endpoint);
						continue;
					}
				}
				if ($result['httpStatus'] !== 200) {
					$this->LogMessage(sprintf('API endpoint %s returned status %s', $endpoint, (string) $result['httpStatus']), KL_WARNING);
					$data['data'][$endpoint] = $this->loadCachedEndpointPayload($endpoint);
					continue;
				}
				$decoded = $this->decodeJsonResponse($result['response']);
				if ($decoded !== null) {
					$this->persistEndpointPayload($endpoint, $decoded);
					$this->debugJson('Endpoint ' . $endpoint, $decoded);
					$data['data'][$endpoint] = $decoded;
					continue;
				}
				$this->SendDebug('EnphaseGateway', sprintf('Endpoint %s response not usable: %s', $endpoint, substr((string)$result['response'], 0, 256)), 0);
				$cached = $this->loadCachedEndpointPayload($endpoint);
				if ($cached !== null) {
					$this->debugJson('Endpoint ' . $endpoint . ' (cached)', $cached);
				}
				$data['data'][$endpoint] = $cached;
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
			$pvPower = $this->extractProductionValue($prod, 'production', ['production', 'current-production'], 1);
			$totalConsumption = $this->extractProductionValue($prod, 'consumption', ['total-consumption', 'consumption'], 0);
			$gridPower = $this->extractProductionValue($prod, 'consumption', ['net-consumption', 'grid'], 1);
			$battFlow = $this->extractBatteryFromLivedata($livedata);
			if ($battFlow === null) { $battFlow = $this->extractBatteryFromMeters($meters); }
			if ($battFlow === null) { $battFlow = $this->extractProductionValue($prod, 'storage', ['battery', 'storage']); }
			$houseLoad = $this->extractHouseFromLivedata($livedata);
			if ($houseLoad === null) { $houseLoad = $this->extractHouseFromMeters($meters); }
			if ($houseLoad === null && is_numeric($totalConsumption)) {
				$houseLoad = $totalConsumption;
			}
			$houseAdjustment = $this->adjustHouseLoadForBattery($houseLoad, $battFlow);
			$houseLoad = $houseAdjustment['house'];
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
			$this->updateString('energyTimestamp', $data['energy']['timestamp']);
			$this->updateString('sourceVendor', $data['energy']['source']['vendor'] ?? '');
			$this->updateString('sourceModel', $data['energy']['source']['model'] ?? '');
			$this->updateFloat('pvPower', $pvPower);
			$this->updateFloat('siteHousePower', $houseLoad);
			$this->updateFloat('siteGridPower', $gridPower);
			$this->updateFloat('siteTotalConsumption', $totalConsumption);
			$this->updateFloat('batterySoc', $soc);
			$this->updateFloat('batteryPower', $battFlow);
			$this->updateInteger('batteryLedStatus', $ledStatus);
			$this->updateString('batteryStatus', $statusNeutral);
			$this->SendDebug('EnergyPayload', sprintf('pv=%s W, house=%s W, grid=%s W, total=%s W, battery=%s W, soc=%s %%',
				$this->formatDebugValue($pvPower),
				$this->formatDebugValue($houseLoad),
				$this->formatDebugValue($gridPower),
				$this->formatDebugValue($totalConsumption),
				$this->formatDebugValue($battFlow),
				$this->formatDebugValue($soc)
			), 0);
			if ($houseAdjustment['removed'] > 0) {
				$this->SendDebug('HouseAdjust', sprintf('Removed %.2f W charging component from house load', $houseAdjustment['removed']), 0);
			}
			$data = json_encode($data);
			$this->WriteAttributeString('pvdata', $data);
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

	private function extractProductionValue($prod, string $section, array $preferredTypes = [], ?int $fallbackIndex = null): ?float
	{
		if (!is_array($prod) || !isset($prod[$section]) || !is_array($prod[$section])) {
			return null;
		}
		$entries = array_filter($prod[$section], 'is_array');
		foreach ($preferredTypes as $targetType) {
			$targetType = strtolower($targetType);
			foreach ($entries as $entry) {
				$type = strtolower((string)($entry['measurementType'] ?? $entry['type'] ?? ''));
				if ($type === $targetType) {
					$val = $this->extractPowerValue($entry);
					if ($val !== null) {
						return $val;
					}
				}
			}
		}
		foreach ($entries as $entry) {
			$val = $this->extractPowerValue($entry);
			if ($val !== null) {
				return $val;
			}
		}
		if ($fallbackIndex !== null && isset($prod[$section][$fallbackIndex]) && is_array($prod[$section][$fallbackIndex])) {
			$val = $this->extractPowerValue($prod[$section][$fallbackIndex]);
			if ($val !== null) {
				return $val;
			}
		}
		return null;
	}

	private function extractHouseFromLivedata($livedata) {
		if (!is_array($livedata)) return null;
		$paths = [
			[['meters','total','consumption','agg_p_mw'], 'milliwatt'],
			[['meters','total','consumption','agg_p_w'], 'watt'],
			[['meters','total','consumption','agg_p_kw'], 'kilowatt'],
			[['total','consumption','agg_p_mw'], 'milliwatt'],
			[['total','consumption','agg_p_w'], 'watt'],
			[['total','consumption','agg_p_kw'], 'kilowatt'],
			[['summary','consumption','agg_p_mw'], 'milliwatt'],
			[['summary','consumption','agg_p_w'], 'watt'],
			[['summary','consumption','agg_p_kw'], 'kilowatt'],
			[['meters','consumption','agg_p_mw'], 'milliwatt'],
			[['meters','consumption','agg_p_w'], 'watt'],
			[['meters','consumption','agg_p_kw'], 'kilowatt'],
			[['consumption','agg_p_mw'], 'milliwatt'],
			[['consumption','agg_p_w'], 'watt'],
			[['consumption','agg_p_kw'], 'kilowatt'],
			[['meters','total','consumption','p_mw'], 'milliwatt'],
			[['total','consumption','p_mw'], 'milliwatt'],
			[['meters','consumption','p_mw'], 'milliwatt'],
			[['consumption','p_mw'], 'milliwatt'],
			[['site','consumption','p_mw'], 'milliwatt'],
			[['summary','consumption','p_mw'], 'milliwatt'],
			[['meters','total','consumption','p_w'], 'watt'],
			[['meters','consumption','p_w'], 'watt'],
			[['consumption','p_w'], 'watt'],
			[['summary','consumption','real_power'], 'watt'],
			[['total','consumption','real_power'], 'watt'],
			[['consumption','real_power'], 'watt'],
			[['meters','total','load','agg_p_mw'], 'milliwatt'],
			[['meters','load','agg_p_mw'], 'milliwatt'],
			[['load','agg_p_mw'], 'milliwatt'],
			[['load','agg_p_w'], 'watt'],
			[['load','agg_p_kw'], 'kilowatt'],
			[['meters','total','load','p_mw'], 'milliwatt'],
			[['meters','load','p_mw'], 'milliwatt'],
			[['total','load','p_mw'], 'milliwatt'],
			[['site','load','p_mw'], 'milliwatt'],
			[['load','p_mw'], 'milliwatt'],
			[['load','p_w'], 'watt'],
			[['load','real_power'], 'watt'],
			[['load','p_kw'], 'kilowatt'],
			[['summary','load','agg_p_mw'], 'milliwatt'],
			[['summary','load','agg_p_w'], 'watt'],
			[['summary','load','agg_p_kw'], 'kilowatt'],
		];
		foreach ($paths as [$path, $unit]) {
			$v = $this->findPath($livedata, $path);
			if ($v !== null) {
				return $this->convertLivePower($v, $unit);
			}
		}
		return null;
	}

	private function extractBatteryFromLivedata($livedata) {
		if (!is_array($livedata)) return null;
		$paths = [
			[['meters','total','storage','agg_p_mw'], 'milliwatt'],
			[['meters','total','storage','agg_p_w'], 'watt'],
			[['meters','total','storage','agg_p_kw'], 'kilowatt'],
			[['meters','storage','agg_p_mw'], 'milliwatt'],
			[['meters','storage','agg_p_w'], 'watt'],
			[['meters','storage','agg_p_kw'], 'kilowatt'],
			[['summary','storage','agg_p_mw'], 'milliwatt'],
			[['summary','storage','agg_p_w'], 'watt'],
			[['summary','storage','agg_p_kw'], 'kilowatt'],
			[['storage','agg_p_mw'], 'milliwatt'],
			[['storage','agg_p_w'], 'watt'],
			[['storage','agg_p_kw'], 'kilowatt'],
			[['meters','total','storage','p_mw'], 'milliwatt'],
			[['meters','storage','p_mw'], 'milliwatt'],
			[['total','storage','p_mw'], 'milliwatt'],
			[['site','storage','p_mw'], 'milliwatt'],
			[['summary','storage','p_mw'], 'milliwatt'],
			[['storage','p_mw'], 'milliwatt'],
			[['storage','p_w'], 'watt'],
			[['storage','real_power'], 'watt'],
			[['storage','p_kw'], 'kilowatt'],
			[['battery','agg_p_mw'], 'milliwatt'],
			[['battery','agg_p_w'], 'watt'],
			[['battery','agg_p_kw'], 'kilowatt'],
			[['battery','p_mw'], 'milliwatt'],
			[['battery','p_w'], 'watt'],
		];
		foreach ($paths as [$path, $unit]) {
			$v = $this->findPath($livedata, $path);
			if ($v !== null) {
				return $this->convertLivePower($v, $unit);
			}
		}
		return null;
	}

	private function convertLivePower(float $value, string $unit): float
	{
		switch ($unit) {
			case 'milliwatt':
				return $value / 1000.0;
			case 'kilowatt':
				return $value * 1000.0;
			default:
				return $value;
		}
	}

	private function decodeJsonResponse($raw)
	{
		if (!is_string($raw)) {
			return null;
		}
		$trimmed = trim($raw);
		if ($trimmed === '' || $trimmed === 'null') {
			return null;
		}
		$attempts = [];
		$attempts[] = $trimmed;
		$attempts[] = rtrim($trimmed, "\0");
		$attempts[] = utf8_encode($trimmed);
		$attempts[] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $trimmed);
		foreach ($attempts as $candidate) {
			if (!is_string($candidate) || $candidate === '') {
				continue;
			}
			$decoded = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING);
			if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
				return $decoded;
			}
		}
		$this->SendDebug('EnphaseGateway', sprintf('decodeJsonResponse failed: %s', json_last_error_msg()), 0);
		return null;
	}

	private function adjustHouseLoadForBattery(?float $house, ?float $battery): array
	{
		$result = [
			'house' => $house,
			'removed' => 0.0,
		];
		if ($house === null || !is_finite($house) || $battery === null || !is_finite($battery)) {
			return $result;
		}
		if ($battery < 0) {
			$removed = min($house, abs($battery));
			$result['house'] = max(0.0, $house + $battery);
			$result['removed'] = max(0.0, $removed);
		}
		return $result;
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
		if (!is_array($source)) {
			return null;
		}
		$keys = ['activePower','averagePower','wNow','power','realPower','real_power','pNow','p_now','p','p_w','p_kw','p_mw','agg_p','agg_p_w','agg_p_kw','agg_p_mw','instantaneousDemand'];
		foreach ($keys as $k) {
			if (isset($source[$k]) && is_numeric($source[$k])) {
				$value = (float)$source[$k];
				if ($k === 'p_kw' || $k === 'agg_p_kw') {
					return $value * 1000.0;
				} elseif ($k === 'p_mw' || $k === 'agg_p_mw') {
					return $value / 1000.0;
				}
				return $value;
			}
		}
		return null;
	}

	private function extractSocFromInventory($inv) {
		// Inventory shape (typical): inventory[0]['devices'][0]['percentFull'] (SoC in %)
		if (!is_array($inv)) return null;
		try { return (float)$inv[0]['devices'][0]['percentFull']; } catch (Throwable $t) { return null; }
	}

	private function resolveHouseLoad(?float $live, ?float $total, ?float $pv, ?float $grid, ?float $battery): ?float
	{
		$best = null;
		if ($total !== null && is_finite($total)) {
			$best = $total;
		}
		if ($live !== null && is_finite($live) && $live > 0) {
			if ($best === null) {
				$best = $live;
			} else {
				$ratio = ($best == 0) ? null : abs($live - $best) / max(abs($best), 1);
				if ($ratio !== null && $ratio > 0.35) {
					$best = max($best, $live);
				} else {
					$best = ($best + $live) / 2;
				}
			}
		}
		if ($best !== null && $battery !== null && is_finite($battery) && abs($battery) > ($best * 1.35)) {
			$best = abs($battery);
		} elseif ($best === null && $battery !== null && is_finite($battery)) {
			$best = abs($battery);
		}
		if ($best === null && ($pv !== null || $grid !== null || $battery !== null)) {
			$calc = 0.0;
			if ($pv !== null && is_finite($pv)) {
				$calc += max(0.0, $pv);
			}
			if ($grid !== null && is_finite($grid)) {
				$calc += max(0.0, $grid);
			}
			if ($battery !== null && is_finite($battery)) {
				$calc += abs($battery);
			}
			if ($calc > 0) {
				$best = $calc;
			}
		}
		return $best;
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

	private function updateString(string $ident, ?string $value): void
	{
		if ($value === null) {
			return;
		}
		$this->setModuleValue($ident, (string)$value);
	}

	private function updateFloat(string $ident, ?float $value): void
	{
		if ($value === null || !is_finite($value)) {
			return;
		}
		$this->setModuleValue($ident, (float)$value);
	}

	private function updateInteger(string $ident, ?int $value): void
	{
		if ($value === null) {
			return;
		}
		$this->setModuleValue($ident, (int)$value);
	}

	private function setModuleValue(string $ident, $value): void
	{
		try {
			$this->SetValue($ident, $value);
		} catch (Throwable $t) {
			$this->SendDebug('EnphaseGateway', sprintf('Failed to set variable %s: %s', $ident, $t->getMessage()), 0);
		}
	}

	private function formatDebugValue($value): string
	{
		if ($value === null) {
			return 'null';
		}
		if (is_float($value)) {
			return number_format($value, 2, ',', '.');
		}
		return (string)$value;
	}

	private function debugJson(string $label, $data, int $limit = 512): void
	{
		try {
			$encoded = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
			if ($encoded === false) {
				$encoded = '<<encoding failed>>';
			}
			if (strlen($encoded) > $limit) {
				$encoded = substr($encoded, 0, $limit) . '…';
			}
			$this->SendDebug($label, $encoded, 0);
		} catch (Throwable $t) {
			$this->SendDebug($label, '<<debugJson error: ' . $t->getMessage() . '>>', 0);
		}
	}

	private function persistEndpointPayload(string $endpoint, $payload): void
	{
		$map = [
			'production' => 'raw_production',
			'ivp/ensemble/inventory' => 'raw_ivp_ensemble_inventory',
			'ivp/meters/readings' => 'raw_ivp_meters_readings',
			'ivp/livedata/status' => 'raw_ivp_livedata_status',
		];
		if (!isset($map[$endpoint])) {
			return;
		}
		try {
			if ($payload === null) {
				return;
			}
			$encoded = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
			if ($encoded === false) {
				return;
			}
			$this->WriteAttributeString($map[$endpoint], $encoded);
		} catch (Throwable $t) {
			$this->SendDebug('EnphaseGateway', sprintf('persistEndpointPayload(%s) failed: %s', $endpoint, $t->getMessage()), 0);
		}
	}

	private function loadCachedEndpointPayload(string $endpoint)
	{
		$map = [
			'production' => 'raw_production',
			'ivp/ensemble/inventory' => 'raw_ivp_ensemble_inventory',
			'ivp/meters/readings' => 'raw_ivp_meters_readings',
			'ivp/livedata/status' => 'raw_ivp_livedata_status',
		];
		if (!isset($map[$endpoint])) {
			return null;
		}
		try {
			$json = $this->ReadAttributeString($map[$endpoint]);
			$decoded = $this->decodeJsonResponse($json);
			if ($decoded === null || $decoded === []) {
				return null;
			}
			return $decoded;
		} catch (Throwable $t) {
			return null;
		}
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
