<?php
// PayPal REST API v2 helpers
// Requires PAYPAL_MODE, PAYPAL_CLIENT_ID, PAYPAL_SECRET defined in config.php

function paypal_base_url(): string {
    return PAYPAL_MODE === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

/**
 * Single choke point for all PayPal HTTP calls: timeouts, transport-error
 * detection, and JSON decoding. Throws RuntimeException on network failure
 * or non-JSON response; HTTP-level errors (4xx/5xx) are returned decoded so
 * callers can inspect PayPal's error payload.
 *
 * @param array<int|string, mixed> $extra_curl_opts
 * @return array{status:int, data:array<string, mixed>, raw:string}
 */
function paypal_request(string $path, string $post_body, array $headers, array $extra_curl_opts = []): array {
    $ch = curl_init(paypal_base_url() . $path);
    if ($ch === false) {
        throw new RuntimeException('PayPal request failed: could not initialize cURL');
    }
    curl_setopt_array($ch, $extra_curl_opts + [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($resp) || $errno !== 0) {
        log_event('error', 'payment', 'PayPal request failed (network)', [
            'path' => $path, 'curl_errno' => $errno, 'curl_error' => $error,
        ]);
        throw new RuntimeException("PayPal request failed: $error");
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        // 204 No Content (e.g. subscription cancel) is a valid empty body
        if ($status === 204 && $resp === '') {
            return ['status' => $status, 'data' => [], 'raw' => ''];
        }
        log_event('error', 'payment', 'PayPal returned non-JSON response', [
            'path' => $path, 'status' => $status, 'body_prefix' => substr($resp, 0, 200),
        ]);
        throw new RuntimeException('PayPal returned an unexpected response');
    }

    /** @var array<string, mixed> $data */
    return ['status' => $status, 'data' => $data, 'raw' => $resp];
}

// Fetch a short-lived access token using client credentials — throws on failure
function paypal_get_token(): string {
    $result = paypal_request('/v1/oauth2/token', 'grant_type=client_credentials',
        ['Accept: application/json'],
        [CURLOPT_USERPWD => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET]
    );
    $token = $result['data']['access_token'] ?? '';
    if ($token === '') {
        log_event('error', 'payment', 'PayPal token request rejected', [
            'status' => $result['status'],
            'error'  => $result['data']['error_description'] ?? ($result['data']['error'] ?? 'unknown'),
        ]);
        throw new RuntimeException('PayPal authentication failed');
    }
    return $token;
}

/** @return string[] */
function paypal_json_headers(string $token): array {
    return ['Content-Type: application/json', 'Authorization: Bearer ' . $token];
}

// Create a PayPal order server-side so the amount cannot be tampered by the client
// Returns the PayPal order ID on success, or throws
function paypal_create_order(float $amount, string $description): string {
    $token = paypal_get_token();
    $body  = (string) json_encode([
        'intent'         => 'CAPTURE',
        'purchase_units' => [[
            'amount'      => [
                'currency_code' => 'USD',
                'value'         => number_format($amount, 2, '.', ''),
            ],
            'description' => $description,
        ]],
    ]);

    $result = paypal_request('/v2/checkout/orders', $body, paypal_json_headers($token));

    if (empty($result['data']['id'])) {
        throw new RuntimeException('PayPal order creation failed: ' . $result['raw']);
    }
    return $result['data']['id'];
}

// Capture an approved PayPal order and return the full response array
function paypal_capture_order(string $order_id): array {
    $token  = paypal_get_token();
    $result = paypal_request('/v2/checkout/orders/' . $order_id . '/capture', '{}',
        paypal_json_headers($token));
    return $result['data'];
}

// Pull the captured amount from a capture response
function paypal_captured_amount(array $capture_response): float {
    return (float)(
        $capture_response['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0
    );
}

// Create a PayPal subscription and return ['id' => ..., 'approve_url' => ...]
function paypal_create_subscription(string $first_name, string $last_name, string $email): array {
    $token = paypal_get_token();
    $body  = (string) json_encode([
        'plan_id'             => PAYPAL_PLAN_ID,
        'subscriber'          => [
            'name'          => ['given_name' => $first_name, 'surname' => $last_name],
            'email_address' => $email,
        ],
        'application_context' => [
            'return_url'  => SITE_URL . '/api/paypal_subscription_return.php',
            'cancel_url'  => SITE_URL . '/student/pay.php',
            'user_action' => 'SUBSCRIBE_NOW',
        ],
    ]);

    $result = paypal_request('/v1/billing/subscriptions', $body, paypal_json_headers($token));

    if (empty($result['data']['id'])) {
        throw new RuntimeException('PayPal subscription creation failed: ' . $result['raw']);
    }

    $approve_url = '';
    foreach ($result['data']['links'] ?? [] as $link) {
        if ($link['rel'] === 'approve') { $approve_url = $link['href']; break; }
    }

    return ['id' => $result['data']['id'], 'approve_url' => $approve_url];
}

// Cancel an active subscription — throws if PayPal did not accept the cancel,
// so callers never mark a subscription cancelled while PayPal keeps charging.
function paypal_cancel_subscription(string $subscription_id): void {
    $token  = paypal_get_token();
    $result = paypal_request('/v1/billing/subscriptions/' . $subscription_id . '/cancel',
        (string) json_encode(['reason' => 'Cancelled by subscriber']),
        paypal_json_headers($token));

    if ($result['status'] !== 204) {
        log_event('error', 'payment', 'PayPal subscription cancel rejected', [
            'subscription_id' => $subscription_id, 'status' => $result['status'], 'body' => $result['raw'],
        ]);
        throw new RuntimeException('PayPal did not accept the subscription cancellation');
    }
}

// Verify a PayPal webhook signature — returns true if valid.
// Never throws: any failure (network, auth) counts as unverified.
function paypal_verify_webhook(string $webhook_id, array $headers, string $body): bool {
    try {
        $token   = paypal_get_token();
        $payload = (string) json_encode([
            'auth_algo'         => $headers['PAYPAL-AUTH-ALGO'] ?? '',
            'cert_url'          => $headers['PAYPAL-CERT-URL'] ?? '',
            'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID'] ?? '',
            'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
            'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
            'webhook_id'        => $webhook_id,
            'webhook_event'     => json_decode($body, true),
        ]);

        $result = paypal_request('/v1/notifications/verify-webhook-signature', $payload,
            paypal_json_headers($token));
        return ($result['data']['verification_status'] ?? '') === 'SUCCESS';
    } catch (RuntimeException $e) {
        log_event('error', 'payment', 'PayPal webhook verification errored', ['message' => $e->getMessage()]);
        return false;
    }
}

// Map payment type to the canonical fee from config
function fee_for_type(string $type): float {
    switch ($type) {
        case 'monthly_tuition': return MONTHLY_FEE;
        case 'registration':    return REG_FEE;
        case 'belt_test':       return TEST_FEE;
        case 'slc_training':    return SLC_FEE;
        case 'seminar':         return SEMINAR_FEE;
        default:                return 0.0;
    }
}
