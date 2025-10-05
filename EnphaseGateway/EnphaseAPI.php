<?php

class EnphaseAPI
{
    const EXCEPTIONS = [
        1001 => "Could not get a valid token.",
        1002 => "HTTP error. Status code: ",
        1003 => "Invalid configuration.",
        1004 => "Gateway host not set.",
        1005 => "Gateway host not reachable.",
        1006 => "User, password, and serial number not properly set.",
        1007 => "CURL error: ",
        1008 => "API response error.",
        1009 => "Unable to retrieve token.",
        1010 => "Invalid token."
    ];

    public function init($config) {
        $this->validateConfig($config);

        if (! $this->verifyApiToken($config)) {
            $token = $this->getApiToken($config);
            $config['token'] = $token;
            if (! $this->verifyApiToken($config)) {
                throw new Exception(self::EXCEPTIONS[1001], 1001);
            } 
        }
        return $config;
    }

    protected function verifyApiToken($config)
    {
        if (empty($config['token'])) {
            return false;
        }
        
        $result = $this->apiRequest($config, 'info');

        if ($result['httpStatus'] == 401) return false;
        if ($result['httpStatus'] != 200) {
            throw new Exception(self::EXCEPTIONS[1002] . $result['httpStatus'], 1002);
        }
        return true;
    }

    protected function validateConfig($config)
    {
        if (! is_array($config)) {
            throw new Exception(self::EXCEPTIONS[1003], 1003);
        }
        if (empty($config['host'])) {
            throw new Exception(self::EXCEPTIONS[1004], 1004);
        }
        
        if (! $this->isHost($config['host'])) {
            throw new Exception(self::EXCEPTIONS[1005], 1005);
        }
        
        if (empty($config['token']) && (empty($config['user']) 
            || empty($config['password']) || empty($config['serial']))) {
            throw new Exception(self::EXCEPTIONS[1006], 1006);
        }
    }
    
    function isHost($host) {
        // Check if the host is an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $url = "https://" . $host;
        } else {
            // If the host doesn't start with 'http://' or 'https://', add 'http://'
            if (!preg_match('#^https?://#', $host)) {
                $url = "https://" . $host;
            } else {
                $url = $host;
            }
        }
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);    // Disable SSL certificate verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);    // Disable host verification
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);        // Timeout for the connection attempt
        curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $statusCode > 0;
    }
    
    public function apiRequest($config, $api_command, $method = 'GET', $headers = [])
    {
        $token = $config['token'];
        if (empty($token)) {
            throw new Exception(self::EXCEPTIONS[1010], 1010);
        }

        $commonHeaders = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];
        $headers = array_merge($commonHeaders, $headers);
        $url = $config['host']. "/" . $api_command;

        return $this->urlRequest($url, $method, $headers);
    }

    protected function urlRequest($url, $method = 'GET', $headers = [], $postFields = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if (!empty($postFields)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $curl_errno = curl_errno($ch);
        if ($curl_errno) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception(self::EXCEPTIONS[1007] . $curl_errno . ", " . $error, 1007);
        }

        curl_close($ch);

        return ['response' => $response, 'httpStatus' => $httpStatus];
    }

    protected function getSessionId($config)
    {
        $url = 'https://enlighten.enphaseenergy.com/login/login.json?';
        $postFields = http_build_query(array(
            'user[email]' => $config["user"],
            'user[password]' => $config["password"],
        ));

        $result = $this->urlRequest($url, 'POST', [], $postFields);
        $data = json_decode($result['response'], true);

        if ($result['httpStatus'] != 200) {
            throw new Exception($data['message']);
        }

        return $data['session_id'];
    }

    protected function fetchApiToken($config, $session_id)
    {
        $url = 'https://entrez.enphaseenergy.com/tokens';
        $postFields = json_encode(array(
            'session_id' => $session_id,
            'serial_num' => $config["serial"],
            'username' => $config["user"],
        ));

        $result = $this->urlRequest($url, 'POST', ['Content-Type: application/json'], $postFields);

        if ($result['httpStatus'] != 200) {
            throw new Exception(self::EXCEPTIONS[1003] . $result['httpStatus'], 1003);
        }

        return $this->parseTokenResponse($result['response']);
    }

    protected function parseTokenResponse($response)
    {
        if (!is_string($response)) {
            throw new Exception(self::EXCEPTIONS[1009], 1009);
        }

        $trimmed = trim($response);
        if ($trimmed === '') {
            throw new Exception(self::EXCEPTIONS[1009], 1009);
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $token = $this->extractTokenFromArray($decoded);
            if (!empty($token)) {
                return $token;
            }
            throw new Exception(self::EXCEPTIONS[1009], 1009);
        }

        return $trimmed;
    }

    protected function extractTokenFromArray($data)
    {
        if (!is_array($data)) {
            return null;
        }

        $candidates = ['token', 'web_token', 'access_token'];
        foreach ($candidates as $candidate) {
            if (isset($data[$candidate]) && is_string($data[$candidate]) && !empty($data[$candidate])) {
                return $data[$candidate];
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $token = $this->extractTokenFromArray($value);
                if (!empty($token)) {
                    return $token;
                }
            }
        }

        return null;
    }

    protected function getApiToken($config)
    {
        try {
            $session_id = $this->getSessionId($config);
            $web_token = $this->fetchApiToken($config, $session_id);
            // Avoid logging full tokens; keep a short preview for debugging without leaking secrets
            $preview = is_string($web_token) && strlen($web_token) > 8
                ? substr($web_token, 0, 4) . '...' . substr($web_token, -4)
                : '[masked]';
            if (function_exists('IPS_LogMessage')) {
                IPS_LogMessage('EnphaseGateway', 'Retrieved API token (masked): ' . $preview);
            } else {
                error_log('EnphaseGateway: Retrieved API token (masked): ' . $preview);
            }
        } catch (Exception $e) {
            throw new Exception(self::EXCEPTIONS[1009], 1009);
        }
        return $web_token;    
    }
}
