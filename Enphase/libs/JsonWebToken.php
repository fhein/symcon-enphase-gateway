<?php

class JsonWebToken {
    public function decodeJwtPayload($jwt)
    {
        // Split the JWT to get the payload
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new Exception("Invalid JWT");
        }

        $payload = $parts[1];

        // Base64 decode may need adjustments for padding
        $payload = base64_decode(str_pad(strtr($payload, '-_', '+/'), strlen($payload) % 4, '=', STR_PAD_RIGHT));

        $claims = json_decode($payload, true);

        return $claims;
    }

	public function getJwtData($jwt) {
		if (empty($jwt)) {
            throw new Exception("Invalid JWT");
        }

        $claims = $this->decodeJwtPayload($jwt);

        $data = [];
        if (isset($claims['iat'])) {
            $data['issue'] = date("Y-m-d H:i:s", $claims['iat']);
        }

        if (isset($claims['exp'])) {
            $data['expiration'] = date("Y-m-d H:i:s", $claims['exp']);
        }

		if (isset($claims['username'])) {
            $data['user'] = $claims['username'];
		}
        
		if (isset($claims['enphaseUser'])) {
            $data['access'] = $claims['enphaseUser'];
		}
		
		if (isset($claims['aud'])) {
            $data['serial'] = $claims['aud'];
		}
        return $data;
    }
}