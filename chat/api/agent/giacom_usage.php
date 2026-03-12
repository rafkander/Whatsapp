<?php
/**
 * Giacom Mobile API — usage data for a contact
 *
 * GET  ?contact_id=X[&refresh=1]
 *   Returns billing services + number attributes + shared bundle usage for the contact's CLI.
 *
 * POST {action:"sim_lookup", network, sim_serial}
 *   Check which CLI a SIM serial is assigned to.
 *
 * POST {action:"number_attributes", mobile_number[, refresh:1]}
 *   Raw attributes for any mobile number.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_agent();

// ── GET: full usage summary for a contact ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contactId = (int)($_GET['contact_id'] ?? 0);
    if (!$contactId) json_error('contact_id required');

    $stmt = db()->prepare('SELECT id, name, phone, whatsapp_number, sms_number FROM contacts WHERE id = ?');
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch();
    if (!$contact) json_error('Contact not found', 404);

    // Best CLI: prefer phone, fallback to whatsapp/sms number, normalise to UK format
    $rawCli = $contact['phone'] ?: $contact['whatsapp_number'] ?: $contact['sms_number'];
    if (!$rawCli) json_error('No phone number on this contact');

    // Normalise: 447... → 07...  (Giacom expects UK local format or E.164 — test both)
    $digits = preg_replace('/\D/', '', $rawCli);
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '44') {
        $cli = '0' . substr($digits, 2); // 447385297011 → 07385297011
    } else {
        $cli = $digits;
    }

    $refresh = !empty($_GET['refresh']);

    // ── 1. Billing services ───────────────────────────────────
    $billingRaw = giacom_billing_services($cli);
    $activeServices = [];
    $rawBlocks = $billingRaw['services']['block'] ?? [];
    if (!empty($rawBlocks) && isset($rawBlocks['name'])) $rawBlocks = [$rawBlocks]; // single block
    foreach ($rawBlocks as $svc) {
        if (($svc['is-active'] ?? '0') !== '1') continue;
        $activeServices[] = [
            'name'       => $svc['name'] ?? $svc['description'] ?? '',
            'type'       => $svc['service-type'] ?? '',
            'price'      => $svc['rate-before-discount'] ?? '',
            'start_date' => isset($svc['start-date']) ? substr($svc['start-date'], 0, 10) : '',
        ];
    }

    // ── 2. Number attributes ──────────────────────────────────
    $attributesRaw = giacom_number_attributes($cli, $refresh);
    $simInfo   = [];
    $services  = [];
    $bars      = [];

    $groups = $attributesRaw['grouped-attributes']['block'] ?? [];
    if (!empty($groups) && isset($groups['name'])) $groups = [$groups]; // single group

    // Labels we want to surface per group
    $wantedMain = ['Sim Serial Number', 'Connect Date/Time', 'Last Updated', 'Network Status'];
    $wantedSvc  = ['4G Allowed', '5G Service', 'MMS Service', 'Voicemail', 'Wifi Calling', 'Video Telephony', 'Conference calling enabled'];
    $wantedBars = ['GPRS', 'GPRS Roaming', 'International Roaming', 'International outgoing calls', 'Incoming Calls', 'Outgoing Calls', 'Incoming Roaming', 'Outgoing Roaming', 'Admin'];

    foreach ($groups as $group) {
        $groupName = $group['name'] ?? '';
        $attrList  = $group['attributes']['block'] ?? [];
        if (!empty($attrList) && isset($attrList['attribute'])) $attrList = [$attrList]; // single attr

        foreach ($attrList as $attrEntry) {
            $label = $attrEntry['attribute']['label'] ?? ($attrEntry['attribute']['name'] ?? '');
            $value = $attrEntry['value'] ?? null;
            if ($value === null || $value === '') continue;

            if ($groupName === 'Main' && in_array($label, $wantedMain)) {
                // Format dates
                if (str_contains((string)$value, 'T')) $value = substr($value, 0, 10);
                $simInfo[$label] = $value;
            } elseif ($groupName === 'Services' && in_array($label, $wantedSvc)) {
                $services[$label] = $value === '1' ? 'Yes' : 'No';
            } elseif ($groupName === 'Bars' && in_array($label, $wantedBars)) {
                if ($value === '1') $bars[] = $label; // only show active bars
            }
        }
    }

    // ── 3. Bill limit ─────────────────────────────────────────
    // Response: <block name="bill-limits"><block><a name="limit">2500.00</a>...
    $billLimitRaw = giacom_bill_limit($cli);
    $billLimit = null;
    $blInner = $billLimitRaw['bill-limits']['block'] ?? [];
    if (!empty($blInner)) {
        // Could be a single block or array of blocks — take first active one
        $first = isset($blInner['limit']) ? $blInner : ($blInner[0] ?? []);
        $val   = $first['limit'] ?? null;
        if ($val && $val !== '0') $billLimit = $val;
    }

    // ── 4. Shared bundle usage ────────────────────────────────
    $bundleUsage = null;
    $bundlesRes  = giacom_shared_bundles(false);
    $bundles     = $bundlesRes['bundle'] ?? [];
    if (!empty($bundles) && isset($bundles['shared-bundle-id'])) $bundles = [$bundles];

    foreach ($bundles as $bundle) {
        $bundleId = $bundle['shared-bundle-id'] ?? null;
        if (!$bundleId) continue;
        $cliRes  = giacom_shared_bundle_clis((string)$bundleId, $cli);
        $cliData = $cliRes['cli'] ?? null;
        if ($cliData) {
            $bundleUsage = [
                'product_name'          => $bundle['product-name'] ?? null,
                'network'               => $bundle['network'] ?? null,
                'units_used_this_month' => $cliData['units-used-this-month'] ?? null,
                'units_used_last_month' => $cliData['units-used-last-month'] ?? null,
            ];
            break;
        }
    }

    // ── 5. aBillity CDR data usage ────────────────────────────
    $cdrUsage = abillity_cdr_usage($cli);

    json_success([
        'contact'          => $contact,
        'cli'              => $cli,
        'active_services'  => $activeServices,
        'sim_info'         => $simInfo,
        'services'         => $services,
        'active_bars'      => $bars,
        'bill_limit'       => $billLimit,
        'bundle'           => $bundleUsage,
        'last_refresh'     => $attributesRaw['last-refresh-date'] ?? null,
        'cdr_usage'        => $cdrUsage,
    ]);
}

// ── POST: explicit actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = request_body();
    $action = $body['action'] ?? '';

    if ($action === 'sim_lookup') {
        $network   = trim($body['network']    ?? '');
        $simSerial = trim($body['sim_serial'] ?? '');
        if (!$network || !$simSerial) json_error('network and sim_serial required');
        json_success(['result' => giacom_sim_lookup($network, $simSerial)]);
    }

    if ($action === 'number_attributes') {
        $mobile  = trim($body['mobile_number'] ?? '');
        $refresh = !empty($body['refresh']);
        if (!$mobile) json_error('mobile_number required');
        json_success(['result' => giacom_number_attributes($mobile, $refresh)]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
