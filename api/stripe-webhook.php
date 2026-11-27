<?php
// ==============================================================================
// DigitalsNova Cloud POS — Stripe Webhook Event Listener
// Handles 'checkout.session.completed', 'invoice.payment_succeeded', 'payment_intent.succeeded'
// ==============================================================================

require_once __DIR__ . '/bootstrap.php';

dnSendApiHeaders('GET, POST, OPTIONS');
dnHandleCorsPreflight();

$dataDir = dnResolveDataDir();
$tenantsFile = $dataDir . '/tenants.json';
$notifsFile = $dataDir . '/notifications.json';
$paymentsFile = $dataDir . '/payments.json';
$licenseKeysFile = $dataDir . '/license_keys.json';
$webhookLogsFile = $dataDir . '/webhook_logs.json';

$payload = @file_get_contents('php://input');
$event = json_decode($payload, true);

if (!$event || !isset($event['type'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        echo json_encode([
            'status' => 'online',
            'service' => 'DigitalsNova Stripe Webhook Listener',
            'time' => date('c'),
            'accepted_events' => [
                'checkout.session.completed',
                'invoice.payment_succeeded',
                'payment_intent.succeeded'
            ]
        ]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid webhook event payload']);
    exit();
}

$eventType = $event['type'];
$eventData = $event['data']['object'] ?? [];

$webhookLogs = dnReadJsonFile($webhookLogsFile, []);
array_unshift($webhookLogs, [
    'id' => $event['id'] ?? ('wh_' . time()),
    'type' => $eventType,
    'timestamp' => date('c'),
    'data' => $eventData
]);
dnWriteJsonFile($webhookLogsFile, array_slice($webhookLogs, 0, 200));

if ($eventType === 'checkout.session.completed' || $eventType === 'payment_intent.succeeded' || $eventType === 'invoice.payment_succeeded') {
    $metadata = $eventData['metadata'] ?? [];
    $subdomain = dnSanitizeSubdomain($metadata['subdomain'] ?? ($metadata['store'] ?? ''));
    $tenantId = trim((string)($metadata['tenantId'] ?? ($metadata['id'] ?? '')));
    $rawPlanType = strtoupper(trim((string)($metadata['planType'] ?? ($metadata['plan'] ?? 'MONTHLY'))));

    $tenants = dnGetUnifiedTenants($dataDir);
    $matchedIndex = -1;
    foreach ($tenants as $idx => $t) {
        $tId = (string)($t['id'] ?? '');
        $tSub = dnSanitizeSubdomain($t['subdomain'] ?? '');
        if (($subdomain !== '' && $tSub === $subdomain) || ($tenantId !== '' && $tId === $tenantId)) {
            $matchedIndex = $idx;
            break;
        }
    }

    if ($matchedIndex !== -1) {
        $tenant = &$tenants[$matchedIndex];
        $rawSec = $tenant['vertical']
            ?? ($tenant['sector']
            ?? ($tenant['storeType']
            ?? ($tenant['businessType']
            ?? ($metadata['vertical'] ?? ($metadata['sector'] ?? 'RETAIL_MART')))));

        $effectivePlans = dnGetEffectivePricingPlans($dataDir);
        $planInfo = dnResolveSectorPlanPricing($rawSec, $rawPlanType, $effectivePlans);

        $planType = $planInfo['planType'];
        $planDays = $planInfo['days'];
        $planName = $planInfo['planName'];
        $amount = intval($eventData['amount_total'] ?? ($eventData['amount'] ?? ($planInfo['fee'] * 100))) / 100;
        if ($amount <= 0) $amount = $planInfo['fee'];

        $validUntilTimestamp = strtotime("+{$planDays} days");
        $validUntilDate = date('d M Y, h:i A', $validUntilTimestamp);
        $validUntilIso = date('c', $validUntilTimestamp);
        $txnId = 'STRIPE_' . ($event['id'] ?? ('wh_' . time()));
        $keyCode = 'DNOVA-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $planType), 0, 4)) . '-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(2)));

        $tenant['status'] = 'ACTIVE';
        $tenant['planType'] = $planType;
        $tenant['planName'] = $planName;
        $tenant['monthlyFee'] = $amount;
        $tenant['monthly_fee'] = $amount;
        $tenant['cycleDays'] = $planDays;
        $tenant['billingCycleDay'] = 1;
        $tenant['subscriptionExpiry'] = $validUntilIso;
        $tenant['lastPaymentDate'] = date('c');
        $tenant['manualBlocked'] = false;
        $tenant['activeLicenseKey'] = $keyCode;
        $tenant['subscription'] = [
            'state' => 'ACTIVE',
            'plan' => $planType,
            'planName' => $planName,
            'billingCycleDay' => 1,
            'totalDays' => $planDays,
            'graceDaysRemaining' => 3,
            'monthlyFee' => $amount,
            'lastPaymentDate' => date('c'),
            'validUntil' => $validUntilIso,
            'validUntilFormatted' => $validUntilDate,
            'txnId' => $txnId
        ];
        dnNormalizeTenantState($tenant, $effectivePlans);
        $savedTenant = $tenant;
        unset($tenant);

        dnWriteJsonFile($tenantsFile, $tenants);
        dnSyncToSecondaryDataDirs('tenants.json', $tenants, $dataDir);

        $clientEmail = $savedTenant['email'] ?? ($savedTenant['adminUser']['email'] ?? ($eventData['customer_details']['email'] ?? ''));
        $storeName = $savedTenant['name'] ?? $subdomain;
        $resolvedSub = $savedTenant['subdomain'] ?? $subdomain;

        // Prepend into payments.json
        $payments = dnReadUnifiedJson($paymentsFile, 'payments.json', []);
        array_unshift($payments, [
            'id' => $txnId,
            'tenantId' => $savedTenant['id'],
            'tenantName' => $storeName,
            'subdomain' => $resolvedSub,
            'planType' => $planType,
            'planName' => $planName,
            'amount' => $amount,
            'currency' => strtoupper((string)($eventData['currency'] ?? 'PKR')),
            'status' => 'SUCCEEDED',
            'gateway' => 'STRIPE_WEBHOOK',
            'createdAt' => date('c'),
            'validUntil' => $validUntilIso
        ]);
        dnWriteJsonFile($paymentsFile, array_slice($payments, 0, 500));
        dnSyncToSecondaryDataDirs('payments.json', array_slice($payments, 0, 500), $dataDir);

        // Save license key record
        $keys = dnReadUnifiedJson($licenseKeysFile, 'license_keys.json', []);
        array_unshift($keys, [
            'id' => 'key_' . time() . '_' . rand(100, 999),
            'keyCode' => $keyCode,
            'tenantId' => $savedTenant['id'],
            'tenantName' => $storeName,
            'subdomain' => $resolvedSub,
            'planType' => $planType,
            'planName' => $planName,
            'fee' => $amount,
            'cycleDays' => $planDays,
            'days' => $planDays,
            'status' => 'ACTIVATED',
            'activatedAt' => date('c'),
            'generatedBy' => 'STRIPE_WEBHOOK',
            'createdAt' => date('c'),
            'active' => true,
            'redeemed' => true,
            'validUntil' => $validUntilIso
        ]);
        dnWriteJsonFile($licenseKeysFile, $keys);
        dnSyncToSecondaryDataDirs('license_keys.json', $keys, $dataDir);

        $notifications = dnReadUnifiedJson($notifsFile, 'notifications.json', []);
        array_unshift($notifications, [
            'id' => 'notif_' . time() . '_' . rand(10, 99),
            'timestamp' => date('c'),
            'type' => 'PAYMENT_RECEIVED',
            'title' => 'Stripe Webhook: Subscription Active (Rs. ' . number_format($amount) . ')',
            'tenantName' => $storeName,
            'subdomain' => $resolvedSub,
            'planType' => $planType,
            'planName' => $planName,
            'amount' => $amount,
            'txnId' => $txnId,
            'portalUrl' => 'https://digitalsnova.com/portal/?store=' . $resolvedSub,
            'message' => "Stripe confirmed payment of Rs. " . number_format($amount) . " for {$storeName} ({$planName}). Unlocked until {$validUntilDate}.",
            'unread' => true
        ]);
        dnWriteJsonFile($notifsFile, array_slice($notifications, 0, 100));
        dnSyncToSecondaryDataDirs('notifications.json', array_slice($notifications, 0, 100), $dataDir);

        $receiptBody = '<div style="font-family: Arial, sans-serif; background: #0b132b; color: #fff; padding: 24px; border-radius: 14px; border: 2px solid #10b981;">'
            . '<h2 style="color: #10b981; margin-top: 0;">Stripe Payment Confirmed!</h2>'
            . '<p>Your subscription for <strong>' . htmlspecialchars($storeName) . '</strong> has been renewed.</p>'
            . '<p><strong>Package:</strong> ' . htmlspecialchars($planName) . '<br>'
            . '<strong>Amount:</strong> Rs. ' . number_format($amount) . '<br>'
            . '<strong>Valid Until:</strong> ' . $validUntilDate . '<br>'
            . '<strong>License Key:</strong> <code>' . htmlspecialchars($keyCode) . '</code><br>'
            . '<strong>Transaction Reference:</strong> ' . htmlspecialchars($txnId) . '</p>'
            . '<p><a href="https://digitalsnova.com/portal/?store=' . urlencode($resolvedSub) . '" style="background: #00e5ff; color: #020617; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Launch Cloud Portal</a></p></div>';

        if ($clientEmail !== '' && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            dnSendGmailSmtp($clientEmail, "Stripe Payment Receipt: {$planName} - DigitalsNova", $receiptBody, 'DigitalsNova Official Billing');
        }
        dnSendGmailSmtp('digitalsnovaofficial@gmail.com', "Stripe Webhook Payment: Rs. " . number_format($amount) . " from {$storeName}", $receiptBody, 'DigitalsNova Official Billing');
    }
}

http_response_code(200);
echo json_encode(['received' => true, 'timestamp' => date('c')]);
exit();
