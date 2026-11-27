<?php
// ==============================================================================
// DigitalsNova Cloud SaaS — Shared Bootstrap, Storage, Security & Mailer Core
// ==============================================================================

function dnSendApiHeaders($allowMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS') {
    if (php_sapi_name() === 'cli' || headers_sent()) {
        return;
    }
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $allowMethods);
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Tenant-Subdomain');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
}

function dnHandleCorsPreflight() {
    if (php_sapi_name() !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

function dnReadJsonFile($path, $default = []) {
    if (!file_exists($path)) {
        return $default;
    }
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $default;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

function dnWriteJsonFile($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
    return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

if (!function_exists('readJsonFile')) {
    function readJsonFile($path, $default = []) {
        return dnReadJsonFile($path, $default);
    }
}

if (!function_exists('writeJsonFile')) {
    function writeJsonFile($path, $data) {
        return dnWriteJsonFile($path, $data);
    }
}

function dnReadUnifiedJson($primaryPath, $filename, $default = []) {
    if (file_exists($primaryPath)) {
        return dnReadJsonFile($primaryPath, $default);
    }
    $candidates = [
        __DIR__ . '/data/' . $filename,
        dirname(__DIR__) . '/portal/api/data/' . $filename
    ];
    foreach ($candidates as $cand) {
        if ($cand !== $primaryPath && file_exists($cand)) {
            return dnReadJsonFile($cand, $default);
        }
    }
    return $default;
}

if (!function_exists('readUnifiedJson')) {
    function readUnifiedJson($primaryPath, $filename, $default = []) {
        return dnReadUnifiedJson($primaryPath, $filename, $default);
    }
}

function dnResolveDataDir() {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $envDir = getenv('DIGITALSNOVA_DATA_DIR');
    if ($envDir && trim($envDir) !== '') {
        $target = rtrim(trim($envDir), "/\\");
        if (!is_dir($target)) {
            @mkdir($target, 0755, true);
        }
        if (is_dir($target) && is_writable($target)) {
            $resolved = realpath($target) ?: $target;
            dnEnsureInitialData($resolved);
            dnSyncShippedRemoteConfig($resolved);
            return $resolved;
        }
    }

    // Production Unix/Hostinger: store runtime state above public_html so `lftp --delete` never wipes live client data
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), "/\\");
    if (DIRECTORY_SEPARATOR === '/' && $docRoot !== '' && preg_match('#/(public_html|htdocs|www)$#i', $docRoot)) {
        $parentDir = dirname($docRoot);
        if (is_dir($parentDir) && is_writable($parentDir)) {
            $outsideDir = $parentDir . '/.digitalsnova_data';
            if (!is_dir($outsideDir)) {
                @mkdir($outsideDir, 0755, true);
            }
            if (is_dir($outsideDir) && is_writable($outsideDir)) {
                $resolved = realpath($outsideDir) ?: $outsideDir;
                dnEnsureInitialData($resolved);
                dnSyncShippedRemoteConfig($resolved);
                return $resolved;
            }
        }
    }

    $fallback = __DIR__ . '/data';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0755, true);
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0777, true);
        }
    }
    $resolved = realpath($fallback) ?: $fallback;
    dnEnsureInitialData($resolved);
    dnSyncShippedRemoteConfig($resolved);
    return $resolved;
}

function dnEnsureInitialData($dataDir) {
    $flagFile = $dataDir . '/.migrated.flag';
    if (file_exists($flagFile)) {
        return;
    }
    $seedDirs = [
        __DIR__ . '/data',
        dirname(__DIR__) . '/portal/api/data'
    ];
    $jsonFiles = [
        'pricing_plans.json',
        'tenants.json',
        'notifications.json',
        'staff.json',
        'deleted_staff.json',
        'deleted_tenants.json',
        'payments.json',
        'license_keys.json',
        'deleted_license_keys.json',
        'platform_brand.json',
        'password_resets.json',
        'cron_logs.json',
        'remote_config.json',
        'payment_gateway_settings.json'
    ];
    foreach ($seedDirs as $sd) {
        if (!is_dir($sd) || realpath($sd) === $dataDir) {
            continue;
        }
        foreach ($jsonFiles as $fname) {
            $src = $sd . '/' . $fname;
            $dst = $dataDir . '/' . $fname;
            if (file_exists($src) && !file_exists($dst)) {
                $parsed = dnReadJsonFile($src, null);
                if ($parsed !== null) {
                    dnWriteJsonFile($dst, $parsed);
                }
            }
        }
        if (file_exists($sd . '/notifications.json')) {
            @file_put_contents($dataDir . '/.notifs_seeded.flag', date('c'));
        }
    }
    @file_put_contents($flagFile, date('c'));
}

