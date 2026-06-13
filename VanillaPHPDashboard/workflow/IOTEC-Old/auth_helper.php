<?php

require_once 'config.php';
require_once 'logger.php';

function getIotecAccessToken() {
    logIotec("Attempting to get IOTEC access token", 'AUTH');
    $cacheFile = __DIR__ . '/token_cache.json';
    
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['token']) && isset($cached['expires_at'])) {
            if (time() < $cached['expires_at'] - 300) {
                logIotec("Using cached access token (expires in " . ($cached['expires_at'] - time()) . " seconds)", 'AUTH');
                return $cached['token'];
            } else {
                logIotec("Cached token expired, requesting new token", 'AUTH');
            }
        }
    } else {
        logIotec("No cached token found, requesting new token", 'AUTH');
    }
    
    $ch = curl_init(IOTEC_AUTH_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => IOTEC_CLIENT_ID,
        'client_secret' => IOTEC_CLIENT_SECRET,
        'grant_type' => 'client_credentials'
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logIotec("Authentication cURL error: " . $error, 'AUTH_ERROR');
        return null;
    }
    
    logIotec("Authentication response received", 'AUTH', ['httpCode' => $httpCode]);
    
    if ($httpCode !== 200) {
        logIotec("Authentication failed with HTTP " . $httpCode, 'AUTH_ERROR', ['response' => substr($response, 0, 500)]);
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['access_token'])) {
        logIotec("Invalid authentication response - missing access_token", 'AUTH_ERROR', ['response' => substr($response, 0, 500)]);
        return null;
    }
    
    $expiresIn = $data['expires_in'] ?? 3600;
    $cacheData = [
        'token' => $data['access_token'],
        'expires_at' => time() + $expiresIn
    ];
    
    file_put_contents($cacheFile, json_encode($cacheData));
    logIotec("Access token obtained successfully (expires in {$expiresIn}s)", 'AUTH_SUCCESS');
    
    return $data['access_token'];
}

function makeIotecApiRequest($method, $endpoint, $data = null) {
    logIotec("Making API request: $method $endpoint", 'API', ['data' => $data]);
    
    $token = getIotecAccessToken();
    if (!$token) {
        logIotec("API request failed - no access token", 'API_ERROR');
        return ['error' => 'Failed to obtain access token'];
    }
    
    $url = IOTEC_API_BASE_URL . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logIotec("API request cURL error: " . $error, 'API_ERROR', ['endpoint' => $endpoint]);
        return ['error' => 'API request failed: ' . $error];
    }
    
    logIotec("API response received", 'API', ['httpCode' => $httpCode, 'endpoint' => $endpoint]);
    
    $responseData = json_decode($response, true);
    
    if ($httpCode >= 400) {
        logIotec("API request failed with HTTP $httpCode", 'API_ERROR', [
            'endpoint' => $endpoint,
            'httpCode' => $httpCode,
            'response' => $responseData
        ]);
        return [
            'error' => 'API error',
            'httpCode' => $httpCode,
            'response' => $responseData
        ];
    }
    
    logIotec("API request successful", 'API_SUCCESS', ['endpoint' => $endpoint, 'response' => $responseData]);
    
    return $responseData;
}
?>
