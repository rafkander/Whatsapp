<?php
/**
 * Widget Bot — mirrors the WhatsApp bot state machine for web chat.
 * Uses DB inserts instead of WA API calls.
 * type='buttons' messages are rendered by the widget as clickable options.
 */

if (!defined('APP_URL')) {
    require_once dirname(__DIR__, 2) . '/config.php';
}
require_once dirname(__DIR__) . '/helpers.php';

// ── Entry point ───────────────────────────────────────────────
function wb_bot_process(PDO $pdo, int $convId, string $content, ?string $interactiveId): void
{
    $stmt = $pdo->prepare('SELECT bot_state, bot_data, assigned_agent_id FROM conversations WHERE id = ?');
    $stmt->execute([$convId]);
    $conv = $stmt->fetch();
    if (!$conv) return;

    $state = $conv['bot_state'] ?? 'start';
    $data  = json_decode($conv['bot_data'] ?? '{}', true) ?: [];
    $id    = $interactiveId ?? '';
    $text  = trim($content);

    switch ($state) {
        case 'start':
            wb_bot_send_greeting($pdo, $convId);
            wb_bot_set_state($pdo, $convId, 'await_dept', $data);
            break;

        case 'await_dept':
            wb_bot_handle_dept($pdo, $convId, $id, $data);
            break;

        case 'alfonica_menu':
            wb_bot_handle_alfonica_menu($pdo, $convId, $id, $data);
            break;

        case 'await_support_type':
            wb_bot_handle_support_type($pdo, $convId, $id, $data);
            break;

        case 'await_other_type':
            wb_send_text($pdo, $convId, "Please type your **company name** so we can look up your account.");
            wb_bot_set_state($pdo, $convId, 'collect_name', $data);
            break;

        case 'collect_name':
            if ($text) {
                $data['company_name'] = $text;
                wb_send_text($pdo, $convId, "Thanks! Please provide your **account number or postcode** so we can locate your account.");
                wb_bot_set_state($pdo, $convId, 'collect_account', $data);
            }
            break;

        case 'collect_account':
            if ($text) {
                $data['account_ref'] = $text;
                $supportType = $data['support_type'] ?? '';
                if ($supportType === 'sup_invoicing') {
                    wb_send_buttons($pdo, $convId,
                        "For invoicing queries, would you prefer a callback or to speak with an agent now?",
                        [
                            ['id' => 'inv_callback', 'title' => 'Request Callback'],
                            ['id' => 'inv_agent',    'title' => 'Speak to Agent'],
                        ]
                    );
                    wb_bot_set_state($pdo, $convId, 'await_inv_choice', $data);
                } else {
                    wb_bot_transfer_support($pdo, $convId, $data);
                }
            }
            break;

        case 'await_inv_choice':
            if ($id === 'inv_callback') {
                wb_send_text($pdo, $convId, "Please type the **phone number** you'd like us to call you back on.");
                wb_bot_set_state($pdo, $convId, 'collect_callback', $data);
            } elseif ($id === 'inv_agent') {
                wb_bot_transfer_support($pdo, $convId, $data);
            }
            break;

        case 'collect_callback':
            if ($text) {
                $data['callback_number'] = $text;
                wb_send_text($pdo, $convId,
                    "Thank you! We've noted your callback number as **{$text}**.\n\n" .
                    "An agent from our billing team will call you back during business hours. " .
                    "Is there anything else we can help you with?"
                );
                wb_bot_transfer_support($pdo, $convId, $data);
            }
            break;

        case 'await_sales_type':
            wb_bot_handle_sales_type($pdo, $convId, $id, $data);
            break;

        case 'await_bb_existing':
            if ($id === 'bb_has_internet') {
                wb_send_text($pdo, $convId, "Who is your current internet provider?");
                wb_bot_set_state($pdo, $convId, 'collect_bb_provider', $data);
            } else {
                wb_send_text($pdo, $convId, "What is your **postcode**? We'll check coverage in your area.");
                wb_bot_set_state($pdo, $convId, 'collect_bb_postcode', $data);
            }
            break;

        case 'collect_bb_provider':
            if ($text) {
                $data['bb_provider'] = $text;
                wb_send_text($pdo, $convId, "And how many **users** will need internet access?");
                wb_bot_set_state($pdo, $convId, 'collect_bb_users', $data);
            }
            break;

        case 'collect_bb_postcode':
            if ($text) {
                $data['bb_postcode'] = $text;
                wb_send_text($pdo, $convId, "How many **users** will need internet access?");
                wb_bot_set_state($pdo, $convId, 'collect_bb_users', $data);
            }
            break;

        case 'collect_bb_users':
            if ($text) {
                $data['bb_users'] = $text;
                wb_bot_send_booking_link($pdo, $convId);
                wb_bot_send_anything_else($pdo, $convId);
                wb_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            }
            break;

        case 'await_ps_existing':
            if ($id === 'ps_has_system') {
                wb_bot_send_booking_link($pdo, $convId);
                wb_bot_send_anything_else($pdo, $convId);
                wb_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            } else {
                wb_send_text($pdo, $convId, "What is your **postcode**?");
                wb_bot_set_state($pdo, $convId, 'collect_ps_postcode', $data);
            }
            break;

        case 'collect_ps_postcode':
            if ($text) {
                $data['ps_postcode'] = $text;
                wb_send_text($pdo, $convId, "How many **staff members** will use the phone system?");
                wb_bot_set_state($pdo, $convId, 'collect_ps_staff', $data);
            }
            break;

        case 'collect_ps_staff':
            if ($text) {
                $data['ps_staff'] = $text;
                wb_bot_send_booking_link($pdo, $convId);
                wb_bot_send_anything_else($pdo, $convId);
                wb_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            }
            break;

        case 'collect_services':
            if ($text) {
                $data['other_services'] = $text;
                wb_bot_send_booking_link($pdo, $convId);
                wb_bot_send_anything_else($pdo, $convId);
                wb_bot_set_state($pdo, $convId, 'await_anything_else', $data);
            }
            break;

        case 'await_anything_else':
            if ($id === 'ae_yes') {
                wb_bot_send_alfonica_menu($pdo, $convId);
                wb_bot_set_state($pdo, $convId, 'alfonica_menu', $data);
            } elseif ($id === 'ae_no') {
                wb_send_text($pdo, $convId, "Thank you for contacting us. Have a great day! 👋");
                wb_bot_set_state($pdo, $convId, 'done', $data);
            }
            break;

        case 'done':
        default:
            break;
    }
}