function dnSyncShippedRemoteConfig($dataDir) {
    $shippedPath = __DIR__ . '/data/remote_config.json';
    $storedPath = $dataDir . '/remote_config.json';
    $defaults = dnGetDefaultRemoteConfig();
    $shipped = file_exists($shippedPath) ? array_merge($defaults, dnReadJsonFile($shippedPath, [])) : $defaults;
    $stored = file_exists($storedPath) ? dnReadJsonFile($storedPath, []) : [];

    $shippedBuild = trim((string)($shipped['buildId'] ?? $shipped['appVersion'] ?? ''));
    $storedBuild = trim((string)($stored['buildId'] ?? ''));

    if (empty($stored)) {
        $initial = array_merge($defaults, $shipped);
        dnWriteJsonFile($storedPath, $initial);
        return;
    }

    if ($shippedBuild !== '' && $shippedBuild !== $storedBuild) {
        $merged = array_merge($defaults, $stored);
        $merged['buildId'] = $shippedBuild;
        $merged['appVersion'] = !empty($shipped['appVersion']) ? $shipped['appVersion'] : $shippedBuild;
        $merged['forceReloadAt'] = intval(round(microtime(true) * 1000));
        $merged['updatedAt'] = date('c');
        dnWriteJsonFile($storedPath, $merged);
    }
}

function dnSyncToSecondaryDataDirs($filename, $data, $activeDataDir = '') {
    // Skip syncing sensitive auth/token files into web docroot when using external storage
    $sensitive = ['super_admin.json', 'otp_tokens.json', 'leads.json'];
    if (in_array($filename, $sensitive, true)) {
        return;
    }
    $rootData = realpath(__DIR__ . '/data') ?: (__DIR__ . '/data');
    $portalData = realpath(dirname(__DIR__) . '/portal/api/data') ?: (dirname(__DIR__) . '/portal/api/data');
    // Only mirror between root api/data and portal/api/data when running directly in docroot fallback mode
    if ($activeDataDir !== '' && realpath($activeDataDir) !== $rootData && realpath($activeDataDir) !== $portalData) {
        return;
    }
    foreach ([$portalData . '/' . $filename, $rootData . '/' . $filename] as $sec) {
        if ($activeDataDir !== '' && dirname($sec) === realpath($activeDataDir)) {
            continue;
        }
        $secDir = dirname($sec);
        if (is_dir($secDir) && is_writable($secDir)) {
            dnWriteJsonFile($sec, $data);
        }
    }
}

function dnSanitizeSubdomain($sub) {
    $clean = strtolower(trim((string)$sub));
    $clean = preg_replace('/[^a-z0-9\-]/', '', $clean);
    return trim($clean, '-');
}

function dnIsReservedName($name) {
    $clean = dnSanitizeSubdomain($name);
    $reserved = ['hq', 'admin', 'superadmin', 'api', 'portal', 'www', 'root', 'system', 'null', 'undefined', 'default', 'support', 'billing'];
    return $clean === '' || in_array($clean, $reserved, true);
}

function dnGetCanonicalPricingDefaults() {
    return [
        'pricingVersion' => 2,
        'monthlyFee' => 7999,
        'yearlyFee' => 79990,
        'threeYearFee' => 229000,
        'trialDays' => 3,
        'currency' => 'Rs.',
        'retailMonthly' => 4999,
        'retailYearly' => 49990,
        'retailThreeYear' => 139000,
        'pharmacyMonthly' => 5999,
        'pharmacyYearly' => 59990,
        'pharmacyThreeYear' => 169000,
        'restaurantMonthly' => 6999,
        'restaurantYearly' => 69990,
        'restaurantThreeYear' => 199000,
        'clinicMonthly' => 7999,
        'clinicYearly' => 79990,
        'clinicThreeYear' => 229000,
        'retail' => ['title' => 'Retail Marts & General Stores', 'monthly' => 4999, 'yearly' => 49990, 'threeYear' => 139000],
        'pharmacy' => ['title' => 'Pharmacies & Medical Stores', 'monthly' => 5999, 'yearly' => 59990, 'threeYear' => 169000],
        'restaurant' => ['title' => 'Restaurants, Cafes & Food Outlets', 'monthly' => 6999, 'yearly' => 69990, 'threeYear' => 199000],
        'clinic' => ['title' => 'Clinics & Hospitals (HMS)', 'monthly' => 7999, 'yearly' => 79990, 'threeYear' => 229000]
    ];
}

function dnGetEffectivePricingPlans($dataDir) {
    $pricingFile = $dataDir . '/pricing_plans.json';
    $defaultPlans = dnGetCanonicalPricingDefaults();
    $stored = dnReadUnifiedJson($pricingFile, 'pricing_plans.json', []);
    if (empty($stored) || !is_array($stored)) {
        return $defaultPlans;
    }
    if (empty($stored['pricingVersion'])) {
        if (isset($stored['yearlyFee']) && intval($stored['yearlyFee']) === 76790) $stored['yearlyFee'] = 79990;
        if (isset($stored['retailThreeYear']) && intval($stored['retailThreeYear']) === 129000) $stored['retailThreeYear'] = 139000;
        if (isset($stored['pharmacyThreeYear']) && intval($stored['pharmacyThreeYear']) === 159000) $stored['pharmacyThreeYear'] = 169000;
        if (isset($stored['restaurantThreeYear']) && intval($stored['restaurantThreeYear']) === 189000) $stored['restaurantThreeYear'] = 199000;
    }
    $merged = array_merge($defaultPlans, $stored);
    $merged['retail'] = ['title' => 'Retail Marts & General Stores', 'monthly' => intval($merged['retailMonthly']), 'yearly' => intval($merged['retailYearly']), 'threeYear' => intval($merged['retailThreeYear'])];
    $merged['pharmacy'] = ['title' => 'Pharmacies & Medical Stores', 'monthly' => intval($merged['pharmacyMonthly']), 'yearly' => intval($merged['pharmacyYearly']), 'threeYear' => intval($merged['pharmacyThreeYear'])];
    $merged['restaurant'] = ['title' => 'Restaurants, Cafes & Food Outlets', 'monthly' => intval($merged['restaurantMonthly']), 'yearly' => intval($merged['restaurantYearly']), 'threeYear' => intval($merged['restaurantThreeYear'])];
    $merged['clinic'] = ['title' => 'Clinics & Hospitals (HMS)', 'monthly' => intval($merged['clinicMonthly']), 'yearly' => intval($merged['clinicYearly']), 'threeYear' => intval($merged['clinicThreeYear'])];
    return $merged;
}

