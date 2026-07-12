<?php
// PayPal webhook receiver — no user session, called directly by PayPal.
// Register this URL in the PayPal dashboard under Notifications > Webhooks.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/paypal.php';

$body = file_get_contents('php://input');

$headers = [
    'PAYPAL-AUTH-ALGO'         => $_SERVER['HTTP_PAYPAL_AUTH_ALGO']         ?? '',
    'PAYPAL-CERT-URL'          => $_SERVER['HTTP_PAYPAL_CERT_URL']           ?? '',
    'PAYPAL-TRANSMISSION-ID'   => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']   ?? '',
    'PAYPAL-TRANSMISSION-SIG'  => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']  ?? '',
    'PAYPAL-TRANSMISSION-TIME' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
];

if (!paypal_verify_webhook(PAYPAL_WEBHOOK_ID, $headers, $body)) {
    log_event('warning', 'security', 'PayPal webhook signature verification failed');
    http_response_code(400);
    exit('Invalid signature');
}

$event      = json_decode($body, true);
$event_type = $event['event_type'] ?? '';
$resource   = $event['resource']   ?? [];
$event_id   = $event['id']         ?? '';

// Idempotency guard — PayPal retries a delivery with the same event id, so
// the UNIQUE key on webhook_events.event_id lets the first delivery win and
// drops replays here, before any payment rows are touched. This is race-safe:
// two simultaneous deliveries both try the INSERT and only one succeeds.
if ($event_id !== '') {
    try {
        db()->prepare('INSERT INTO webhook_events (event_id, event_type) VALUES (?,?)')
           ->execute([$event_id, $event_type]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // duplicate key — already processed
            log_event('info', 'payment', 'PayPal webhook replay dropped', [
                'event_id' => $event_id, 'event_type' => $event_type,
            ]);
            http_response_code(200);
            exit('Already processed');
        }
        throw $e;
    }
}

switch ($event_type) {

    case 'BILLING.SUBSCRIPTION.ACTIVATED':
        $sub_id = $resource['id'] ?? '';
        db()->prepare("UPDATE subscriptions SET status='active' WHERE paypal_subscription_id=?")
           ->execute([$sub_id]);
        break;

    case 'PAYMENT.SALE.COMPLETED':
        // PayPal fires this for every successful subscription charge
        $sub_id = $resource['billing_agreement_id'] ?? '';
        $amount = (float)($resource['amount']['total'] ?? 0);
        $txn_id = $resource['id'] ?? null;

        if (!$sub_id || $amount <= 0) break;

        $row = db()->prepare(
            "SELECT student_id FROM subscriptions WHERE paypal_subscription_id=? AND status='active'"
        );
        $row->execute([$sub_id]);
        $sub = $row->fetch();

        if ($sub) {
            // Deduplicate — PayPal can occasionally resend webhooks
            $dup = db()->prepare(
                "SELECT id FROM payments WHERE transaction_id=? AND payment_method='paypal_subscription'"
            );
            $dup->execute([$txn_id]);
            if (!$dup->fetch()) {
                db()->prepare(
                    'INSERT INTO payments
                     (student_id, amount, payment_type, payment_method, transaction_id, month_covered)
                     VALUES (?,?,?,?,?,?)'
                )->execute([
                    $sub['student_id'], $amount, 'monthly_tuition', 'paypal_subscription',
                    $txn_id, date('Y-m-01'),
                ]);
            }
        }
        break;

    case 'BILLING.SUBSCRIPTION.CANCELLED':
    case 'BILLING.SUBSCRIPTION.EXPIRED':
    case 'BILLING.SUBSCRIPTION.SUSPENDED':
        $sub_id     = $resource['id'] ?? '';
        $new_status = strtolower(str_replace('BILLING.SUBSCRIPTION.', '', $event_type));
        db()->prepare("UPDATE subscriptions SET status=? WHERE paypal_subscription_id=?")
           ->execute([$new_status, $sub_id]);
        break;
}

http_response_code(200);
echo 'OK';
