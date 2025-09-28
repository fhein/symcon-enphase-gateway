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
		'ivp/production/powerflow',
	];

	public function __construct($InstanceID)
	{
		parent::__construct($InstanceID);
		$this->api = new EnphaseAPI();
	}

	public function Create()
    {
        parent::Create();
		$this->RegisterAttributeBoolean('powerflowUnavailable', false);
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
		$this->WriteAttributeBoolean('powerflowUnavailable', false);
		
		// Initialize the Enphase API
		try {
			$this->initApi();
			$enabled = $this->ReadPropertyBoolean('enabled');
			$updateInterval = $enabled ? $this->ReadPropertyInteger('updateInterval') : 0;
			$this->SetTimerInterval('Update', $updateInterval * 1000);
			$this->SetStatus(102); // Set status to active if successful
		} catch (Exception $e) {
			$this->SetStatus(104); // Set an error status if initialization fails
			$this->LogMessage("Error initializing Enphase Gateway: " . $e->getMessage(), KL_ERROR);
		}		
	}

	public function Update() {
		$tokenRefreshed = false;
		try {
			$data = [ 'DataID' => $this->GetBuffer('EnphaseGateway.ChildRequirements') ] ;
			$powerflowUnavailable = $this->ReadAttributeBoolean('powerflowUnavailable');
			foreach ($this->endpoints as $endpoint) {
				$isPowerflowEndpoint = ($endpoint === 'ivp/production/powerflow');
				if ($isPowerflowEndpoint && $powerflowUnavailable) {
					$data['data'][$endpoint] = null;
					continue;
				}
				$result = $this->api->apiRequest($this->getConfig(), $endpoint);
				if ($result['httpStatus'] === 401) {
					if (!$tokenRefreshed && $this->refreshToken()) {
						$tokenRefreshed = true;
						$result = $this->api->apiRequest($this->getConfig(), $endpoint);
					}
					if ($result['httpStatus'] === 401) {
						$this->LogMessage(sprintf('API endpoint %s returned unauthorized (401).', $endpoint), KL_WARNING);
						$this->SetStatus(104);
						$data['data'][$endpoint] = null;
						continue;
					}
				}
				if ($isPowerflowEndpoint && $result['httpStatus'] === 404) {
					if (!$powerflowUnavailable) {
						$this->LogMessage('Powerflow endpoint returned 404. Skipping further requests until the gateway configuration changes or the endpoint becomes available again.', KL_MESSAGE);
					}
					$powerflowUnavailable = true;
					$this->WriteAttributeBoolean('powerflowUnavailable', true);
					$data['data'][$endpoint] = null;
					continue;
				}
				if ($result['httpStatus'] !== 200) {
					$this->LogMessage(sprintf('API endpoint %s returned status %s', $endpoint, (string) $result['httpStatus']), KL_WARNING);
					$data['data'][$endpoint] = null;
					continue;
				}
				if ($isPowerflowEndpoint && $powerflowUnavailable) {
					$this->LogMessage('Powerflow endpoint is available again. Re-enabling requests.', KL_MESSAGE);
					$powerflowUnavailable = false;
					$this->WriteAttributeBoolean('powerflowUnavailable', false);
				}
				$data['data'][$endpoint] = json_decode($result['response'], true);
			}
			$data = json_encode($data);
			$this->WriteAttributeString('pvdata', json_encode($data));
			$this->SendDataToChildren($data);
			$this->SetStatus(102);
		} catch (Exception $e) {
			$this->LogMessage("API Error: " . $e->getMessage(), KL_ERROR);
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
		$keys = [ 'serial', 'user', 'expiration', 'issue', 'access' ];
		foreach ($keys as $key) {
			$this->SetValue($key, '');
		}
	}

	protected function setVariables($token) {
		$jwt = new JsonWebToken();
		$data = $jwt->getJwtData($token);
		$keys = [ 'serial', 'user', 'expiration', 'issue', 'access' ];
		foreach ($keys as $key) {
			$value = isset($data[$key]) ? $data[$key] : '';
			$this->SetValue($key, $value);
		}
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