function dnNormalizeSectorCode($raw) {
    $s = strtoupper(trim((string)$raw));
    if (strpos($s, 'REST') !== false || strpos($s, 'FOOD') !== false || strpos($s, 'CAFE') !== false) return 'RESTAURANT';
    if (strpos($s, 'PHARM') !== false || strpos($s, 'MED') !== false) return 'PHARMACY';
    if (strpos($s, 'CLIN') !== false || strpos($s, 'HOSP') !== false || strpos($s, 'DENT') !== false) return 'CLINIC_HMS';
    if (strpos($s, 'RETAIL') !== false || strpos($s, 'MART') !== false || strpos($s, 'STORE') !== false) return 'RETAIL_MART';
    return 'RETAIL_MART';
}

function dnResolveSectorPlanPricing($vertical, $planType, $plans) {
    $v = dnNormalizeSectorCode($vertical);
    $pUpper = strtoupper(trim((string)($planType ?: 'TRIAL_3_DAYS')));
    $secKey = ($v === 'RESTAURANT') ? 'restaurant' : (($v === 'PHARMACY') ? 'pharmacy' : (($v === 'CLINIC_HMS') ? 'clinic' : 'retail'));
    $secLabel = ($v === 'RESTAURANT') ? 'Restaurant & Cafe POS' : (($v === 'PHARMACY') ? 'Pharmacy & Medical POS' : (($v === 'CLINIC_HMS') ? 'Clinic & HMS Portal' : 'Retail Mart POS'));

    $monthly = intval($plans[$secKey . 'Monthly'] ?? 4999);
    $yearly = intval($plans[$secKey . 'Yearly'] ?? ($monthly * 10));
    $threeYear = intval($plans[$secKey . 'ThreeYear'] ?? 139000);

    if ($pUpper === 'TRIAL_3_DAYS' || $pUpper === 'TRIAL') {
        return ['planType' => 'TRIAL_3_DAYS', 'fee' => 0, 'days' => intval($plans['trialDays'] ?? 3), 'planName' => $secLabel . ' (3-Day Trial)'];
    }
    if ($pUpper === 'YEARLY' || $pUpper === '1_YEAR' || $pUpper === 'ONE_YEAR' || $pUpper === 'ANNUAL') {
        return ['planType' => 'YEARLY', 'fee' => $yearly, 'days' => 365, 'planName' => $secLabel . ' (Annual)'];
    }
    if ($pUpper === '3_YEARS' || $pUpper === '3_YEAR' || $pUpper === 'THREE_YEAR' || $pUpper === 'THREE_YEARS') {
        return ['planType' => '3_YEARS', 'fee' => $threeYear, 'days' => 1095, 'planName' => $secLabel . ' (3-Year Enterprise)'];
    }
    return ['planType' => 'MONTHLY', 'fee' => $monthly, 'days' => 30, 'planName' => $secLabel . ' (Monthly)'];
}

function dnGetDefaultPlatformBrand() {
    return [
        'name' => 'Digital Snove Portal',
        'logoUrl' => '',
        'tagline' => 'Enterprise Multi-Tenant Cloud POS & SaaS Platform',
        'address' => 'Digital Snove SaaS Headquarters',
        'phone' => '03131094557',
        'updatedAt' => ''
    ];
}

function dnGetDefaultRemoteConfig() {
    return [
        'appVersion' => '20261111v21',
        'buildId' => '20261111v21',
        'forceReloadAt' => 0,
        'maintenanceMode' => false,
        'maintenanceMessage' => 'Scheduled platform upgrade is underway. Please wait a few minutes and refresh.',
        'globalNotice' => '',
        'globalNoticeType' => 'INFO',
        'globalNoticeActive' => false,
        'updatedAt' => ''
    ];
}

