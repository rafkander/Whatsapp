/**
 * Chat Widget — Shadow DOM, vanilla JS
 * Self-contained, zero dependencies
 */
(function () {
    'use strict';

    const CONFIG = window.__ChatWidgetConfig || {};
    const API_BASE = CONFIG.apiBase || '';
    const POLL_INTERVAL = 2000; // ms

    if (!API_BASE) {
        console.error('[ChatWidget] apiBase not configured');
        return;
    }

    // ── Brand Detection ───────────────────────────────────────
    // Priority: data-brand attribute > hostname auto-detect > server settings
    const BRANDS = {
        rcuk: {
            name:    'RCUK Support',
            color:   '#1e1080',
            tagline: 'Rose Communications UK',
            logo:    API_BASE + '/widget/brand/rcuk.png',
        },
        alfonica: {
            name:    'Alfonica Support',
            color:   '#6d4aae',
            tagline: 'Alfonica',
            logo:    API_BASE + '/widget/brand/alfonica.png',
        },
    };

    function detectBrand() {
        // Explicit override via data-brand attribute on the script tag
        const explicit = (CONFIG.brand || '').toLowerCase();
        if (BRANDS[explicit]) return BRANDS[explicit];

        // Auto-detect from the page's hostname
        const host = window.location.hostname.toLowerCase();
        if (host.includes('rcuk')) return BRANDS.rcuk;
        if (host.includes('alfonica')) return BRANDS.alfonica;

        return null; // fall back to server settings
    }

    const BRAND = detectBrand();

    // CSS bundled inline — no separate fetch needed
    const WIDGET_CSS = `*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:host {
    --primary: #C0392B;
    --primary-dark: #96281B;
    --primary-light: #FDECEA;
    --text: #1e293b;
    --text-muted: #64748b;
    --border: #e2e8f0;
    --bg: #ffffff;
    --bubble-agent: #f1f5f9;
    --bubble-visitor: var(--primary);
    --bubble-visitor-text: #ffffff;
    --radius: 16px;
    --shadow: 0 8px 40px rgba(0,0,0,.18);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.chat-launcher {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--primary);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 20px rgba(0,0,0,.25);
    transition: transform .2s, box-shadow .2s;
    z-index: 2147483647;
}
.chat-launcher:hover { transform: scale(1.08); }
.chat-launcher svg { width: 28px; height: 28px; fill: currentColor; transition: opacity .2s; }
.chat-launcher .icon-close { display: none; }
.chat-launcher.open .icon-chat { display: none; }
.chat-launcher.open .icon-close { display: block; }
.chat-launcher .badge {
    position: absolute; top: -4px; right: -4px;
    background: #ef4444; color: #fff; font-size: 11px; font-weight: 700;
    min-width: 20px; height: 20px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 5px; border: 2px solid #fff;
}
.chat-launcher .badge:empty, .chat-launcher .badge[data-count="0"] { display: none; }
.chat-window {
    position: fixed;
    bottom: 96px;
    right: 24px;
    width: 380px;
    max-width: calc(100vw - 32px);
    height: 560px;
    max-height: calc(100vh - 120px);
    background: var(--bg);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 2147483646;
    transform: scale(.94) translateY(12px);
    opacity: 0;
    pointer-events: none;
    transition: transform .22s cubic-bezier(.34,1.56,.64,1), opacity .18s ease;
}
.chat-window.open { transform: scale(1) translateY(0); opacity: 1; pointer-events: all; }
.chat-header {
    background: var(--primary); color: #fff;
    padding: 16px 18px; display: flex; align-items: center; gap: 12px; flex-shrink: 0;
}
.chat-header-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    background: rgba(255,255,255,.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 700; overflow: hidden; flex-shrink: 0;
}
.chat-header-avatar img { width: 100%; height: 100%; object-fit: cover; }
.chat-header-info { flex: 1; }
.chat-header-name { font-weight: 700; font-size: 1rem; line-height: 1.2; }
.chat-header-status { font-size: .78rem; opacity: .85; display: flex; align-items: center; gap: 5px; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; display: inline-block; }
.status-dot.away { background: #facc15; }
.status-dot.offline { background: #94a3b8; }
.chat-header-close {
    background: none; border: none; color: rgba(255,255,255,.8);
    cursor: pointer; padding: 4px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center; transition: background .15s;
}
.chat-header-close:hover { background: rgba(255,255,255,.15); color: #fff; }
.prechat { padding: 24px 22px; overflow-y: auto; flex: 1; }
.prechat-title { font-size: 1.1rem; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.prechat-subtitle { font-size: .875rem; color: var(--text-muted); margin-bottom: 22px; }
.prechat-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding-bottom: 18px; border-bottom: 1px solid var(--border); }
.prechat-brand-logo { width: 36px; height: 36px; border-radius: 10px; background: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.prechat-brand-text { line-height: 1.25; }
.prechat-brand-name { font-size: .9rem; font-weight: 700; color: var(--text); }
.prechat-brand-tagline { font-size: .74rem; color: var(--text-muted); }
.form-group { margin-bottom: 14px; }
.form-label { display: block; font-size: .82rem; font-weight: 600; color: var(--text); margin-bottom: 5px; }
.form-input, .form-select {
    width: 100%; padding: 10px 12px; border: 1.5px solid var(--border);
    border-radius: 10px; font-size: .9rem; color: var(--text); background: var(--bg);
    transition: border-color .2s, box-shadow .2s; outline: none; font-family: inherit;
}
.form-input:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,0,0,.08); }
.form-select { cursor: pointer; }
.btn-start {
    width: 100%; padding: 12px; background: var(--primary); color: #fff;
    border: none; border-radius: 10px; font-size: .95rem; font-weight: 600;
    cursor: pointer; margin-top: 6px; transition: background .2s; font-family: inherit;
}
.btn-start:hover { background: var(--primary-dark); }
.btn-start:disabled { opacity: .6; cursor: not-allowed; }
.messages-area {
    flex: 1; overflow-y: auto; padding: 14px 16px;
    display: flex; flex-direction: column; gap: 10px; scroll-behavior: smooth;
}
.messages-area::-webkit-scrollbar { width: 4px; }
.messages-area::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }
.msg-row { display: flex; flex-direction: column; gap: 2px; }
.msg-row.visitor { align-items: flex-end; }
.msg-row.agent, .msg-row.bot, .msg-row.system { align-items: flex-start; }
.msg-bubble { max-width: 80%; padding: 9px 13px; border-radius: 16px; font-size: .9rem; line-height: 1.45; word-break: break-word; }
.msg-row.visitor .msg-bubble { background: var(--primary); color: #fff; border-bottom-right-radius: 4px; }
.msg-row.agent .msg-bubble, .msg-row.bot .msg-bubble { background: var(--bubble-agent); color: var(--text); border-bottom-left-radius: 4px; }
.msg-row.system .msg-bubble { background: transparent; color: var(--text-muted); font-size: .78rem; font-style: italic; padding: 4px 8px; text-align: center; max-width: 100%; }
.msg-sender { font-size: .73rem; color: var(--text-muted); margin-bottom: 3px; padding: 0 2px; font-weight: 600; }
.msg-time { font-size: .7rem; color: var(--text-muted); padding: 0 2px; margin-top: 1px; }
.msg-row.visitor .msg-time { text-align: right; }
.msg-image { max-width: 200px; border-radius: 10px; cursor: pointer; display: block; }
.msg-file-link { display: flex; align-items: center; gap: 7px; color: inherit; text-decoration: none; font-size: .85rem; }
.msg-file-link:hover { text-decoration: underline; }
.typing-indicator { display: flex; align-items: center; gap: 4px; padding: 10px 14px; background: var(--bubble-agent); border-radius: 16px; border-bottom-left-radius: 4px; width: fit-content; }
.typing-dot { width: 7px; height: 7px; background: #94a3b8; border-radius: 50%; animation: bounce 1.2s infinite; }
.typing-dot:nth-child(2) { animation-delay: .2s; }
.typing-dot:nth-child(3) { animation-delay: .4s; }
@keyframes bounce { 0%, 80%, 100% { transform: translateY(0); opacity: .5; } 40% { transform: translateY(-6px); opacity: 1; } }
.input-area { padding: 10px 12px; border-top: 1px solid var(--border); display: flex; align-items: flex-end; gap: 8px; background: var(--bg); flex-shrink: 0; }
.msg-input { flex: 1; border: 1.5px solid var(--border); border-radius: 20px; padding: 9px 14px; font-size: .9rem; resize: none; outline: none; font-family: inherit; max-height: 100px; min-height: 40px; line-height: 1.4; color: var(--text); background: var(--bg); transition: border-color .2s; }
.msg-input:focus { border-color: var(--primary); }
.msg-input::placeholder { color: #94a3b8; }
.input-btn { width: 38px; height: 38px; border-radius: 50%; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background .15s, transform .1s; }
.btn-send { background: var(--primary); color: #fff; }
.btn-send:hover { background: var(--primary-dark); }
.btn-send:active { transform: scale(.93); }
.btn-send:disabled { opacity: .5; cursor: not-allowed; }
.btn-attach { background: var(--bubble-agent); color: var(--text-muted); }
.btn-attach:hover { background: #e2e8f0; }
.input-btn svg { width: 18px; height: 18px; fill: currentColor; }
.rating-section { padding: 18px 20px; border-top: 1px solid var(--border); text-align: center; flex-shrink: 0; }
.rating-title { font-size: .9rem; font-weight: 600; color: var(--text); margin-bottom: 12px; }
.stars { display: flex; justify-content: center; gap: 6px; margin-bottom: 12px; }
.star { font-size: 1.8rem; cursor: pointer; color: #e2e8f0; transition: color .15s, transform .1s; line-height: 1; }
.star:hover, .star.active { color: #f59e0b; transform: scale(1.15); }
.rating-comment { width: 100%; border: 1.5px solid var(--border); border-radius: 8px; padding: 8px 12px; font-size: .85rem; resize: none; font-family: inherit; outline: none; margin-bottom: 10px; color: var(--text); }
.rating-comment:focus { border-color: var(--primary); }
.btn-rate { padding: 8px 20px; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-size: .875rem; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn-rate:hover { background: var(--primary-dark); }
.rating-done { color: var(--text-muted); font-size: .875rem; }
.offline-banner { background: #fef3c7; color: #92400e; font-size: .78rem; padding: 6px 14px; text-align: center; border-bottom: 1px solid #fde68a; flex-shrink: 0; }
.closed-banner { background: #f1f5f9; color: var(--text-muted); font-size: .82rem; padding: 10px 14px; text-align: center; border-top: 1px solid var(--border); flex-shrink: 0; }
.powered-by { text-align: center; font-size: .68rem; color: var(--text-muted); padding: 6px 0 4px; flex-shrink: 0; opacity: .6; }
.msg-buttons { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.msg-btn { padding: 7px 14px; background: var(--bg); border: 1.5px solid var(--primary); color: var(--primary); border-radius: 20px; font-size: .84rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .15s, color .15s; white-space: nowrap; }
.msg-btn:hover { background: var(--primary); color: #fff; }
.msg-btn:disabled, .msg-btn.used { opacity: .45; cursor: default; border-color: var(--border); color: var(--text-muted); background: var(--bg); }
@media (max-width: 480px) {
    .chat-window { width: 100%; max-width: 100%; height: 100%; max-height: 100%; bottom: 0; right: 0; left: 0 !important; border-radius: 0; }
    .chat-launcher { bottom: 16px; right: 16px; }
}`;

    // ── State ─────────────────────────────────────────────────
    const state = {
        uid:         localStorage.getItem('_cw_uid') || '',
        convId:      localStorage.getItem('_cw_conv_id') ? parseInt(localStorage.getItem('_cw_conv_id')) : null,
        lastMsgId:   0,
        open:        false,
        settings:    {},
        departments: [],
        agentsOnline: false,
        typing:      false,
        typingTimer: null,
        pollTimer:   null,
        phase:       'prechat',  // prechat | chat | closed
        unread:      0,
        ratingGiven: false,
        notifSound:  null,
        browserNotifPermission: false,
    };

    // ── DOM References ────────────────────────────────────────
    let host, shadow, launcher, window_, badge, messagesArea, inputEl, typingEl, offlineBanner, closedBanner, ratingSection;

    // ── Init ──────────────────────────────────────────────────
    async function init() {
        console.log('[CW] init() start — uid:', state.uid, 'conv_id:', state.convId);
        // Fetch settings + resume session
        const res = await api('POST', 'widget/init.php', {
            uid:    state.uid,
            conv_id: state.convId,
        });

        console.log('[CW] init.php response:', res);

        if (res.uid) {
            state.uid = res.uid;
            localStorage.setItem('_cw_uid', res.uid);
        }

        state.settings    = res.settings    || {};
        state.departments = res.departments || [];
        state.agentsOnline = !!res.agents_online;

        if (res.conv_id) {
            state.convId = res.conv_id;
            localStorage.setItem('_cw_conv_id', res.conv_id);
        }

        console.log('[CW] state after init — uid:', state.uid, 'conv_id:', state.convId);

        // Build widget DOM
        mount();

        // Apply settings
        applyCssVars();
        applyPosition();

        // Always show chat — bot handles greeting/routing
        state.phase = 'chat';
        showChat();
        await loadAllMessages();
        startPolling();
        scrollBottom(true);

        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission().then(p => {
                state.browserNotifPermission = p === 'granted';
            });
        } else {
            state.browserNotifPermission = Notification.permission === 'granted';
        }
    }

    // ── Mount Shadow DOM ──────────────────────────────────────
    function mount() {
        host   = document.createElement('div');
        shadow = host.attachShadow({ mode: 'open' });

        // Inject CSS
        const style = document.createElement('style');
        style.textContent = WIDGET_CSS;
        shadow.appendChild(style);

        // Launcher
        launcher = el('button', { class: 'chat-launcher', 'aria-label': 'Open chat' });
        launcher.innerHTML = `
            <svg class="icon-chat" viewBox="0 0 24 24" fill="currentColor">
                <path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
            </svg>
            <svg class="icon-close" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            <span class="badge" data-count="0"></span>
        `;
        launcher.addEventListener('click', toggleWidget);
        shadow.appendChild(launcher);

        // Chat window
        window_ = el('div', { class: 'chat-window' });
        window_.innerHTML = buildWindowHTML();
        shadow.appendChild(window_);

        // Cache refs
        badge         = shadow.querySelector('.badge');
        messagesArea  = shadow.querySelector('.messages-area');
        typingEl      = shadow.querySelector('.typing-indicator');
        offlineBanner = shadow.querySelector('.offline-banner');
        closedBanner  = shadow.querySelector('.closed-banner');
        ratingSection = shadow.querySelector('.rating-section');
        inputEl       = shadow.querySelector('.msg-input');

        // Events
        shadow.querySelector('.chat-header-close').addEventListener('click', toggleWidget);
        shadow.querySelector('.btn-send').addEventListener('click', sendMessage);

        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
            onTyping();
        });

        inputEl.addEventListener('input', autoResize);
        shadow.querySelector('.btn-attach').addEventListener('click', triggerUpload);
        shadow.querySelector('#file-input').addEventListener('change', handleFileUpload);

        // Stars
        shadow.querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', () => selectStar(parseInt(star.dataset.value)));
        });
        shadow.querySelector('.btn-rate')?.addEventListener('click', submitRating);

        document.body.appendChild(host);
    }

    function buildWindowHTML() {
        const name    = esc((BRAND && BRAND.name) || state.settings.name || 'Support Chat');
        const logo    = (BRAND && BRAND.logo) ? BRAND.logo : (state.settings.avatar || '');

        const avatarHtml = logo
            ? `<img src="${esc(logo)}" alt="${name}" style="width:100%;height:100%;object-fit:contain;padding:4px">`
            : `<svg width="26" height="26" viewBox="0 0 24 24" fill="white"><path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>`;

        return `
        <div class="chat-header">
            <div class="chat-header-avatar" id="header-avatar" style="background:rgba(255,255,255,.15)">
                ${avatarHtml}
            </div>
            <div class="chat-header-info">
                <div class="chat-header-name">${name}</div>
                <div class="chat-header-status">
                    <span class="status-dot" id="status-dot"></span>
                    <span id="status-text">Online</span>
                </div>
            </div>
            <button class="chat-header-close" aria-label="Close chat">
                <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>

        <!-- Offline banner -->
        <div class="offline-banner" style="display:none">
            ⚠️ We're currently offline — leave a message and we'll reply soon.
        </div>


        <!-- Messages -->
        <div class="messages-area"></div>

        <!-- Typing -->
        <div class="typing-indicator" style="display:none;margin:4px 16px 4px">
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
        </div>

        <!-- Closed banner -->
        <div class="closed-banner" style="display:none">This conversation has been closed.</div>

        <!-- Rating -->
        <div class="rating-section" style="display:none">
            <div class="rating-title">How would you rate this chat?</div>
            <div class="stars">
                ${[1,2,3,4,5].map(i => `<span class="star" data-value="${i}">★</span>`).join('')}
            </div>
            <textarea class="rating-comment" placeholder="Optional comment..." rows="2"></textarea>
            <button class="btn-rate">Submit Rating</button>
        </div>

        <!-- Input -->
        <div class="input-area">
            <button class="input-btn btn-attach" aria-label="Attach file">
                <svg viewBox="0 0 24 24"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
            </button>
            <textarea class="msg-input" placeholder="Type a message…" rows="1" aria-label="Message"></textarea>
            <button class="input-btn btn-send" aria-label="Send">
                <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            </button>
            <input type="file" id="file-input" style="display:none" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
        </div>

        <!-- Powered-by -->
        <div class="powered-by">Powered by RCG Live Chat</div>
        `;
    }

    // ── Show / Hide Phases ────────────────────────────────────
    function showChat() {
        shadow.querySelector('.messages-area').style.display = '';
        shadow.querySelector('.input-area').style.display = '';
        updateStatusBanner();
    }

    function updateStatusBanner() {
        if (state.agentsOnline) {
            if (offlineBanner) offlineBanner.style.display = 'none';
            setStatusDot('online');
        } else {
            if (offlineBanner) offlineBanner.style.display = '';
            setStatusDot('offline');
        }
    }

    function setStatusDot(s) {
        const dot  = shadow.querySelector('#status-dot');
        const text = shadow.querySelector('#status-text');
        if (!dot) return;
        dot.className = 'status-dot ' + s;
        if (text) text.textContent = s === 'online' ? 'Online' : s === 'away' ? 'Away' : 'Offline';
    }

    // ── Toggle Widget ─────────────────────────────────────────
    function toggleWidget() {
        state.open = !state.open;
        window_.classList.toggle('open', state.open);
        launcher.classList.toggle('open', state.open);

        if (state.open) {
            state.unread = 0;
            updateBadge();
            if (state.phase === 'chat') {
                setTimeout(() => scrollBottom(), 50);
                inputEl?.focus();
            }
        }
    }

    // ── Load all messages (on resume) ─────────────────────────
    async function loadAllMessages() {
        if (!state.convId) { console.log('[CW] loadAllMessages: no convId, skipping'); return; }
        console.log('[CW] loadAllMessages — conv_id:', state.convId, 'uid:', state.uid);
        const res = await api('GET', `widget/messages.php?conv_id=${state.convId}&uid=${state.uid}&last_id=0`);
        console.log('[CW] loadAllMessages response:', res);
        if (res.messages) {
            res.messages.forEach(renderMessage);
            state.lastMsgId = res.messages.length ? parseInt(res.messages[res.messages.length - 1].id) : 0;
        }
        console.log('[CW] loadAllMessages done — lastMsgId:', state.lastMsgId, 'count:', res.messages?.length ?? 0);
        if (res.conv_status === 'closed') showClosed(res.can_rate);
        scrollBottom(true);
    }

    // ── Polling ───────────────────────────────────────────────
    function startPolling() {
        if (state.pollTimer) return;
        state.pollTimer = setInterval(poll, POLL_INTERVAL);
    }

    async function poll() {
        if (!state.convId) return;
        try {
            const res = await api('GET', `widget/messages.php?conv_id=${state.convId}&uid=${state.uid}&last_id=${state.lastMsgId}`);

            if (res.success === false) {
                console.warn('[CW] poll error response:', res);
            }

            if (res.messages?.length) {
                console.log('[CW] poll got', res.messages.length, 'new messages, last_id was:', state.lastMsgId);
                res.messages.forEach(msg => {
                    console.log('[CW] rendering msg:', msg.id, msg.sender_type, msg.type, msg.content?.substring?.(0, 60));
                    renderMessage(msg);
                    if (!state.open && msg.sender_type !== 'visitor') {
                        state.unread++;
                        updateBadge();
                        playSound();
                        showBrowserNotif(msg);
                    }
                });
                state.lastMsgId = parseInt(res.messages[res.messages.length - 1].id);
                scrollBottom(true);
            }

            // Typing indicator
            if (typingEl) typingEl.style.display = res.agent_typing ? '' : 'none';

            // Conversation closed
            if (res.conv_status === 'closed' && state.phase !== 'closed') {
                showClosed(res.can_rate);
            }

            // Agent reopened the conversation — reset closed state
            if (res.conv_status === 'open' && state.phase === 'closed') {
                state.phase = 'chat';
                state.ratingGiven = false;
                if (closedBanner) closedBanner.style.display = 'none';
                if (ratingSection) ratingSection.style.display = 'none';
            }

            // Heartbeat
            api('POST', 'widget/heartbeat.php', { uid: state.uid, conv_id: state.convId });

        } catch (e) { console.error('[CW] poll exception:', e); }
    }

    // ── Render Message ────────────────────────────────────────
    function renderMessage(msg) {
        if (shadow.querySelector(`[data-msg-id="${msg.id}"]`)) return;

        const row = document.createElement('div');
        row.className = `msg-row ${msg.sender_type}`;
        row.dataset.msgId = msg.id;

        let content = '';
        let buttonsHtml = '';

        if (msg.type === 'buttons') {
            try {
                const parsed = JSON.parse(msg.content || '{}');
                content = esc(parsed.text || '').replace(/\n/g, '<br>');
                if (Array.isArray(parsed.buttons) && parsed.buttons.length) {
                    const btns = parsed.buttons.map(b =>
                        `<button class="msg-btn" data-id="${esc(b.id)}" data-title="${esc(b.title)}">${esc(b.title)}</button>`
                    ).join('');
                    buttonsHtml = `<div class="msg-buttons">${btns}</div>`;
                }
            } catch (_) {
                content = esc(msg.content || '');
            }
        } else if (msg.type === 'image' && msg.file_url) {
            content = `<img class="msg-image" src="${esc(msg.file_url)}" alt="Image" loading="lazy">`;
        } else if (msg.type === 'file' && msg.file_url) {
            content = `<a class="msg-file-link" href="${esc(msg.file_url)}" target="_blank" rel="noopener">
                <span class="msg-file-icon">📎</span>${esc(msg.file_name || 'File')}
            </a>`;
        } else {
            content = esc(msg.content || '').replace(/\n/g, '<br>');
        }

        const time = formatTime(msg.created_at);

        if (msg.sender_type === 'system') {
            row.innerHTML = `<div class="msg-bubble">${content}</div>`;
        } else {
            const sender = msg.sender_name ? `<div class="msg-sender">${esc(msg.sender_name)}</div>` : '';
            row.innerHTML = `
                ${msg.sender_type !== 'visitor' ? sender : ''}
                <div class="msg-bubble">${content}${buttonsHtml}</div>
                <div class="msg-time">${time}</div>
            `;
        }

        // Wire up button clicks
        if (buttonsHtml) {
            row.querySelectorAll('.msg-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    // Disable all buttons in this message
                    row.querySelectorAll('.msg-btn').forEach(b => {
                        b.disabled = true;
                        b.classList.add('used');
                    });
                    sendButtonReply(btn.dataset.id, btn.dataset.title);
                });
            });
        }

        messagesArea.appendChild(row);
    }

    // ── Send Message ──────────────────────────────────────────
    async function sendMessage() {
        const text = inputEl?.value.trim();
        if (!text || !state.convId) return;

        inputEl.value = '';
        autoResize();

        const fakeId = 'tmp_' + Date.now();
        const fakeMsg = { id: fakeId, sender_type: 'visitor', content: text, type: 'text', created_at: new Date().toISOString() };
        renderMessage(fakeMsg);
        scrollBottom(true);

        const result = await api('POST', 'widget/send.php', {
            conv_id: state.convId,
            uid: state.uid,
            content: text,
            type: 'text',
        });

        console.log('[CW] send.php response:', result);

        // Replace the temp ID with the real server ID so the next poll
        // won't render the same message again as a duplicate
        if (result.message_id) {
            const tmpEl = shadow.querySelector('[data-msg-id="' + fakeId + '"]');
            if (tmpEl) tmpEl.dataset.msgId = result.message_id;
            if (result.message_id > state.lastMsgId) {
                state.lastMsgId = result.message_id;
            }
            console.log('[CW] lastMsgId updated to:', state.lastMsgId);
            // If visitor sent a message on a closed conversation it is now
            // reopened — hide the closed banner (poll will confirm open status)
            if (state.phase === 'closed') {
                state.phase = 'chat';
                if (closedBanner) closedBanner.style.display = 'none';
                if (ratingSection) ratingSection.style.display = 'none';
                startPolling(); // Safety net — idempotent if already running
            }
        }
    }

    // ── Send Button Reply ─────────────────────────────────────
    async function sendButtonReply(buttonId, buttonTitle) {
        if (!state.convId) return;

        const fakeId  = 'tmp_' + Date.now();
        const fakeMsg = { id: fakeId, sender_type: 'visitor', content: buttonTitle, type: 'text', created_at: new Date().toISOString() };
        renderMessage(fakeMsg);
        scrollBottom(true);

        const result = await api('POST', 'widget/send.php', {
            conv_id:        state.convId,
            uid:            state.uid,
            content:        buttonTitle,
            type:           'text',
            interactive_id: buttonId,
        });

        console.log('[CW] sendButtonReply send.php response:', result);

        if (result.message_id) {
            const tmpEl = shadow.querySelector('[data-msg-id="' + fakeId + '"]');
            if (tmpEl) tmpEl.dataset.msgId = result.message_id;
            if (result.message_id > state.lastMsgId) {
                state.lastMsgId = result.message_id;
            }
            console.log('[CW] lastMsgId updated to:', state.lastMsgId);
        }
    }

    // ── Typing ────────────────────────────────────────────────
    function onTyping() {
        if (!state.convId) return;
        clearTimeout(state.typingTimer);
        api('POST', 'widget/typing.php', { conv_id: state.convId, uid: state.uid });
        state.typingTimer = setTimeout(() => {}, 3000);
    }

    // ── File Upload ───────────────────────────────────────────
    function triggerUpload() {
        shadow.querySelector('#file-input')?.click();
    }

    async function handleFileUpload(e) {
        const file = e.target.files?.[0];
        if (!file || !state.convId) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('conv_id', state.convId);
        formData.append('uid', state.uid);

        try {
            const res = await fetch(`${API_BASE}/api/widget/upload.php`, {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();
            if (data.success) {
                const msg = { id: data.message_id, sender_type: 'visitor', content: file.name, type: data.type, file_url: data.file_url, file_name: data.file_name, created_at: new Date().toISOString() };
                renderMessage(msg);
                scrollBottom();
            }
        } catch (e) {}

        e.target.value = '';
    }

    // ── Closed / Rating ──────────────────────────────────────
    function showClosed(canRate) {
        state.phase = 'closed';
        // Keep polling running — if the agent sends a new message it will
        // reopen the conversation and the visitor should receive it.

        if (closedBanner) closedBanner.style.display = '';

        if (canRate && !state.ratingGiven && ratingSection) {
            ratingSection.style.display = '';
        }
    }

    let selectedRating = 0;
    function selectStar(val) {
        selectedRating = val;
        shadow.querySelectorAll('.star').forEach((s, i) => {
            s.classList.toggle('active', i < val);
        });
    }

    async function submitRating() {
        if (!selectedRating || !state.convId) return;
        const comment = shadow.querySelector('.rating-comment')?.value.trim() || '';

        await api('POST', 'widget/rate.php', {
            conv_id: state.convId,
            uid: state.uid,
            rating: selectedRating,
            comment,
        });

        state.ratingGiven = true;
        if (ratingSection) {
            ratingSection.innerHTML = '<div class="rating-done">⭐ Thank you for your feedback!</div>';
        }
    }

    // ── Utilities ─────────────────────────────────────────────
    function applyCssVars() {
        const color = (BRAND && BRAND.color) || CONFIG.color || state.settings.color || '#C0392B';
        shadow.host.style.setProperty('--primary', color);
        shadow.host.style.setProperty('--bubble-visitor', color);
    }

    function applyPosition() {
        const pos = state.settings.position || 'bottom-right';
        if (pos === 'bottom-left') {
            host.classList.add('pos-bottom-left');
            launcher.style.right = 'auto';
            launcher.style.left = '24px';
            window_.style.right = 'auto';
            window_.style.left = '24px';
        }
    }

    function updateBadge() {
        if (!badge) return;
        badge.textContent = state.unread > 0 ? state.unread : '';
        badge.dataset.count = state.unread;
    }

    function scrollBottom(instant) {
        if (!messagesArea) return;
        messagesArea.scrollTo({ top: messagesArea.scrollHeight, behavior: instant ? 'instant' : 'smooth' });
    }

    function autoResize() {
        if (!inputEl) return;
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 100) + 'px';
    }

    function formatTime(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function el(tag, attrs) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
        return e;
    }

    function playSound() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain); gain.connect(ctx.destination);
            osc.frequency.value = 880; gain.gain.value = 0.08;
            osc.start(); osc.stop(ctx.currentTime + 0.12);
        } catch (_) {}
    }

    function showBrowserNotif(msg) {
        if (!state.browserNotifPermission) return;
        const name = state.settings.name || 'Support Chat';
        new Notification(name, {
            body: msg.content || 'New message',
            icon: state.settings.avatar || '',
        });
    }

    // ── API Helper ────────────────────────────────────────────
    async function api(method, endpoint, body) {
        const url = `${API_BASE}/api/${endpoint}`;
        const opts = {
            method,
            headers: {},
        };
        if (body && method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        try {
            const res  = await fetch(url, opts);
            return await res.json();
        } catch (e) {
            return {};
        }
    }

    // ── Start ─────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
