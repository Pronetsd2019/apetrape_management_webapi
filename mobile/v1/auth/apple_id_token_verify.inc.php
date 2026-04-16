<?php

/**
 * Shared Apple Sign In id_token verification (JWKS).
 * Included by social-login.php and social-login-apple-complete.php.
 */

if (!function_exists('_getAppleJwks')) {
    /**
     * Fetch Apple's JWKS (cached on disk).
     */
    function _getAppleJwks() {
        $cacheFile = sys_get_temp_dir() . '/apple_jwks_cache.json';
        $ttlSeconds = 60 * 60; // 1 hour

        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age >= 0 && $age < $ttlSeconds) {
                $cached = @file_get_contents($cacheFile);
                if ($cached) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])) {
                        return $decoded;
                    }
                }
            }
        }

        $url = 'https://appleid.apple.com/auth/keys';
        $jwksRaw = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $jwksRaw = curl_exec($ch);
            curl_close($ch);
        } else {
            $jwksRaw = @file_get_contents($url);
        }

        if (!$jwksRaw) {
            throw new Exception('Failed to fetch Apple public keys.');
        }

        $decoded = json_decode($jwksRaw, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new Exception('Invalid Apple JWKS response.');
        }

        @file_put_contents($cacheFile, $jwksRaw);
        return $decoded;
    }
}

if (!function_exists('verifyAppleIdToken')) {
    /**
     * Verify Apple identity token (id_token) using Apple's JWKS and validate issuer/audience.
     * Returns decoded claims as an associative array.
     */
    function verifyAppleIdToken($idToken, $allowedAudiences) {
        if (!is_string($idToken) || trim($idToken) === '') {
            throw new Exception('Missing Apple id_token.');
        }
        if (!is_array($allowedAudiences) || empty($allowedAudiences)) {
            throw new Exception('Apple token verification misconfigured: allowed audiences missing.');
        }

        $jwks = _getAppleJwks();

        if (class_exists('\\Firebase\\JWT\\JWK')) {
            $keySet = \Firebase\JWT\JWK::parseKeySet($jwks);

            $parts = explode('.', $idToken);
            if (count($parts) < 2) {
                throw new Exception('Invalid Apple id_token format.');
            }
            $headerJson = \Firebase\JWT\JWT::urlsafeB64Decode($parts[0]);
            $header = json_decode($headerJson, true);
            $kid = is_array($header) && isset($header['kid']) ? $header['kid'] : null;
            if (!$kid || !isset($keySet[$kid])) {
                throw new Exception('Apple public key not found for token kid.');
            }

            $decodedObj = \Firebase\JWT\JWT::decode($idToken, $keySet[$kid]);
            $claims = (array)$decodedObj;
        } else {
            throw new Exception('Apple token verification unavailable (missing JWK parser).');
        }

        if (!isset($claims['iss']) || $claims['iss'] !== 'https://appleid.apple.com') {
            throw new Exception('Invalid Apple token issuer.');
        }

        $aud = $claims['aud'] ?? null;
        $audOk = false;
        if (is_string($aud)) {
            $audOk = in_array($aud, $allowedAudiences, true);
        } elseif (is_array($aud)) {
            foreach ($aud as $a) {
                if (is_string($a) && in_array($a, $allowedAudiences, true)) {
                    $audOk = true;
                    break;
                }
            }
        }
        if (!$audOk) {
            throw new Exception('Invalid Apple token audience.');
        }

        if (!isset($claims['sub']) || !is_string($claims['sub']) || trim($claims['sub']) === '') {
            throw new Exception('Apple token missing subject.');
        }

        return $claims;
    }
}