function dnGetDefaultPaymentGatewaySettings() {
    return [
        'cardGateway' => [
            'provider' => 'STRIPE',
            'mode' => 'TEST',
            'publishableKey' => 'pk_test_51O9Xv7DigitalsNovaLiveKeyDemo001',
            'secretKey' => 'sk_test_51O9Xv7DigitalsNovaSecretDemo001',
            'webhookSecret' => 'whsec_digitalsnova_demo_secret_2026'
        ],
        'localAccounts' => [
            'bankName' => 'Meezan Bank Ltd',
            'accountTitle' => 'DigitalsNova / Ubaid Ur Rehman',
            'accountNumber' => '01080105286455',
            'iban' => 'PK62MEZN0001080105286455',
            'jazzCash' => '0313-1094557',
            'jazzCashTitle' => 'Ubaid Ur Rehman',
            'easyPaisa' => '0313-1094557',
            'easyPaisaTitle' => 'Ubaid Ur Rehman',
            'whatsappNumber' => '+92 313 1094557',
            'customMethods' => [
                [
                    'id' => 'pm_meezan',
                    'name' => 'Meezan Bank Ltd',
                    'title' => 'DigitalsNova / Ubaid Ur Rehman',
                    'number' => '01080105286455',
                    'iban' => 'PK62MEZN0001080105286455',
                    'note' => '24/7 Instant Online Transfer (IBFT)'
                ],
                [
                    'id' => 'pm_jazzcash',
                    'name' => 'JazzCash',
                    'title' => 'Ubaid Ur Rehman',
                    'number' => '0313-1094557',
                    'iban' => '',
                    'note' => 'Instant Mobile Wallet Transfer'
                ],
                [
                    'id' => 'pm_easypaisa',
                    'name' => 'EasyPaisa',
                    'title' => 'Ubaid Ur Rehman',
                    'number' => '0313-1094557',
                    'iban' => '',
                    'note' => 'Instant Mobile Wallet Transfer'
                ]
            ]
        ],
        'updatedAt' => date('c')
    ];
}

function dnGetPaymentGatewaySettings($dataDir) {
    $filePath = $dataDir . '/payment_gateway_settings.json';
    $settings = dnReadUnifiedJson($filePath, 'payment_gateway_settings.json', null);
    $defaults = dnGetDefaultPaymentGatewaySettings();
    if (!is_array($settings)) {
        return $defaults;
    }
    $local = array_merge($defaults['localAccounts'], is_array($settings['localAccounts'] ?? null) ? $settings['localAccounts'] : []);
    if (isset($settings['localAccounts']['customMethods']) && is_array($settings['localAccounts']['customMethods'])) {
        $local['customMethods'] = $settings['localAccounts']['customMethods'];
    } elseif (empty($local['customMethods'])) {
        $local['customMethods'] = $defaults['localAccounts']['customMethods'];
    }
    return [
        'cardGateway' => array_merge($defaults['cardGateway'], is_array($settings['cardGateway'] ?? null) ? $settings['cardGateway'] : []),
        'localAccounts' => $local,
        'updatedAt' => $settings['updatedAt'] ?? date('c')
    ];
}

function dnSavePaymentGatewaySettings($dataDir, $settings) {
    $filePath = $dataDir . '/payment_gateway_settings.json';
    $current = dnGetPaymentGatewaySettings($dataDir);
    $incomingLocal = is_array($settings['localAccounts'] ?? null) ? $settings['localAccounts'] : [];
    $mergedLocal = array_merge($current['localAccounts'], $incomingLocal);
    if (isset($incomingLocal['customMethods']) && is_array($incomingLocal['customMethods'])) {
        $mergedLocal['customMethods'] = $incomingLocal['customMethods'];
    }
    $merged = [
        'cardGateway' => array_merge($current['cardGateway'], is_array($settings['cardGateway'] ?? null) ? $settings['cardGateway'] : []),
        'localAccounts' => $mergedLocal,
        'updatedAt' => date('c')
    ];
    dnWriteJsonFile($filePath, $merged);
    dnSyncToSecondaryDataDirs('payment_gateway_settings.json', $merged, $dataDir);
    return $merged;
}

function dnGetSuperAdmin($dataDir) {
    $path = $dataDir . '/super_admin.json';
    $default = [
        'id' => 'u_ubaid',
        'name' => 'Ubaid Ur Rehman (Platform Owner)',
        'username' => 'ubaid',
        'aliases' => ['ubaid', 'superadmin'],
        'email' => 'ubaidurrehman2200230@gmail.com',
        'passwordHash' => password_hash('2200230', PASSWORD_DEFAULT),
        'updatedAt' => ''
    ];
    if (!file_exists($path)) {
        dnWriteJsonFile($path, $default);
        return $default;
    }
    $stored = dnReadJsonFile($path, []);
    if (empty($stored) || empty($stored['passwordHash'])) {
        $merged = array_merge($default, $stored);
        dnWriteJsonFile($path, $merged);
        return $merged;
    }
    return array_merge($default, $stored);
}

function dnSaveSuperAdmin($dataDir, $sa) {
    return dnWriteJsonFile($dataDir . '/super_admin.json', $sa);
}

function dnVerifySuperAdminLogin($dataDir, $identifier, $password) {
    $sa = dnGetSuperAdmin($dataDir);
    $idL = strtolower(trim((string)$identifier));
    $aliases = array_map('strtolower', is_array($sa['aliases'] ?? null) ? $sa['aliases'] : ['ubaid', 'superadmin']);
    $uL = strtolower(trim((string)($sa['username'] ?? 'ubaid')));
    $eL = strtolower(trim((string)($sa['email'] ?? '')));

    $matchesUser = ($idL === $uL || in_array($idL, $aliases, true) || ($eL !== '' && $idL === $eL));
    if (!$matchesUser) {
        return ['matched' => false];
    }
    $ok = !empty($sa['passwordHash']) && password_verify((string)$password, $sa['passwordHash']);
    return ['matched' => true, 'ok' => $ok, 'sa' => $sa];
}

