<?php
declare(strict_types=1);

/**
 * EnphaseGateway — pragmatic v2.2 (strict livedata, copy-safe)
 *
 * - Reads ONLY /ivp/livedata/status for PV/Load/Storage/Grid/SoC.
 * - Uses /ivp/ensemble/inventory ONLY for LED/Sleep (and optional weighted SoC fallback).
 * - Keeps vendor-neutral payload EXACTLY (com.maxence.energy.v1).
 * - site.total_consumption_w ONLY from /production (if available); otherwise NULL.
 * - No heuristics on house load by default; BUT includes a safe fix for firmware that reports Total at meters.load when charging.
 * - No literal control chars in source; regex uses escaped sequences (\xHH).
 */

class EnphaseAuthException extends Exception { }
class EnphaseCurlException extends Exception { }
class EnphaseHttpException extends Exception { }
class CurlException        extends Exception { }

require_once __DIR__ . '/EnphaseAPI.php';
require_once __DIR__ . '/../libs/ModuleRegistration.php';
require_once __DIR__ . '/../libs/JsonWebToken.php';

class EnphaseGateway extends IPSModule
{
    /** @var EnphaseAPI|null */
    protected $api = null;

    /**
     * Endpoints: livedata + inventory (primary), production (optional for total consumption).
     */
    protected $endpoints = [
        'ivp/livedata/status',
        'ivp/ensemble/inventory',
        'production', // optional, only for site.total_consumption_w
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
            $this->SetBuffer('EnphaseGateway.ChildRequirements', $moduleData['childRequirements'][0] ?? '');

            $mr = new EnphaseModuleRegistration($this);
            $config = include __DIR__ . '/module.config.php';
            $mr->Register($config);

            $this->RegisterTimer('Update', 0, 'ENPHASE_Update($_IPS["TARGET"]);');
        } catch (Exception $e) {
            $this->LogMessage(__CLASS__ . ': Error creating module: ' . $e->getMessage(), KL_ERROR);
        }
    }

    public function Destroy()
    {
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
        $this->WriteAttributeString('token', '');
        try {
            $this->initApi();
            $enabled = $this->ReadPropertyBoolean('enabled');
            $updateInterval = $enabled ? $this->ReadPropertyInteger('updateInterval') : 0;
            $this->SetTimerInterval('Update', $updateInterval * 1000);
            $this->cleanupLegacyVariables();
            $this->SetStatus(102);
        } catch (Exception $e) {
            $this->SetStatus(104);
            $this->LogMessage('Error initializing Enphase Gateway: ' . $e->getMessage(), KL_ERROR);
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

    /**
     * Main polling: fetch endpoints, build com.maxence.energy.v1, update vars, send to children.
     */
    public function Update()
    {
        $tokenRefreshed = false;
        try {
            $data = ['DataID' => $this->GetBuffer('EnphaseGateway.ChildRequirements')];

            // 1) Fetch endpoints and cache usable results
            foreach ($this->endpoints as $endpoint) {
                $result = $this->api->apiRequest($this->getConfig(), $endpoint);

                if (($result['httpStatus'] ?? 0) === 401) {
                    if (!$tokenRefreshed && $this->refreshToken()) {
                        $tokenRefreshed = true;
                        $result = $this->api->apiRequest($this->getConfig(), $endpoint);
                    }
                }

                if (($result['httpStatus'] ?? 0) !== 200) {
                    $this->LogMessage(sprintf('API endpoint %s returned status %s', $endpoint, (string)($result['httpStatus'] ?? 'n/a')), KL_WARNING);
                    $data['data'][$endpoint] = $this->loadCachedEndpointPayload($endpoint);
                    continue;
                }

                $decoded = $this->decodeJsonResponse($result['response'] ?? '');
                if ($decoded !== null) {
                    $this->persistEndpointPayload($endpoint, $decoded);
                    $this->debugJson('Endpoint ' . $endpoint, $decoded);
                    $data['data'][$endpoint] = $decoded;
                } else {
                    $this->SendDebug('EnphaseGateway', sprintf('Endpoint %s response not usable', $endpoint), 0);
                    $data['data'][$endpoint] = $this->loadCachedEndpointPayload($endpoint);
                }
            }

            // 2) Strict livedata mapping
            $livedata  = $data['data']['ivp/livedata/status']    ?? null;
            $inventory = $data['data']['ivp/ensemble/inventory'] ?? null;
            $prod      = $data['data']['production']             ?? null; // only for total_consumption

            $norm = [
                'pv_w'          => null,
                'load_w'        => null,
                'battery_w'     => null,
                'grid_w'        => null,
                'soc_percent'   => null,
                'battery_status'=> null,
                'sleep_enabled' => null,
                'led_status'    => null,
            ];

            if (is_array($livedata)) {
                $norm['pv_w']      = $this->liveW($livedata, ['meters','pv','agg_p_mw'],     ['meters','pv','agg_p_w'],     ['meters','pv','agg_p_kw']);
                $norm['load_w']    = $this->liveW($livedata, ['meters','load','agg_p_mw'],   ['meters','load','agg_p_w'],   ['meters','load','agg_p_kw']);
                $norm['battery_w'] = $this->liveW($livedata, ['meters','storage','agg_p_mw'],['meters','storage','agg_p_w'],['meters','storage','agg_p_kw']);
                $norm['grid_w']    = $this->liveW($livedata, ['meters','grid','agg_p_mw'],   ['meters','grid','agg_p_w'],   ['meters','grid','agg_p_kw']);

                // SoC from livedata (prefer meters.soc, fallback enc_agg_soc)
                if (isset($livedata['meters']['soc']) && is_numeric($livedata['meters']['soc'])) {
                    $norm['soc_percent'] = (float)$livedata['meters']['soc'];
                } elseif (isset($livedata['meters']['enc_agg_soc']) && is_numeric($livedata['meters']['enc_agg_soc'])) {
                    $norm['soc_percent'] = (float)$livedata['meters']['enc_agg_soc'];
                }

                // Battery status from sign (if no LED later)
                if ($norm['battery_w'] !== null) {
                    $norm['battery_status'] = $norm['battery_w'] > 0 ? 'entlädt' : ($norm['battery_w'] < 0 ? 'lädt' : 'inaktiv');
                }
            }

            // 3) Flags (LED/Sleep) from inventory, optional weighted SoC fallback
            if (is_array($inventory)) {
                $flags = $this->batteryFlagsFromInventory($inventory);
                $norm['sleep_enabled'] = $flags['sleep_enabled'];
                $norm['led_status']    = $flags['led_status'];
                if ($flags['led_status'] !== null) {
                    $norm['battery_status'] = $this->batteryStatusFromLed($flags['led_status'], $norm['soc_percent']);
                }
                if ($norm['soc_percent'] === null) {
                    $norm['soc_percent'] = $this->socFromInventoryWeighted($inventory);
                }
            }

            // 4) site.total_consumption_w ONLY from production (if present), else NULL
            $totalConsumption = $this->extractProductionValue($prod, 'consumption', ['total-consumption','consumption'], 0);

            // 4.1) Fix firmware variant: when battery is charging, some report meters.load as Total (House + charge).
            // Detect load ≈ pv + grid and correct to house = load - (-storage).
            if ($norm['pv_w'] !== null && $norm['grid_w'] !== null && $norm['battery_w'] !== null && $norm['load_w'] !== null) {
                $pv   = (float)$norm['pv_w'];
                $grid = (float)$norm['grid_w'];
                $stor = (float)$norm['battery_w'];
                $load = (float)$norm['load_w'];

                if ($stor < 0) { // charging
                    $T   = $pv + $grid;         // total site draw from PV+grid
                    $H   = $pv + $grid + $stor; // expected house load by balance
                    $tol = max(10.0, 0.05 * max(abs($load), abs($T), abs($H), 1.0));
                    $looksLikeTotal = (abs($load - $T) <= $tol) && (abs($load - $H) > $tol / 2);
                    if ($looksLikeTotal) {
                        $house = max(0.0, $load - (-$stor)); // H = T - C
                        $this->SendDebug('HouseFix', sprintf('Adjusted house load from %.1f W to %.1f W (battery charge %.1f W).', $load, $house, -$stor), 0);
                        $norm['load_w'] = $house;
                    }
                }
            }

            // 5) Build com.maxence.energy.v1 payload (unchanged shape)
            $payload = [
                'protocol' => 'com.maxence.energy.v1',
                'type'     => 'energy.update',
                'timestamp'=> date('c'),
                'source'   => [ 'vendor' => 'Enphase', 'model' => 'Envoy' ],
                'data'     => [
                    'pv'    => [ 'pv_power_w' => $norm['pv_w'] ],
                    'site'  => [
                        'house_power_w'       => $norm['load_w'],
                        'grid_power_w'        => $norm['grid_w'],
                        'total_consumption_w' => $totalConsumption,
                    ],
                    'battery' => [
                        'soc_percent'     => $norm['soc_percent'],
                        'battery_power_w' => $norm['battery_w'],
                        'sleep_enabled'   => $norm['sleep_enabled'],
                        'led_status'      => $norm['led_status'],
                        'status'          => $norm['battery_status'],
                    ],
                ],
            ];

            // 6) Update exposed variables & send to children
            $this->updateString('energyTimestamp', $payload['timestamp']);
            $this->updateString('sourceVendor', $payload['source']['vendor']);
            $this->updateString('sourceModel',  $payload['source']['model']);

            $this->updateFloat('pvPower',             $norm['pv_w']);
            $this->updateFloat('siteHousePower',      $norm['load_w']);
            $this->updateFloat('siteGridPower',       $norm['grid_w']);
            $this->updateFloat('siteTotalConsumption',$totalConsumption);
            $this->updateFloat('batterySoc',          $norm['soc_percent']);
            $this->updateFloat('batteryPower',        $norm['battery_w']);
            $this->updateInteger('batteryLedStatus',  $norm['led_status']);
            $this->updateString('batteryStatus',      $norm['battery_status']);

            $this->SendDebug('EnergyPayload', sprintf(
                'pv=%s W, house=%s W, grid=%s W, total=%s W, battery=%s W, soc=%s %%',
                $this->fmt($norm['pv_w']),
                $this->fmt($norm['load_w']),
                $this->fmt($norm['grid_w']),
                $this->fmt($totalConsumption),
                $this->fmt($norm['battery_w']),
                $norm['soc_percent'] !== null ? number_format((float)$norm['soc_percent'], 2, ',', '.') : 'null'
            ), 0);

            $data['energy'] = $payload;
            $encoded = json_encode($data);
            $this->WriteAttributeString('pvdata', $encoded !== false ? $encoded : '{}');
            $this->SendDataToChildren($encoded !== false ? $encoded : '{}');

            $this->SetStatus(102);
        } catch (Exception $e) {
            $this->LogMessage('API Error: ' . $e->getMessage(), KL_ERROR);
        }
    }

    /** ----------------------- livedata helpers -------------------------- */

    private function liveW(array $arr, array $pathMw, array $pathW = [], array $pathkW = []): ?float
    {
        $v = $this->findPath($arr, $pathMw);
        if (is_numeric($v)) return (float)$v / 1000.0; // mW -> W
        if ($pathW) {
            $v = $this->findPath($arr, $pathW);
            if (is_numeric($v)) return (float)$v;      // W
        }
        if ($pathkW) {
            $v = $this->findPath($arr, $pathkW);
            if (is_numeric($v)) return (float)$v * 1000.0; // kW -> W
        }
        return null;
    }

    private function findPath($arr, $path)
    {
        $ref = $arr;
        foreach ($path as $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) return null;
            $ref = $ref[$seg];
        }
        return is_numeric($ref) ? (float)$ref : null;
    }

    /** ----------------------- production helper ------------------------- */

    private function extractProductionValue($prod, string $section, array $preferredTypes = [], ?int $fallbackIndex = null): ?float
    {
        if (!is_array($prod) || !isset($prod[$section]) || !is_array($prod[$section])) return null;
        $entries = array_filter($prod[$section], 'is_array');
        foreach ($preferredTypes as $targetType) {
            $targetType = strtolower($targetType);
            foreach ($entries as $entry) {
                $type = strtolower((string)($entry['measurementType'] ?? $entry['type'] ?? ''));
                if ($type === $targetType) {
                    $val = $this->extractPowerValue($entry);
                    if ($val !== null) return $val;
                }
            }
        }
        if ($fallbackIndex !== null && isset($prod[$section][$fallbackIndex]) && is_array($prod[$section][$fallbackIndex])) {
            $val = $this->extractPowerValue($prod[$section][$fallbackIndex]);
            if ($val !== null) return $val;
        }
        foreach ($entries as $entry) {
            $val = $this->extractPowerValue($entry);
            if ($val !== null) return $val;
        }
        return null;
    }

    private function extractPowerValue($source): ?float
    {
        if (!is_array($source)) return null;
        $keys = ['activePower','averagePower','wNow','power','realPower','real_power','pNow','p_now','p','p_w','p_kw','p_mw','agg_p','agg_p_w','agg_p_kw','agg_p_mw','instantaneousDemand'];
        foreach ($keys as $k) {
            if (isset($source[$k]) && is_numeric($source[$k])) {
                $value = (float)$source[$k];
                if ($k === 'p_kw' || $k === 'agg_p_kw') return $value * 1000.0;
                if ($k === 'p_mw' || $k === 'agg_p_mw') return $value / 1000.0;
                return $value; // assume W
            }
        }
        return null;
    }

    /** ----------------------- inventory helpers ------------------------- */

    private function batteryFlagsFromInventory(array $inv): array
    {
        // Returns ['sleep_enabled'=>bool|null, 'led_status'=>int|null]
        $dev = $inv[0]['devices'][0] ?? null;
        if (!is_array($dev)) return ['sleep_enabled'=>null, 'led_status'=>null];
        return [
            'sleep_enabled' => isset($dev['sleep_enabled']) ? (bool)$dev['sleep_enabled'] : null,
            'led_status'    => (isset($dev['led_status']) && is_numeric($dev['led_status'])) ? (int)$dev['led_status'] : null,
        ];
    }

    private function batteryStatusFromLed(int $led, ?float $soc): string
    {
        $map = [ 12 => 'lädt', 13 => 'entlädt', 14 => 'voll', 15 => 'inaktiv', 16 => 'inaktiv', 17 => 'leer' ];
        $status = $map[$led] ?? ('unbekannt (' . (string)$led . ')');
        if ($status === 'voll' && $soc !== null && $soc < 100) $status = 'inaktiv';
        if ($status === 'leer' && $soc !== null && $soc > 5)   $status = 'inaktiv';
        return $status;
    }

    private function socFromInventoryWeighted(array $inv): ?float
    {
        $packs = $inv[0]['devices'] ?? null;
        if (!is_array($packs) || !$packs) return null;
        $num = 0.0; $den = 0.0;
        foreach ($packs as $p) {
            if (!isset($p['percentFull']) || !is_numeric($p['percentFull'])) continue;
            $soc   = (float)$p['percentFull'];
            $capWh = isset($p['nominal_energy_wh']) && is_numeric($p['nominal_energy_wh']) ? (float)$p['nominal_energy_wh'] : 1.0;
            $num += $soc * $capWh;
            $den += $capWh;
        }
        return $den > 0 ? $num / $den : null;
    }

    /** ------------------------- caching helpers -------------------------- */

    private function persistEndpointPayload(string $endpoint, $payload): void
    {
        $map = [
            'production'               => 'raw_production',
            'ivp/ensemble/inventory'   => 'raw_ivp_ensemble_inventory',
            'ivp/livedata/status'      => 'raw_ivp_livedata_status',
        ];
        if (!isset($map[$endpoint])) return;
        try {
            if ($payload === null) return;
            $encoded = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) return;
            $this->WriteAttributeString($map[$endpoint], $encoded);
        } catch (Throwable $t) {
            $this->SendDebug('EnphaseGateway', sprintf('persistEndpointPayload(%s) failed: %s', $endpoint, $t->getMessage()), 0);
        }
    }

    private function loadCachedEndpointPayload(string $endpoint)
    {
        $map = [
            'production'               => 'raw_production',
            'ivp/ensemble/inventory'   => 'raw_ivp_ensemble_inventory',
            'ivp/livedata/status'      => 'raw_ivp_livedata_status',
        ];
        if (!isset($map[$endpoint])) return null;
        try {
            $json = $this->ReadAttributeString($map[$endpoint]);
            $decoded = $this->decodeJsonResponse($json);
            if ($decoded === null || $decoded === []) return null;
            return $decoded;
        } catch (Throwable $t) {
            return null;
        }
    }

    /** --------------------------- utilities ----------------------------- */

    private function decodeJsonResponse($raw)
    {
        if (!is_string($raw)) return null;
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed === 'null') return null;

        $attempts = [];
        $attempts[] = $trimmed;
        // Remove trailing NULs (escaped sequence in regex; no literal NULs here)
        $attempts[] = preg_replace('/\x00+$/', '', $trimmed);
        // Remove ASCII control chars (0–8, 11, 12, 14–31)
        $attempts[] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $trimmed);

        foreach ($attempts as $candidate) {
            if (!is_string($candidate) || $candidate === '') continue;
            $decoded = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) return $decoded;
        }
        $this->SendDebug('EnphaseGateway', 'decodeJsonResponse failed: ' . json_last_error_msg(), 0);
        return null;
    }

    private function fmt($v): string { return $v === null ? 'null' : number_format((float)$v, 2, ',', '.'); }

    /** --------------------- API/token plumbing --------------------------- */

    protected function initApi()
    {
        $this->clearVariables();
        $config = $this->api->init($this->getConfig());
        $token  = $config['token'] ?? '';
        $this->setVariables($token);
        $this->WriteAttributeString('token', $token);
    }

    protected function clearVariables()
    {
        $this->WriteAttributeString('token_serial', '');
        $this->WriteAttributeString('token_user', '');
        $this->WriteAttributeString('token_expiration', '');
        $this->WriteAttributeString('token_issue', '');
        $this->WriteAttributeString('token_access', '');
    }

    protected function setVariables($token)
    {
        $jwt  = new JsonWebToken();
        $data = $jwt->getJwtData($token);
        $this->WriteAttributeString('token_serial', isset($data['serial']) ? (string)$data['serial'] : '');
        $this->WriteAttributeString('token_user', isset($data['user']) ? (string)$data['user'] : '');
        $this->WriteAttributeString('token_expiration', isset($data['expiration']) ? (string)$data['expiration'] : '');
        $this->WriteAttributeString('token_issue', isset($data['issue']) ? (string)$data['issue'] : '');
        $this->WriteAttributeString('token_access', isset($data['access']) ? (string)$data['access'] : '');
    }

    protected function getConfig(): array
    {
        return [
            'host'     => $this->ReadPropertyString('host'),
            'serial'   => $this->ReadPropertyString('serial'),
            'user'     => $this->ReadPropertyString('user'),
            'password' => $this->ReadPropertyString('password'),
            'token'    => $this->ReadAttributeString('token'),
        ];
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

    public function ForwardData($json)
    {
        try {
            $data = json_decode($json);
            $result = $this->api->apiRequest($this->getConfig(), $data->endpoint);
            return $result['response'] ?? '{}';
        } catch (Exception $e) {
            $this->LogMessage('API Error: ' . $e->getMessage(), KL_ERROR);
            return '{}';
        }
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                [ 'type' => 'CheckBox',          'name' => 'enabled',  'caption' => 'Gateway enabled',           'required' => true ],
                [ 'type' => 'ValidationTextBox', 'name' => 'host',     'caption' => 'Gateway Host',              'required' => true ],
                [ 'type' => 'ValidationTextBox', 'name' => 'serial',   'caption' => 'Gateway Serial Number',     'required' => true ],
                [ 'type' => 'ValidationTextBox', 'name' => 'user',     'caption' => 'Enphase User',              'required' => true ],
                [ 'type' => 'PasswordTextBox',   'name' => 'password', 'caption' => 'Enphase Password',          'required' => true ],
                [ 'type' => 'NumberSpinner',     'name' => 'updateInterval', 'caption' => 'Update Interval (seconds)', 'required' => true ],
                [ 'type' => 'Label', 'caption' => 'Token Details' ],
                [ 'type' => 'Label', 'caption' => 'User: ' . $this->ReadAttributeString('token_user') ],
                [ 'type' => 'Label', 'caption' => 'Serial: ' . $this->ReadAttributeString('token_serial') ],
                [ 'type' => 'Label', 'caption' => 'Access: ' . $this->ReadAttributeString('token_access') ],
                [ 'type' => 'Label', 'caption' => 'Issue: ' . $this->ReadAttributeString('token_issue') ],
                [ 'type' => 'Label', 'caption' => 'Expiration: ' . $this->ReadAttributeString('token_expiration') ],
            ],
            'actions' => [],
            'status'  => [
                [ 'code' => 102, 'icon' => 'active',   'caption' => 'Enphase Gateway connected' ],
                [ 'code' => 104, 'icon' => 'inactive', 'caption' => 'Enphase Gateway disconnected' ],
            ],
        ];
        return json_encode($form);
    }

    /** ---------------- Var setters (safe) ------------------------------- */

    private function updateString(string $ident, ?string $value): void
    {
        if ($value === null) return;
        $this->setModuleValue($ident, (string)$value);
    }

    private function updateFloat(string $ident, $value): void
    {
        if ($value === null || !is_finite((float)$value)) return;
        $this->setModuleValue($ident, (float)$value);
    }

    private function updateInteger(string $ident, ?int $value): void
    {
        if ($value === null) return;
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

    private function debugJson(string $label, $data, int $limit = 1024): void
    {
        try {
            $encoded = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) { $encoded = '<<encoding failed>>'; }
            if (strlen($encoded) > $limit) { $encoded = substr($encoded, 0, $limit) . '…'; }
            $this->SendDebug($label, $encoded, 0);
        } catch (Throwable $t) {
            $this->SendDebug('EnphaseGateway', '<<debugJson error: ' . $t->getMessage() . '>>', 0);
        }
    }
}