// ── Send helpers ──────────────────────────────────────────────

function wb_send_text(PDO $pdo, int $convId, string $text): void
{
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'bot', ?, 'text')")
        ->execute([$convId, $text]);
}

function wb_send_buttons(PDO $pdo, int $convId, string $text, array $buttons): void
{
    $content = json_encode(['text' => $text, 'buttons' => $buttons]);
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'bot', ?, 'buttons')")
        ->execute([$convId, $content]);
}

// ── State handlers ────────────────────────────────────────────

function wb_bot_send_greeting(PDO $pdo, int $convId): void
{
    wb_send_buttons($pdo, $convId,
        "👋 Welcome to RCG! We're here to help.\n\nPlease select which team you'd like to speak with:",
        [
            ['id' => 'dept_retail',   'title' => 'Retail'],
            ['id' => 'dept_mobile',   'title' => 'Mobile'],
            ['id' => 'dept_alfonica', 'title' => 'Alfonica / UCC'],
        ]
    );
}

function wb_bot_handle_dept(PDO $pdo, int $convId, string $id, array $data): void
{
    switch ($id) {
        case 'dept_retail':
            wb_bot_route_basic($pdo, $convId, 'RCUK Retail', $data);
            break;

        case 'dept_mobile':
            wb_bot_route_basic($pdo, $convId, 'RCUK Mobile', $data);
            break;

        case 'dept_alfonica':
            if (!is_within_business_hours() || !any_agent_online()) {
                wb_send_text($pdo, $convId,
                    "Thank you for contacting **Alfonica / UCC**.\n\n" .
                    "Our team is currently outside office hours. We'll get back to you as soon as possible. " .
                    "You can also email us at support@ucc.co.uk"
                );
                wb_bot_assign_dept($pdo, $convId, 'UCC');
                wb_bot_set_state($pdo, $convId, 'done', $data);
            } else {
                wb_bot_send_alfonica_menu($pdo, $convId);
                wb_bot_set_state($pdo, $convId, 'alfonica_menu', $data);
            }
            break;

        default:
            wb_bot_send_greeting($pdo, $convId);
            break;
    }
}