function dnNormalizeTenantState(&$t, $effectivePlans) {
    $dirty = false;
    $tName = $t['name'] ?? ($t['businessName'] ?? '');
    if (empty($t['name']) && !empty($tName)) { $t['name'] = $tName; $dirty = true; }
    if (empty($t['businessName']) && !empty($t['name'])) { $t['businessName'] = $t['name']; $dirty = true; }

    $tSub = strtolower($t['subdomain'] ?? '');
    $explicitVert = strtoupper($t['vertical'] ?? ($t['sector'] ?? ($t['storeType'] ?? '')));
    if (in_array($explicitVert, ['RESTAURANT', 'PHARMACY', 'CLINIC_HMS', 'RETAIL_MART'], true)) {
        $vert = $explicitVert;
    } else {
        $isRest = (stripos($tName, 'Resturent') !== false || stripos($tName, 'Restaurant') !== false || stripos($tSub, 'resturent') !== false || stripos($tSub, 'restaurant') !== false || (isset($t['businessType']) && stripos($t['businessType'], 'Restaurant') !== false));
        $isPharm = (stripos($tName, 'Pharmacy') !== false || stripos($tSub, 'pharmacy') !== false || (isset($t['businessType']) && stripos($t['businessType'], 'Pharmacy') !== false));
        $isClinic = ($tSub === 'clinic' || stripos($tName, 'Clinic') !== false || stripos($tName, 'Hospital') !== false || (isset($t['businessType']) && stripos($t['businessType'], 'Clinic') !== false));
        $vert = $isRest ? 'RESTAURANT' : ($isPharm ? 'PHARMACY' : ($isClinic ? 'CLINIC_HMS' : 'RETAIL_MART'));
    }

    $bTypeMap = [
        'RESTAURANT' => 'Restaurants, Cafes & Food Outlets',
        'PHARMACY' => 'Pharmacy & Medical Store',
        'CLINIC_HMS' => 'Clinic / Hospital',
        'RETAIL_MART' => 'Retail Mart / General Store'
    ];
    if (($t['vertical'] ?? '') !== $vert || ($t['businessType'] ?? '') !== $bTypeMap[$vert]) {
        $t['vertical'] = $vert;
        $t['sector'] = $vert;
        $t['storeType'] = $vert;
        $t['businessType'] = $bTypeMap[$vert];
        $t['business_type'] = $bTypeMap[$vert];
        $dirty = true;
    }

    $curPlanType = strtoupper($t['planType'] ?? ($t['subscription']['plan'] ?? 'MONTHLY'));
    $resolved = dnResolveSectorPlanPricing($vert, $curPlanType, $effectivePlans);
    if (empty($t['planName']) || stripos($t['planName'], 'undefined') !== false) {
        $t['planName'] = $resolved['planName'];
        $dirty = true;
    }
    if (!isset($t['cycleDays']) || !is_numeric($t['cycleDays']) || intval($t['cycleDays']) <= 0) {
        $t['cycleDays'] = $resolved['days'];
        $dirty = true;
    }
    if ($curPlanType !== 'TRIAL_3_DAYS' && (!isset($t['monthlyFee']) || !is_numeric($t['monthlyFee']) || floatval($t['monthlyFee']) <= 0)) {
        $t['monthlyFee'] = $resolved['fee'];
        $dirty = true;
    } elseif (!isset($t['monthlyFee']) || !is_numeric($t['monthlyFee'])) {
        $t['monthlyFee'] = $resolved['fee'];
        $dirty = true;
    }
    $t['monthly_fee'] = $t['monthlyFee'];

    if (!isset($t['enabledModules']) || !is_array($t['enabledModules'])) {
        $t['enabledModules'] = [
            'pos' => true,
            'inventory' => true,
            'patientsLedger' => ($vert === 'CLINIC_HMS'),
            'tableManagement' => ($vert === 'RESTAURANT'),
            'expiryBatch' => ($vert === 'PHARMACY')
        ];
        $dirty = true;
    } else {
        if (!isset($t['enabledModules']['pos'])) { $t['enabledModules']['pos'] = true; $dirty = true; }
        if (!isset($t['enabledModules']['inventory'])) { $t['enabledModules']['inventory'] = true; $dirty = true; }
        if (!isset($t['enabledModules']['patientsLedger'])) { $t['enabledModules']['patientsLedger'] = ($vert === 'CLINIC_HMS'); $dirty = true; }
    }

    $uName = $t['adminName'] ?? ($t['adminUser']['name'] ?? (($t['name'] ?? 'Store') . ' Admin'));
    $uUser = $t['adminUsername'] ?? ($t['adminUser']['username'] ?? ('admin.' . $tSub));
    $uPass = $t['adminPassword'] ?? ($t['adminUser']['password'] ?? '123');
    $uRole = $t['adminUser']['role'] ?? ($vert === 'CLINIC_HMS' ? 'CLINIC_ADMIN' : 'STORE_ADMIN');
    $t['adminName'] = $uName;
    $t['adminUsername'] = $uUser;
    $t['adminPassword'] = $uPass;
    $t['adminPin'] = $t['adminPin'] ?? '1234';
    if (!isset($t['adminUser']) || !is_array($t['adminUser'])) $t['adminUser'] = [];
    $t['adminUser']['name'] = $uName;
    $t['adminUser']['username'] = $uUser;
    $t['adminUser']['password'] = $uPass;
    $t['adminUser']['role'] = $uRole;
    if (!empty($t['email'])) $t['adminUser']['email'] = $t['email'];
    if (!empty($t['phone'])) $t['adminUser']['phone'] = $t['phone'];

    $totalDays = intval($t['cycleDays'] ?? 30);
    if ($totalDays <= 0) $totalDays = 30;

    $isTrial = ($curPlanType === 'TRIAL_3_DAYS' || stripos($curPlanType, 'TRIAL') !== false || stripos($t['planName'] ?? '', 'Trial') !== false);
    if ($isTrial && $totalDays > 7) {
        $totalDays = 3;
        $t['cycleDays'] = 3;
        $dirty = true;
    }

    // Determine start timestamp
    $startTs = !empty($t['lastPaymentDate']) ? strtotime($t['lastPaymentDate']) : (!empty($t['createdAt']) ? strtotime($t['createdAt']) : time());
    if ($startTs === false || $startTs <= 0) {
        $startTs = time();
    }

    // Determine expiry timestamp
    if (!empty($t['subscriptionExpiry'])) {
        $expTs = strtotime($t['subscriptionExpiry']);
        if ($expTs === false) {
            $expTs = $startTs + ($totalDays * 86400);
            $t['subscriptionExpiry'] = date('c', $expTs);
            $dirty = true;
        }
    } else {
        $expTs = $startTs + ($totalDays * 86400);
        $t['subscriptionExpiry'] = date('c', $expTs);
        $dirty = true;
    }

    // Dynamic elapsed day calculation (real-time elapsed billing day)
    $now = time();
    $elapsedSecs = max(0, $now - $startTs);
    $dynamicCycleDay = intval(floor($elapsedSecs / 86400)) + 1;
    $storedCycleDay = intval($t['billingCycleDay'] ?? ($t['subscription']['billingCycleDay'] ?? 1));
    $cycleDay = max($dynamicCycleDay, $storedCycleDay);

    $isManualBlocked = !empty($t['manualBlocked']) || strtoupper((string)($t['status'] ?? '')) === 'BLOCKED';

    if ($isManualBlocked) {
        $subState = 'LOCKED';
        $t['manualBlocked'] = true;
        $t['status'] = 'BLOCKED';
        $graceRem = 0;
        $daysRem = 0;
        $bannerMsg = 'Access manually suspended by DigitalsNova Super Admin.';
    } elseif ($isTrial) {
        // TRIAL LOGIC: ZERO GRACE PERIOD! If time() >= expTs or cycleDay > totalDays, LOCK IMMEDIATELY!
        if ($now >= $expTs || $cycleDay > $totalDays) {
            $subState = 'LOCKED';
            $t['manualBlocked'] = false;
            $t['status'] = 'LOCKED';
            $graceRem = 0;
            $daysRem = 0;
            $cycleDay = max($cycleDay, $totalDays + 1);
            $bannerMsg = '3-Day Free Trial expired on ' . date('d M Y, h:i A', $expTs) . '. Please subscribe to a plan to continue access.';
        } else {
            $subState = 'ACTIVE';
            $t['manualBlocked'] = false;
            $t['status'] = 'ACTIVE';
            $graceRem = 0;
            $daysRem = max(0, ceil(($expTs - $now) / 86400));
            $bannerMsg = "Free Trial active ({$daysRem} day" . ($daysRem === 1 ? '' : 's') . " remaining).";
        }
    } else {
        // REGULAR PAID PLAN LOGIC: 3-Day Grace Period
        $graceEndTs = $expTs + (3 * 86400);
        if ($now >= $graceEndTs || $cycleDay > ($totalDays + 3)) {
            $subState = 'LOCKED';
            $t['manualBlocked'] = false;
            $t['status'] = 'LOCKED';
            $graceRem = 0;
            $daysRem = 0;
            $cycleDay = max($cycleDay, $totalDays + 4);
            $bannerMsg = 'Subscription expired after Day ' . ($totalDays + 3) . " (Billing Day {$cycleDay}).";
        } elseif ($now >= $expTs || $cycleDay > $totalDays) {
            $subState = 'GRACE_PERIOD';
            $t['manualBlocked'] = false;
            $t['status'] = 'ACTIVE';
            $graceRem = max(1, ceil(($graceEndTs - $now) / 86400));
            $daysRem = 0;
            $bannerMsg = "Grace Period Active (Day {$cycleDay}/" . ($totalDays + 3) . "). Renew within {$graceRem} day(s).";
        } else {
            $subState = 'ACTIVE';
            $t['manualBlocked'] = false;
            $t['status'] = 'ACTIVE';
            $graceRem = 3;
            $daysRem = max(0, ceil(($expTs - $now) / 86400));
            $bannerMsg = '';
        }
    }

    if (($t['billingCycleDay'] ?? 0) !== $cycleDay) {
        $t['billingCycleDay'] = $cycleDay;
        $dirty = true;
    }
    if (!isset($t['subscription']) || !is_array($t['subscription'])) $t['subscription'] = [];
    if (($t['subscription']['state'] ?? '') !== $subState) { $t['subscription']['state'] = $subState; $dirty = true; }
    if (($t['subscription']['plan'] ?? '') !== $curPlanType) { $t['subscription']['plan'] = $curPlanType; $dirty = true; }
    if (($t['subscription']['planName'] ?? '') !== $t['planName']) { $t['subscription']['planName'] = $t['planName']; $dirty = true; }
    if (($t['subscription']['billingCycleDay'] ?? 0) !== $cycleDay) { $t['subscription']['billingCycleDay'] = $cycleDay; $dirty = true; }
    if (($t['subscription']['totalDays'] ?? 0) !== $totalDays) { $t['subscription']['totalDays'] = $totalDays; $dirty = true; }
    if (($t['subscription']['graceDaysRemaining'] ?? -1) !== $graceRem) { $t['subscription']['graceDaysRemaining'] = $graceRem; $dirty = true; }
    if (($t['subscription']['daysRemainingInCycle'] ?? -1) !== $daysRem) { $t['subscription']['daysRemainingInCycle'] = $daysRem; $dirty = true; }
    if (($t['subscription']['monthlyFee'] ?? null) !== floatval($t['monthlyFee'])) { $t['subscription']['monthlyFee'] = floatval($t['monthlyFee']); $dirty = true; }
    if (!empty($bannerMsg)) {
        if (($t['subscription']['bannerMessage'] ?? '') !== $bannerMsg) { $t['subscription']['bannerMessage'] = $bannerMsg; $dirty = true; }
    } else {
        if (isset($t['subscription']['bannerMessage'])) { unset($t['subscription']['bannerMessage']); $dirty = true; }
    }
    if (empty($t['subscription']['validUntil']) || $t['subscription']['validUntil'] !== date('c', $expTs)) {
        $t['subscription']['validUntil'] = date('c', $expTs);
        $t['subscription']['validUntilFormatted'] = date('d M Y, h:i A', $expTs);
        $dirty = true;
    }
    return $dirty;
}

