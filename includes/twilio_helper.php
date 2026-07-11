<?php

if (!function_exists('twilio_config')) {
    function twilio_config(): array
    {
        static $config = null;

        if ($config === null) {
            $loaded = require dirname(__DIR__) . '/twilio_config.php';
            $config = is_array($loaded) ? $loaded : [];
        }

        return $config;
    }
}

if (!function_exists('twilio_normalize_phone')) {
    function twilio_normalize_phone(string $phone): string
    {
        $normalized = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
        if ($normalized === '' || preg_match('/^\+[1-9]\d{7,14}$/', $normalized) !== 1) {
            throw new RuntimeException('Twilio phone numbers must use E.164 format, like +15551234567.');
        }

        return $normalized;
    }
}

if (!function_exists('send_sms')) {
    function send_sms($to, $message): bool
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required to send Twilio SMS messages.');
        }

        $config = twilio_config();
        $account_sid = trim((string)($config['account_sid'] ?? ''));
        $auth_token = trim((string)($config['auth_token'] ?? ''));
        $from_number_raw = trim((string)($config['from_number'] ?? ''));
        $to_number_raw = trim((string)$to);
        $body = trim((string)$message);

        $missing = [];
        if ($account_sid === '') {
            $missing[] = 'account_sid';
        }
        if ($auth_token === '') {
            $missing[] = 'auth_token';
        }
        if ($from_number_raw === '') {
            $missing[] = 'from_number';
        }
        if ($to_number_raw === '') {
            throw new RuntimeException('Missing Twilio destination phone number.');
        }
        if ($body === '') {
            throw new RuntimeException('SMS message cannot be empty.');
        }
        if ($missing) {
            throw new RuntimeException('Missing Twilio configuration: ' . implode(', ', $missing));
        }

        $from_number = twilio_normalize_phone($from_number_raw);
        $to_number = twilio_normalize_phone($to_number_raw);

        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($account_sid) . '/Messages.json';
        $payload = http_build_query([
            'To' => $to_number,
            'From' => $from_number,
            'Body' => $body,
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $account_sid . ':' . $auth_token,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $curl_error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Twilio request failed: ' . $curl_error);
        }

        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($http_code < 200 || $http_code >= 300) {
            $api_message = trim((string)($decoded['message'] ?? ''));
            throw new RuntimeException($api_message !== '' ? 'Twilio SMS failed: ' . $api_message : 'Twilio SMS failed.');
        }

        if (!is_array($decoded) || trim((string)($decoded['sid'] ?? '')) === '') {
            throw new RuntimeException('Twilio SMS failed: missing message SID.');
        }

        return true;
    }
}