function wb_bot_route_basic(PDO $pdo, int $convId, string $deptName, array $data): void
{
    if (!is_within_business_hours() || !any_agent_online()) {
        wb_send_text($pdo, $convId,
            "Thank you for contacting **{$deptName}**.\n\n" .
            "Our team is currently outside office hours. We'll get back to you as soon as possible."
        );
    } else {
        wb_send_text($pdo, $convId,
            "Thank you! Connecting you to our **{$deptName}** team now. An agent will be with you shortly. 🙂"
        );
    }
    wb_bot_assign_dept($pdo, $convId, $deptName);
    wb_bot_set_state($pdo, $convId, 'done', $data);
}

function wb_bot_send_alfonica_menu(PDO $pdo, int $convId): void
{
    wb_send_buttons($pdo, $convId,
        "How can we help you today?",
        [
            ['id' => 'menu_support', 'title' => 'Technical Support'],
            ['id' => 'menu_sales',   'title' => 'Sales Enquiry'],
        ]
    );
}

function wb_bot_handle_alfonica_menu(PDO $pdo, int $convId, string $id, array $data): void
{
    switch ($id) {
        case 'menu_support':
            wb_send_buttons($pdo, $convId,
                "Please select the type of support you need:",
                [
                    ['id' => 'sup_phone',     'title' => 'Phone System'],
                    ['id' => 'sup_internet',  'title' => 'Internet / Broadband'],
                    ['id' => 'sup_invoicing', 'title' => 'Invoicing'],
                    ['id' => 'sup_other',     'title' => 'Other'],
                ]
            );
            wb_bot_set_state($pdo, $convId, 'await_support_type', $data);
            break;

        case 'menu_sales':
            wb_send_buttons($pdo, $convId,
                "What are you interested in?",
                [
                    ['id' => 'sales_bb',    'title' => 'Broadband'],
                    ['id' => 'sales_phone', 'title' => 'Phone Systems'],
                    ['id' => 'sales_other', 'title' => 'Other Services'],
                ]
            );
            wb_bot_set_state($pdo, $convId, 'await_sales_type', $data);
            break;

        default:
            wb_bot_send_alfonica_menu($pdo, $convId);
            break;
    }
}

function wb_bot_handle_support_type(PDO $pdo, int $convId, string $id, array $data): void
{
    switch ($id) {
        case 'sup_phone':
        case 'sup_internet':
        case 'sup_invoicing':
            $data['support_type'] = $id;
            wb_send_text($pdo, $convId, "Please type your **company name** so we can look up your account.");
            wb_bot_set_state($pdo, $convId, 'collect_name', $data);
            break;

        case 'sup_other':
            wb_send_buttons($pdo, $convId,
                "Please select the category that best fits your query:",
                [
                    ['id' => 'other_hardware', 'title' => 'Hardware'],
                    ['id' => 'other_software', 'title' => 'Software'],
                    ['id' => 'other_general',  'title' => 'General Query'],
                ]
            );
            wb_bot_set_state($pdo, $convId, 'await_other_type', $data);
            break;

        default:
            wb_bot_handle_alfonica_menu($pdo, $convId, 'menu_support', $data);
            break;
    }
}