function dnGetUnifiedTenants($dataDir) {
    $primaryPath = $dataDir . '/tenants.json';
    $tenants = dnReadJsonFile($primaryPath, []);
    $otherPaths = [
        __DIR__ . '/data/tenants.json',
        dirname(__DIR__) . '/portal/api/data/tenants.json'
    ];
    $dirty = false;
    foreach ($otherPaths as $op) {
        if (realpath($op) !== realpath($primaryPath) && file_exists($op)) {
            $other = dnReadJsonFile($op, []);
            foreach ($other as $ot) {
                $oSub = strtolower($ot['subdomain'] ?? '');
                $oId = $ot['id'] ?? '';
                $exists = false;
                foreach ($tenants as $t) {
                    if ((!empty($oSub) && strtolower($t['subdomain'] ?? '') === $oSub) || (!empty($oId) && ($t['id'] ?? '') === $oId)) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $tenants[] = $ot;
                    $dirty = true;
                }
            }
        }
    }

    $deletedLookup = [];
    foreach (dnReadUnifiedJson($dataDir . '/deleted_tenants.json', 'deleted_tenants.json', []) as $dt) {
        if (!empty($dt['id'])) $deletedLookup[strtolower($dt['id'])] = true;
        if (!empty($dt['subdomain'])) $deletedLookup[strtolower($dt['subdomain'])] = true;
    }

    $filtered = [];
    foreach ($tenants as $t) {
        $tSub = strtolower($t['subdomain'] ?? '');
        $tId = strtolower($t['id'] ?? '');
        if ((!empty($tSub) && isset($deletedLookup[$tSub])) || (!empty($tId) && isset($deletedLookup[$tId]))) {
            $dirty = true;
            continue;
        }
        $filtered[] = $t;
    }

    if ($dirty) {
        dnWriteJsonFile($primaryPath, $filtered);
        dnSyncToSecondaryDataDirs('tenants.json', $filtered, $dataDir);
    }
    return $filtered;
}

