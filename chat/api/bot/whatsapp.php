<?php
/**
 * WhatsApp Bot — state machine for routing new WA conversations
 * Called from api/webhook/whatsapp.php after message is stored.
 *
 * States: start → await_dept → [alfonica_menu | done]
 *   Alfonica branch: alfonica_menu → await_support_type / await_sales_type → ...
 *   All paths end at 'done' where human agents take over.
 */

if (!defined('APP_URL')) {
    require_once dirname(__DIR__, 2) . '/config.php';
}
require_once dirname(__DIR__) . '/helpers.php';

// ── Entry point ───────────────────────────────────────────────
function wa_bot_process(PDO $pdo, int $convId, string $from, string $content, ?string $interactiveId, ?array $creds = null, string $botFlow = 'standard'): void
{
    // Fetch current conv state
    try {
        $stmt = $pdo->prepare('SELECT bot_state, bot_data, assigned_agent_id FROM conversations WHERE id = ?');
        $stmt->execute([$convId]);
        $conv = $stmt->fetch();
    } catch (\PDOException $e) {
        error_log('wa_bot: missing column — run: ALTER TABLE conversations ADD COLUMN bot_state VARCHAR(50) DEFAULT NULL, ADD COLUMN bot_data TEXT DEFAULT NULL');
        return;
    }
    if (!$conv) return;

    $state = $conv['bot_state'] ?? 'start';
    $data  = json_decode($conv['bot_data'] ?? '{}', true) ?: [];
    $id    = $interactiveId ?? '';
    $text  = trim($content);

    switch ($state) {
        case 'start':
            if ($botFlow === 'alfonica') {
                wa_send_text($from, "👋 Welcome to *Alfonica / UCC*! How can we help you today?", $creds);
                wa_bot_send_alfonica_menu($from, $creds);
                wa_bot_set_state($pdo, $convId, 'alfonica_menu', $data);
            } else {
                wa_bot_send_greeting($from, $creds);
                wa_bot_set_state($pdo, $convId, 'await_dept', $data);
            }
            break;

        case 'await_dept':
            wa_bot_handle_dept($pdo, $convId, $from, $id, $data, $creds);
            break;

        case 'alfonica_menu':
            wa_bot_handle_alfonica_menu($pdo, $convId, $from, $id, $data, $creds);
            break;

        case 'await_support_type':
            wa_bot_handle_support_type($pdo, $convId, $from, $id, $data, $creds);
            break;

        case 'await_other_type':
            wa_send_text($from, "Please type your *company name* so we can look up your account.", $creds);
            wa_bot_set_state($pdo, $convId, 'collect_name', $data);
            break;

        case 'collect_name':
            if ($text) {
                $data['company_name'] = $text;
                wa_send_text($from, "Thanks! Please provide your *account number or postcode* so we can locate your account.", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_account', $data);
            }
            break;

        case 'collect_account':
            if ($text) {
                $data['account_ref'] = $text;
                $supportType = $data['support_type'] ?? '';
                if ($supportType === 'sup_invoicing') {
                    wa_send_buttons($from,
                        "For invoicing queries, would you prefer a callback or to speak with an agent now?",
                        [
                            ['id' => 'inv_callback', 'title' => 'Request Callback'],
                            ['id' => 'inv_agent',    'title' => 'Speak to Agent'],
                        ],
                        $creds
                    );
                    wa_bot_set_state($pdo, $convId, 'await_inv_choice', $data);
                } else {
                    wa_bot_transfer_support($pdo, $convId, $from, $data, $creds);
                }
            }
            break;

        case 'await_inv_choice':
            if ($id === 'inv_callback') {
                wa_send_text($from, "Please type the *phone number* you'd like us to call you back on.", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_callback', $data);
            } elseif ($id === 'inv_agent') {
                wa_bot_transfer_support($pdo, $convId, $from, $data, $creds);
            }
            break;

        case 'collect_callback':
            if ($text) {
                $data['callback_number'] = $text;
                wa_send_text($from,
                    "Thank you! We've noted your callback number as *{$text}*.\n\n" .
                    "An agent from our billing team will call you back during business hours. " .
                    "Is there anything else we can help you with?",
                    $creds
                );
                wa_bot_transfer_support($pdo, $convId, $from, $data, $creds);
            }
            break;

        case 'await_sales_type':
            wa_bot_handle_sales_type($pdo, $convId, $from, $id, $data, $creds);
            break;

        case 'await_bb_existing':
            if ($id === 'bb_has_internet') {
                wa_send_text($from, "Who is your current internet provider?", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_bb_provider', $data);
            } else {
                wa_send_text($from, "What is your *postcode*? We'll check coverage in your area.", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_bb_postcode', $data);
            }
            break;

        case 'collect_bb_provider':
            if ($text) {
                $data['bb_provider'] = $text;
                wa_send_text($from, "And how many *users* will need internet access?", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_bb_users', $data);
            }
            break;

        case 'collect_bb_postcode':
            if ($text) {
                $data['bb_postcode'] = $text;
                wa_send_text($from, "How many *users* will need internet access?", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_bb_users', $data);
            }
            break;

        case 'collect_bb_users':
            if ($text) {
                $data['bb_users'] = $text;
                wa_bot_send_booking_link($from, $creds);
                wa_bot_send_anything_else($from, $creds);
                wa_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            }
            break;

        case 'await_ps_existing':
            if ($id === 'ps_has_system') {
                wa_bot_send_booking_link($from, $creds);
                wa_bot_send_anything_else($from, $creds);
                wa_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            } else {
                wa_send_text($from, "What is your *postcode*?", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_ps_postcode', $data);
            }
            break;

        case 'collect_ps_postcode':
            if ($text) {
                $data['ps_postcode'] = $text;
                wa_send_text($from, "How many *staff members* will use the phone system?", $creds);
                wa_bot_set_state($pdo, $convId, 'collect_ps_staff', $data);
            }
            break;

        case 'collect_ps_staff':
            if ($text) {
                $data['ps_staff'] = $text;
                wa_bot_send_booking_link($from, $creds);
                wa_bot_send_anything_else($from, $creds);
                wa_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            }
            break;

        case 'collect_services':
            if ($text) {
                $data['other_services'] = $text;
                wa_bot_send_booking_link($from, $creds);
                wa_bot_send_anything_else($from, $creds);
                wa_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            }
            break;

        case 'await_anything_else':
            if ($id === 'ae_yes') {
                wa_bot_send_alfonica_menu($from, $creds);
                wa_bot_set_state($pdo, $convId, 'alfonica_menu', $data);
            } elseif ($id === 'ae_no') {
                wa_send_text($from, "Thank you for contacting UCC. Have a great day! 👋", $creds);
                wa_bot_set_state($pdo, $convId, 'done', $data);
            }
            break;

        case 'done':
        default:
            // No-op — human agents handle it
            break;
    }
}

// ─────────────────────────────────────────────────────────────
// State handlers
// ─────────────────────────────────────────────────────────────

function wa_bot_send_greeting(string $from, ?array $creds): void
{
    wa_send_buttons($from,
        "👋 Welcome to *RCG*! We're here to help.\n\nPlease select which team you'd like to speak with:",
        [
            ['id' => 'dept_retail',   'title' => 'Retail'],
            ['id' => 'dept_mobile',   'title' => 'Mobile'],
            ['id' => 'dept_alfonica', 'title' => 'Alfonica / UCC'],
        ],
        $creds
    );
}

function wa_bot_handle_dept(PDO $pdo, int $convId, string $from, string $id, array $data, ?array $creds): void
{
    switch ($id) {
        case 'dept_retail':
            wa_bot_route_basic($pdo, $convId, $from, 'RCUK Retail', $data, $creds);
            break;

        case 'dept_mobile':
            wa_bot_route_basic($pdo, $convId, $from, 'RCUK Mobile', $data, $creds);
            break;

        case 'dept_alfonica':
            if (!is_within_business_hours() || !any_agent_online()) {
                wa_send_text($from,
                    "Thank you for contacting *Alfonica / UCC*.\n\n" .
                    "Our team is currently outside office hours. We'll get back to you as soon as possible. " .
                    "You can also email us at support@ucc.co.uk",
                    $creds
                );
                wa_bot_assign_dept($pdo, $convId, 'UCC+ 9');
                wa_bot_set_state($pdo, $convId, 'done', $data);
            } else {
                wa_bot_send_alfonica_menu($from, $creds);
                wa_bot_set_state($pdo, $convId, 'alfonica_menu', $data);
            }
            break;

        default:
            wa_bot_send_greeting($from, $creds);
            break;
    }
}

function wa_bot_route_basic(PDO $pdo, int $convId, string $from, string $deptName, array $data, ?array $creds): void
{
    if (!is_within_business_hours() || !any_agent_online()) {
        wa_send_text($from,
            "Thank you for contacting *{$deptName}*.\n\n" .
            "Our team is currently outside office hours. We'll get back to you as soon as possible.",
            $creds
        );
    } else {
        wa_send_text($from,
            "Thank you! Connecting you to our *{$deptName}* team now. An agent will be with you shortly. 🙂",
            $creds
        );
    }
    wa_bot_assign_dept($pdo, $convId, $deptName);
    wa_bot_set_state($pdo, $convId, 'done', $data);
}

function wa_bot_send_alfonica_menu(string $from, ?array $creds): void
{
    wa_send_buttons($from,
        "How can we help you today?",
        [
            ['id' => 'menu_support', 'title' => 'Technical Support'],
            ['id' => 'menu_sales',   'title' => 'Sales Enquiry'],
        ],
        $creds
    );
}

function wa_bot_handle_alfonica_menu(PDO $pdo, int $convId, string $from, string $id, array $data, ?array $creds): void
{
    switch ($id) {
        case 'menu_support':
            wa_send_list($from,
                "Please select the type of support you need:",
                "Choose support type",
                [
                    ['id' => 'sup_phone',     'title' => 'Phone System',        'description' => 'Calls, handsets, extensions'],
                    ['id' => 'sup_internet',  'title' => 'Internet / Broadband', 'description' => 'Connectivity issues'],
                    ['id' => 'sup_invoicing', 'title' => 'Invoicing',            'description' => 'Bills, payments, accounts'],
                    ['id' => 'sup_other',     'title' => 'Other',                'description' => 'Something else'],
                ],
                $creds
            );
            wa_bot_set_state($pdo, $convId, 'await_support_type', $data);
            break;

        case 'menu_sales':
            wa_send_buttons($from,
                "What are you interested in?",
                [
                    ['id' => 'sales_bb',    'title' => 'Broadband'],
                    ['id' => 'sales_phone', 'title' => 'Phone Systems'],
                    ['id' => 'sales_other', 'title' => 'Other Services'],
                ],
                $creds
            );
            wa_bot_set_state($pdo, $convId, 'await_sales_type', $data);
            break;

        default:
            wa_bot_send_alfonica_menu($from, $creds);
            break;
    }
}

function wa_bot_handle_support_type(PDO $pdo, int $convId, string $from, string $id, array $data, ?array $creds): void
{
    switch ($id) {
        case 'sup_phone':
        case 'sup_internet':
        case 'sup_invoicing':
            $data['support_type'] = $id;
            wa_send_text($from, "Please type your *company name* so we can look up your account.", $creds);
            wa_bot_set_state($pdo, $convId, 'collect_name', $data);
            break;

        case 'sup_other':
            wa_send_buttons($from,
                "Please select the category that best fits your query:",
                [
                    ['id' => 'other_hardware', 'title' => 'Hardware'],
                    ['id' => 'other_software', 'title' => 'Software'],
                    ['id' => 'other_general',  'title' => 'General Query'],
                ],
                $creds
            );
            wa_bot_set_state($pdo, $convId, 'await_other_type', $data);
            break;

        default:
            wa_bot_handle_alfonica_menu($pdo, $convId, $from, 'menu_support', $data, $creds);
            break;
    }
}

function wa_bot_handle_sales_type(PDO $pdo, int $convId, string $from, string $id, array $data, ?array $creds): void
{
    switch ($id) {
        case 'sales_bb':
            wa_send_text($from,
                "Great — we offer a range of *business broadband* solutions including leased lines and fibre.\n\n" .
                "Just a couple of questions to get you the best recommendation:",
                $creds
            );
            wa_send_buttons($from,
                "Do you currently have an internet connection?",
                [
                    ['id' => 'bb_has_internet', 'title' => 'Yes'],
                    ['id' => 'bb_no_internet',  'title' => 'No'],
                ],
                $creds
            );
            wa_bot_set_state($pdo, $convId, 'await_bb_existing', $data);
            break;

        case 'sales_phone':
            wa_send_buttons($from,
                "Do you already have a phone system in place?",
                [
                    ['id' => 'ps_has_system', 'title' => 'Yes, existing system'],
                    ['id' => 'ps_no_system',  'title' => 'No, starting fresh'],
                ],
                $creds
            );
            wa_bot_set_state($pdo, $convId, 'await_ps_existing', $data);
            break;

        case 'sales_other':
            wa_send_text($from, "Please describe the services or solutions you're interested in:", $creds);
            wa_bot_set_state($pdo, $convId, 'collect_services', $data);
            break;

        default:
            wa_bot_handle_alfonica_menu($pdo, $convId, $from, 'menu_sales', $data, $creds);
            break;
    }
}

// ─────────────────────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────────────────────

function wa_bot_set_state(PDO $pdo, int $convId, string $state, array $data): void
{
    $pdo->prepare('UPDATE conversations SET bot_state = ?, bot_data = ? WHERE id = ?')
        ->execute([$state, json_encode($data), $convId]);
}

function wa_bot_assign_dept(PDO $pdo, int $convId, string $deptName): void
{
    $stmt = $pdo->prepare('SELECT id FROM departments WHERE name = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$deptName]);
    $dept = $stmt->fetch();
    if ($dept) {
        $pdo->prepare('UPDATE conversations SET dept_id = ? WHERE id = ?')
            ->execute([$dept['id'], $convId]);
    }
}

function wa_bot_transfer_support(PDO $pdo, int $convId, string $from, array $data, ?array $creds): void
{
    $companyName = $data['company_name'] ?? 'Unknown';
    $accountRef  = $data['account_ref']  ?? 'Not provided';
    $supportType = $data['support_type'] ?? '';

    $deptMap = [
        'sup_phone'     => 'UCC+ 9',
        'sup_internet'  => 'UCC Broadband',
        'sup_invoicing' => 'UCC Hosted',
    ];
    $deptName = $deptMap[$supportType] ?? 'UCC+ 9';

    wa_send_text($from,
        "Thank you! I've passed your details to our support team.\n\n" .
        "*Company:* {$companyName}\n*Reference:* {$accountRef}\n\n" .
        "An agent will review your case and be in touch shortly. 🙂",
        $creds
    );

    $callbackNum = $data['callback_number'] ?? null;
    $note = "Bot collected: Company={$companyName}, Ref={$accountRef}, Type={$supportType}";
    if ($callbackNum) $note .= ", Callback={$callbackNum}";
    wa_bot_save_note($pdo, $convId, $note);

    wa_bot_assign_dept($pdo, $convId, $deptName);
    wa_bot_set_state($pdo, $convId, 'done', $data);
}

function wa_bot_send_booking_link(string $from, ?array $creds): void
{
    wa_send_text($from,
        "To speak with a member of our sales team, you can book a convenient time using our online calendar:\n\n" .
        "📅 https://outlook.office365.com/book/UCCSales@ucc.co.uk/\n\n" .
        "Alternatively, one of our team will reach out to you.",
        $creds
    );
}

function wa_bot_send_anything_else(string $from, ?array $creds): void
{
    wa_send_buttons($from,
        "Is there anything else we can help you with today?",
        [
            ['id' => 'ae_yes', 'title' => 'Yes please'],
            ['id' => 'ae_no',  'title' => 'No, thanks'],
        ],
        $creds
    );
}

function wa_bot_save_note(PDO $pdo, int $convId, string $note): void
{
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', ?, 'system')")
        ->execute([$convId, $note]);
}
