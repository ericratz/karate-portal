<?php
// Site-wide constants
// Edit these to match your setup

define('SITE_NAME',     'Shotokan Karate Portal');
define('SITE_URL',      'http://localhost/karate/portal');  // update to live URL when deployed
// DOJO_EMAIL is loaded from .env — this fallback is used only if .env is missing
if (!defined('DOJO_EMAIL')) define('DOJO_EMAIL', 'admin@example.com');
define('MONTHLY_FEE',   30.00);
define('REG_FEE',       15.00);
define('TEST_FEE',      10.00);
define('SLC_FEE',       10.00);
define('SEMINAR_FEE',   60.00);

// PayPal — switch to live credentials when ready
define('PAYPAL_MODE',        'sandbox');            // 'sandbox' or 'live'
define('PAYPAL_CLIENT_ID',   'YOUR_PAYPAL_CLIENT_ID');
define('PAYPAL_SECRET',      'YOUR_PAYPAL_SECRET');

// PayPal Subscriptions — set these up before enabling auto-pay:
// 1. In PayPal dashboard: Catalog > Products > Create a product
// 2. Catalog > Subscription Plans > Create a plan (monthly, $30, fixed price)
// 3. Copy the Plan ID (P-XXXX) and paste below
// 4. In PayPal dashboard: Notifications > Webhooks > Add webhook
//    URL: https://yourdomain.com/karate/portal/paypal_webhook.php
//    Events: BILLING.SUBSCRIPTION.ACTIVATED, BILLING.SUBSCRIPTION.CANCELLED,
//            BILLING.SUBSCRIPTION.EXPIRED, BILLING.SUBSCRIPTION.SUSPENDED,
//            PAYMENT.SALE.COMPLETED
// 5. Copy the Webhook ID and paste below
define('PAYPAL_PLAN_ID',     'YOUR_PAYPAL_PLAN_ID');   // P-XXXXXXXXXXXX
define('PAYPAL_WEBHOOK_ID',  'YOUR_PAYPAL_WEBHOOK_ID'); // numeric webhook ID