function dnWorkspacePath($dataDir, $subdomain) {
    $safe = dnSanitizeSubdomain($subdomain);
    if ($safe === '') $safe = 'default';
    return $dataDir . '/workspaces/' . $safe . '.json';
}

function dnEmptyWorkspace() {
    return [
        'branches' => [],
        'patients' => [],
        'treatments' => [],
        'treatmentCatalog' => [],
        'inventory' => [],
        'sales' => [],
        'parkedSales' => [],
        'activeShift' => null,
        'closedShifts' => [],
        'discountRequests' => [],
        'auditLogs' => [],
        'smsLogs' => []
    ];
}

function dnLoadWorkspace($dataDir, $subdomain) {
    $base = dnEmptyWorkspace();
    $raw = dnReadJsonFile(dnWorkspacePath($dataDir, $subdomain), []);
    if (!is_array($raw)) return $base;
    foreach ($base as $k => $v) {
        if (array_key_exists($k, $raw)) {
            $base[$k] = ($k === 'activeShift')
                ? (is_array($raw[$k]) ? $raw[$k] : null)
                : (is_array($raw[$k]) ? $raw[$k] : []);
        }
    }
    if (empty($base['branches'])) {
        $base['branches'] = [[
            'id' => 'branch_main',
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Primary Location',
            'phone' => '',
            'status' => 'ACTIVE',
            'isDefault' => true
        ]];
    }
    return $base;
}