function wb_bot_handle_sales_type(PDO $pdo, int $convId, string $id, array $data): void
{
    switch ($id) {
        case 'sales_bb':
            wb_send_text($pdo, $convId,
                "Great — we offer a range of **business broadband** solutions including leased lines and fibre.\n\n" .
                "Just a couple of questions to get you the best recommendation:"
            );
            wb_send_buttons($pdo, $convId,
                "Do you currently have an internet connection?",
                [
                    ['id' => 'bb_has_internet', 'title' => 'Yes'],
                    ['id' => 'bb_no_internet',  'title' => 'No'],
                ]
            );
            wb_bot_set_state($pdo, $convId, 'await_bb_existing', $data);
            break;

        case 'sales_phone':
            wb_send_buttons($pdo, $convId,
                "Do you already have a phone system in place?",
                [
                    ['id' => 'ps_has_system', 'title' => 'Yes, existing system'],
                    ['id' => 'ps_no_system',  'title' => 'No, starting fresh'],
                ]
            );
            wb_bot_set_state($pdo, $convId, 'await_ps_existing', $data);
            break;

        case 'sales_other':
            wb_send_text($pdo, $convId, "Please describe the services or solutions you're interested in:");
            wb_bot_set_state($pdo, $convId, 'collect_services', $data);
            break;

        default:
            wb_bot_handle_alfonica_menu($pdo, $convId, 'menu_sales', $data);
            break;
    }
}

// ── Helpers ───────────────────────────────────────────────────

function wb_bot_set_state(PDO $pdo, int $convId, string $state, array $data): void
{
    $pdo->prepare('UPDATE conversations SET bot_state = ?, bot_data = ? WHERE id = ?')
        ->execute([$state, json_encode($data), $convId]);
}

function wb_bot_assign_dept(PDO $pdo, int $convId, string $deptName): void
{
    $stmt = $pdo->prepare('SELECT id FROM departments WHERE name = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$deptName]);
    $dept = $stmt->fetch();
    if ($dept) {
        $pdo->prepare('UPDATE conversations SET dept_id = ? WHERE id = ?')
            ->execute([$dept['id'], $convId]);
    }
}

function wb_bot_transfer_support(PDO $pdo, int $convId, array $data): void
{
    $companyName = $data['company_name']  ?? 'Unknown';
    $accountRef  = $data['account_ref']   ?? 'Not provided';
    $supportType = $data['support_type']  ?? '';

    $deptMap = [
        'sup_phone'     => 'UCC',
        'sup_internet'  => 'UCC BroadBand',
        'sup_invoicing' => 'UCC Hosted',
    ];
    $deptName = $deptMap[$supportType] ?? 'UCC+ 9';

    wb_send_text($pdo, $convId,
        "Thank you! I've passed your details to our support team.\n\n" .
        "**Company:** {$companyName}\n**Reference:** {$accountRef}\n\n" .
        "An agent will review your case and be in touch shortly. 🙂"
    );

    $callbackNum = $data['callback_number'] ?? null;
    $note = "Bot collected: Company={$companyName}, Ref={$accountRef}, Type={$supportType}";
    if ($callbackNum) $note .= ", Callback={$callbackNum}";
    $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, type) VALUES (?, 'system', ?, 'system')")
        ->execute([$convId, $note]);

    wb_bot_assign_dept($pdo, $convId, $deptName);
    wb_bot_set_state($pdo, $convId, 'done', $data);
}

function wb_bot_send_booking_link(PDO $pdo, int $convId): void
{
    wb_send_text($pdo, $convId,
        "To speak with a member of our sales team, you can book a convenient time using our online calendar:\n\n" .
        "📅 https://outlook.office365.com/book/UCCSales@ucc.co.uk/\n\n" .
        "Alternatively, one of our team will reach out to you."
    );
}

function wb_bot_send_anything_else(PDO $pdo, int $convId): void
{
    wb_send_buttons($pdo, $convId,
        "Is there anything else we can help you with today?",
        [
            ['id' => 'ae_yes', 'title' => 'Yes please'],
            ['id' => 'ae_no',  'title' => 'No, thanks'],
        ]
    );
}
