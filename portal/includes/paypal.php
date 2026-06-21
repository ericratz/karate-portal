<?php
// PayPal REST API v2 helpers
// Requires PAYPAL_MODE, PAYPAL_CLIENT_ID, PAYPAL_SECRET defined in config.php

function paypal_base_url(): string {
    return PAYPAL_MODE === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

// Fetch a short-lived access token using client credentials
function paypal_get_token(): string {
    $ch = curl_init(paypal_base_url() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['access_token'] ?? '';
}

// Create a PayPal order server-side so the amount cannot be tampered by the client
// Returns the PayPal order ID on success, or throws
function paypal_create_order(float $amount, string $description): string {
    $token = paypal_get_token();
    $body  = json_encode([
        'intent'         => 'CAPTURE',
        'purchase_units' => [[
            'amount'      => [
                'currency_code' => 'USD',
                'value'         => number_format($amount, 2, '.', ''),
            ],
            'description' => $description,
        ]],
    ]);

    $ch = curl_init(paypal_base_url() . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);

    if (empty($data['id'])) {
        throw new RuntimeException('PayPal order creation failed: ' . $resp);
    }
    return $data['id'];
}

// Capture an approved PayPal order and return the full response array
function paypal_capture_order(string $order_id): array {
    $token = paypal_get_token();
    $ch    = curl_init(paypal_base_url() . '/v2/checkout/orders/' . $order_id . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?? [];
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
    $body  = json_encode([
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

    $ch = curl_init(paypal_base_url() . '/v1/billing/subscriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);

    if (empty($data['id'])) {
        throw new RuntimeException('PayPal subscription creation failed: ' . $resp);
    }

    $approve_url = '';
    foreach ($data['links'] ?? [] as $link) {
        if ($link['rel'] === 'approve') { $approve_url = $link['href']; break; }
    }

    return ['id' => $data['id'], 'approve_url' => $approve_url];
}

// Cancel an active subscription
function paypal_cancel_subscription(string $subscription_id): void {
    $token = paypal_get_token();
    $ch    = curl_init(paypal_base_url() . '/v1/billing/subscriptions/' . $subscription_id . '/cancel');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['reason' => 'Cancelled by subscriber']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Verify a PayPal webhook signature — returns true if valid
function paypal_verify_webhook(string $webhook_id, array $headers, string $body): bool {
    $token   = paypal_get_token();
    $payload = json_encode([
        'auth_algo'         => $headers['PAYPAL-AUTH-ALGO'] ?? '',
        'cert_url'          => $headers['PAYPAL-CERT-URL'] ?? '',
        'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID'] ?? '',
        'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
        'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
        'webhook_id'        => $webhook_id,
        'webhook_event'     => json_decode($body, true),
    ]);

    $ch = curl_init(paypal_base_url() . '/v1/notifications/verify-webhook-signature');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return ($data['verification_status'] ?? '') === 'SUCCESS';
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