function dnSaveWorkspace($dataDir, $subdomain, $ws) {
    return dnWriteJsonFile(dnWorkspacePath($dataDir, $subdomain), $ws);
}

function dnDeleteWorkspace($dataDir, $subdomain) {
    $p = dnWorkspacePath($dataDir, $subdomain);
    if (file_exists($p)) @unlink($p);
}

function dnSendGmailSmtp($toEmail, $subject, $htmlBody, $senderName = 'DigitalsNova Official') {
    if (getenv('DIGITALSNOVA_SKIP_SMTP') === '1') {
        return true;
    }
    $smtpHost = 'ssl://smtp.gmail.com';
    $smtpPort = 465;
    $smtpUser = 'digitalsnovaofficial@gmail.com';
    $smtpPass = 'rfnygyzvohahoqde';

    // 1. Build clean plain-text fallback for anti-spam deliverability
    $cleanPlainText = strip_tags(preg_replace([
        '/<style[^>]*>.*?<\/style>/is',
        '/<script[^>]*>.*?<\/script>/is',
        '/<br\s*\/?>/i',
        '/<\/p>/i',
        '/<\/tr>/i',
        '/<\/div>/i',
        '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i'
    ], [
        '',
        '',
        "\r\n",
        "\r\n\r\n",
        "\r\n",
        "\r\n",
        '$2 ($1)'
    ], $htmlBody));
    $cleanPlainText = trim(html_entity_decode($cleanPlainText, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($cleanPlainText === '') {
        $cleanPlainText = $subject . "\r\nPlease view this email in an HTML-compatible client.";
    }

    // 2. Strict anti-spam headers and multipart/alternative MIME envelope
    $boundary = "==_DigitalsNova_MIME_Boundary_" . md5(uniqid((string)time(), true));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedSender = '=?UTF-8?B?' . base64_encode($senderName) . '?=';
    $messageId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@digitalsnova.com>';
    $dateHeader = date('r');

    $headers = [
        'MIME-Version: 1.0',
        'From: ' . $encodedSender . ' <' . $smtpUser . '>',
        'Reply-To: ' . $encodedSender . ' <' . $smtpUser . '>',
        'Return-Path: <' . $smtpUser . '>',
        'Sender: <' . $smtpUser . '>',
        'To: <' . $toEmail . '>',
        'Date: ' . $dateHeader,
        'Subject: ' . $encodedSubject,
        'Message-ID: ' . $messageId,
        'Auto-Submitted: auto-generated',
        'X-Mailer: DigitalsNova-Cloud-Engine/2.0',
        'X-Auto-Response-Suppress: All',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"'
    ];

    $bodyParts = [];
    $bodyParts[] = "--" . $boundary;
    $bodyParts[] = "Content-Type: text/plain; charset=UTF-8";
    $bodyParts[] = "Content-Transfer-Encoding: 8bit\r\n";
    $bodyParts[] = $cleanPlainText . "\r\n";
    $bodyParts[] = "--" . $boundary;
    $bodyParts[] = "Content-Type: text/html; charset=UTF-8";
    $bodyParts[] = "Content-Transfer-Encoding: 8bit\r\n";
    $bodyParts[] = $htmlBody . "\r\n";
    $bodyParts[] = "--" . $boundary . "--";

    $multipartPayload = implode("\r\n", $bodyParts);

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($smtpHost . ':' . $smtpPort, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return @mail($toEmail, $subject, $multipartPayload, implode("\r\n", $headers));
    }

    stream_set_timeout($socket, 12);
    $read = function($sock, $expectedCode) {
        $response = '';
        while ($line = fgets($sock, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        $code = intval(substr($response, 0, 3));
        return ($code === $expectedCode || ($expectedCode === 250 && ($code === 250 || $code === 251)));
    };
    $send = function($sock, $cmd) {
        fputs($sock, $cmd . "\r\n");
    };

    if (!$read($socket, 220)) { fclose($socket); return false; }
    $send($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'digitalsnova.com'));
    if (!$read($socket, 250)) { fclose($socket); return false; }
    $send($socket, 'AUTH LOGIN');
    if (!$read($socket, 334)) { fclose($socket); return false; }
    $send($socket, base64_encode($smtpUser));
    if (!$read($socket, 334)) { fclose($socket); return false; }
    $send($socket, base64_encode($smtpPass));
    if (!$read($socket, 235)) { fclose($socket); return false; }
    $send($socket, 'MAIL FROM:<' . $smtpUser . '>');
    if (!$read($socket, 250)) { fclose($socket); return false; }
    $send($socket, 'RCPT TO:<' . $toEmail . '>');
    if (!$read($socket, 250)) { fclose($socket); return false; }
    $send($socket, 'DATA');
    if (!$read($socket, 354)) { fclose($socket); return false; }

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $multipartPayload . "\r\n.";
    $send($socket, $payload);
    $sent = $read($socket, 250);
    $send($socket, 'QUIT');
    fclose($socket);
    return $sent;
}


