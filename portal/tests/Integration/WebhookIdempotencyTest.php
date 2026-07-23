<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for PayPal webhook idempotency.
 *
 * The webhook receiver (api/paypal_webhook.php) relies on two DB-level
 * guarantees verified here:
 *   1. webhook_events.event_id UNIQUE key rejects a replayed event id
 *      (PayPal retries reuse the same id), so replays are dropped race-safely.
 *   2. payments.payment_method accepts 'paypal_subscription' — this was
 *      missing from the ENUM and made every subscription charge INSERT fail
 *      under STRICT_TRANS_TABLES.
 */
class WebhookIdempotencyTest extends TestCase
{
    private const EVENT_ID = 'WH-PHPUNIT-REPLAY-TEST';

    #[\Override]
    protected function tearDown(): void
    {
        db()->prepare('DELETE FROM webhook_events WHERE event_id = ?')
             ->execute([self::EVENT_ID]);
    }

    public function test_first_event_insert_succeeds(): void
    {
        db()->prepare('INSERT INTO webhook_events (event_id, event_type) VALUES (?,?)')
             ->execute([self::EVENT_ID, 'PAYMENT.SALE.COMPLETED']);

        $stmt = db()->prepare('SELECT COUNT(*) FROM webhook_events WHERE event_id = ?');
        $stmt->execute([self::EVENT_ID]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function test_replayed_event_id_is_rejected_with_duplicate_key(): void
    {
        $insert = db()->prepare('INSERT INTO webhook_events (event_id, event_type) VALUES (?,?)');
        $insert->execute([self::EVENT_ID, 'PAYMENT.SALE.COMPLETED']);

        try {
            $insert->execute([self::EVENT_ID, 'PAYMENT.SALE.COMPLETED']);
            $this->fail('Expected duplicate-key PDOException on replayed event id');
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation — the code path the
            // webhook uses to recognise a replay and exit with 200.
            $this->assertSame('23000', (string)$e->getCode());
        }

        $stmt = db()->prepare('SELECT COUNT(*) FROM webhook_events WHERE event_id = ?');
        $stmt->execute([self::EVENT_ID]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function test_payment_method_enum_accepts_paypal_subscription(): void
    {
        $stmt = db()->prepare("SHOW COLUMNS FROM payments LIKE 'payment_method'");
        $stmt->execute();
        $col = $stmt->fetch();
        $this->assertStringContainsString(
            "'paypal_subscription'",
            $col['Type'],
            "payments.payment_method ENUM must include 'paypal_subscription' — run migration_2026-07-10_webhook_idempotency.sql"
        );
    }
}
