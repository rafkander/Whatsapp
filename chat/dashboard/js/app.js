/**
 * Agent Dashboard — Vue 3 SPA
 * Communicates with PHP REST API via fetch + JWT
 */
const { createApp, ref, reactive, computed, watch, nextTick, onMounted, onUnmounted } = Vue;

// ── Detect API base URL ────────────────────────────────────────
const API_BASE = (() => {
    const loc = window.location;
    // If serving from /chat/dashboard/, API is at /chat/api/
    const parts = loc.pathname.split('/');
    // Remove trailing empty + 'dashboard' segment
    while (parts.length && (parts[parts.length - 1] === '' || parts[parts.length - 1] === 'dashboard')) {
        parts.pop();
    }
    return loc.origin + parts.join('/');
})();

// ── Permissions catalogue ──────────────────────────────────
const ALL_PERMISSIONS = [
    { key: 'view_all_conversations', label: 'View All Conversations', group: 'Conversations',  desc: 'See all conversations, not just assigned ones' },
    { key: 'reply_conversations',    label: 'Reply to Conversations', group: 'Conversations',  desc: 'Send messages to visitors' },
    { key: 'take_conversations',     label: 'Take Conversations',     group: 'Conversations',  desc: 'Assign conversations to themselves' },
    { key: 'assign_conversations',   label: 'Assign Conversations',   group: 'Conversations',  desc: 'Reassign conversations to other agents or departments' },
    { key: 'close_conversations',    label: 'Close / Reopen',         group: 'Conversations',  desc: 'Close and reopen conversations' },
    { key: 'view_analytics',         label: 'View Analytics',         group: 'Reporting',      desc: 'Access the analytics dashboard' },
    { key: 'manage_canned',          label: 'Manage Canned Responses',group: 'Content',        desc: 'Create, edit and delete canned responses' },
    { key: 'manage_agents',          label: 'Manage Agents',          group: 'Administration', desc: 'Invite, edit and remove team members' },
    { key: 'manage_departments',     label: 'Manage Departments',     group: 'Administration', desc: 'Create and configure departments' },
    { key: 'manage_roles',           label: 'Manage Roles',           group: 'Administration', desc: 'Create and configure roles and permissions' },
    { key: 'manage_settings',        label: 'Manage Settings',        group: 'Administration', desc: 'Access system settings and integrations' },
];

createApp({
    setup() {
        // ── Auth ─────────────────────────────────────────────
        const auth = reactive({
            token: localStorage.getItem('_dash_token') || '',
            agent: JSON.parse(localStorage.getItem('_dash_agent') || 'null') || {},
        });

        const loginForm    = reactive({ email: '', password: '' });
        const loginLoading = ref(false);
        const loginError   = ref('');

        async function login() {
            loginLoading.value = true;
            loginError.value   = '';
            const res = await api('POST', 'agent/login.php', loginForm);
            if (res.success) {
                auth.token = res.token;
                auth.agent = res.agent;
                localStorage.setItem('_dash_token', res.token);
                localStorage.setItem('_dash_agent', JSON.stringify(res.agent));
                init();
            } else {
                loginError.value = res.error || 'Login failed';
            }
            loginLoading.value = false;
        }

        async function logout() {
            await api('POST', 'agent/logout.php');
            auth.token = '';
            auth.agent = {};
            localStorage.removeItem('_dash_token');
            localStorage.removeItem('_dash_agent');
            localStorage.removeItem('_dash_lcid');
            lastConvId.value = 0;
            stopPolling();
        }

        // ── View ─────────────────────────────────────────────
        const view = ref('conversations');

        // ── Toasts ───────────────────────────────────────────
        const toasts = ref([]);
        function toast(msg, type = 'info') {
            const id = Date.now();
            toasts.value.push({ id, msg, type });
            setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id); }, 3500);
        }

        // ── Conversations ─────────────────────────────────────
        const conversations  = ref([]);
        const convFilter     = ref('open');
        const convSearch     = ref('');
        const activeConvId   = ref(null);
        const activeConv     = ref(null);
        const activeMessages = ref([]);
        const unreadTotal      = ref(0);
        const myOpenCount      = ref(0);
        const unassignedCount  = ref(0);
        const visitorTyping  = ref(false);
        const lastMsgId      = ref(0);
        // Persisted across refreshes so existing conversations don't re-notify
        const lastConvId     = ref(parseInt(localStorage.getItem('_dash_lcid') || '0'));
        let   prevMyUnread   = 0;
        const notifiedAssignIds = new Set();

        // ── Notification preferences ──────────────────────────
        const _savedPrefs = JSON.parse(localStorage.getItem('_notif_prefs') || '{}');
        const notifPrefs = reactive({
            sound:          _savedPrefs.sound          ?? true,
            browser:        _savedPrefs.browser        ?? true,
            toast_new_conv: _savedPrefs.toast_new_conv ?? true,
            toast_assigned: _savedPrefs.toast_assigned ?? true,
        });

        function saveNotifPrefs() {
            localStorage.setItem('_notif_prefs', JSON.stringify(notifPrefs));
            if (notifPrefs.browser && 'Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
            toast('Preferences saved', 'success');
        }

        function requestBrowserPermission() {
            if ('Notification' in window) {
                Notification.requestPermission().then(p => {
                    notifPermission.value = p;
                    if (p === 'granted') toast('Browser notifications enabled', 'success');
                    else toast('Browser notifications blocked', 'error');
                });
            }
        }

        const notifApiSupported = 'Notification' in window;
        const notifPermission   = ref(notifApiSupported ? Notification.permission : 'denied');

        const departments = ref([]);
        const activeDepts = computed(() => departments.value.filter(d => d.is_active == 1));
        const agents      = ref([]);
        const contactHistory = ref([]);

        // ── Bitrix24 sidebar state ────────────────────────────
        const bitrix24Data      = ref(null);
        const bitrix24Fields    = ref([]);
        const bitrix24Enabled   = ref(false);
        const bitrix24SyncedAt  = ref(null);
        const bitrix24Loading   = ref(false);

        // ── Contact edit state ────────────────────────────────
        const contactEditForm    = reactive({ name: '', email: '', phone: '' });
        const contactEditLoading = ref(false);
        const historyOpen        = ref(false);

        // ── Bitrix24 admin state ──────────────────────────────
        const bitrix24Settings       = reactive({ enabled: false, webhook_url: '', cache_ttl: 3600 });
        const bitrix24AvailableFields = ref([]);
        const bitrix24FieldConfig    = ref([]);
        const bitrix24FieldsLoading  = ref(false);

        let pollTimer     = null;
        let notifTimer    = null;
        let debounceTimer = null;
        let agentsTimer   = null;

        async function loadConversations() {
            const params = new URLSearchParams();
            if (['open','closed','pending'].includes(convFilter.value)) params.set('status', convFilter.value);
            if (convFilter.value === 'open') params.set('unassigned', '1');
            if (convFilter.value === 'mine')   { params.set('mine', '1'); params.set('status', 'open'); }
            if (convFilter.value === 'unread') params.set('unread', '1');
            if (convFilter.value === 'widget') params.set('channel', 'widget');
            if (convFilter.value === 'wa')     params.set('channel', 'whatsapp');
            if (convFilter.value === 'sms')    params.set('channel', 'sms');
            if (convSearch.value)              params.set('search', convSearch.value);

            const res = await api('GET', `agent/conversations.php?${params}`);
            if (res.success) {
                conversations.value = res.conversations;
                unreadTotal.value   = res.unread_count;
            }
        }

        const debouncedLoadConvs = () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(loadConversations, 350);
        };

        async function openConversation(conv) {
            activeConvId.value = conv.id;
            activeMessages.value = [];
            lastMsgId.value = 0;
            visitorTyping.value = false;
            contactHistory.value = [];
            historyOpen.value = false;

            // Fetch full conv details
            const res = await api('GET', `agent/conversation.php?id=${conv.id}`);
            if (res.success) {
                activeConv.value = res.conversation;
                convTags.value   = (res.conversation.tags || '').split(',').filter(Boolean);
            }

            // Load all messages
            await loadMessages(true);

            // Load contact info + history
            const cRes = await api('GET', `agent/contacts.php?conv_id=${conv.id}`);
            if (cRes.success) {
                contactHistory.value    = cRes.history || [];
                bitrix24Enabled.value   = !!cRes.bitrix24_enabled;
                bitrix24Data.value      = cRes.bitrix24 || null;
                bitrix24Fields.value    = cRes.bitrix24_fields || [];
                bitrix24SyncedAt.value  = cRes.bitrix24_synced_at || null;
                // Populate contact edit form (phone falls back to whatsapp_number)
                contactEditForm.name  = cRes.contact?.name  || '';
                contactEditForm.email = cRes.contact?.email || '';
                contactEditForm.phone = cRes.contact?.phone || cRes.contact?.whatsapp_number || '';

                // Auto-trigger Bitrix24 lookup if contact has a phone but no cached data
                const hasPhone = !!(cRes.contact?.phone || cRes.contact?.whatsapp_number || cRes.contact?.sms_number);
                if (cRes.bitrix24_enabled && hasPhone && !cRes.bitrix24) {
                    refreshBitrix24(true);
                }
            }

            // Load agents & depts for assign dropdowns
            if (!agents.value.length) await loadAgents();
            if (!departments.value.length) await loadDepartments();

            scrollToBottom();
        }

        async function openConversationById(id) {
            const conv = conversations.value.find(c => c.id === id) || { id };
            await openConversation(conv);
        }

        async function loadMessages(all = false) {
            if (!activeConvId.value) return;
            const since = all ? 0 : lastMsgId.value;
            const res   = await api('GET', `agent/messages.php?conv_id=${activeConvId.value}&last_id=${since}`);

            if (res.success && res.messages?.length) {
                if (all) {
                    activeMessages.value = res.messages;
                } else {
                    activeMessages.value.push(...res.messages);
                    notifyNewMsg(res.messages);
                }
                lastMsgId.value = parseInt(res.messages[res.messages.length - 1].id);

                // If new messages arrived on a conversation we think is closed,
                // refresh activeConv so the status and canReply update correctly
                if (activeConv.value?.status === 'closed') {
                    const convRes = await api('GET', `agent/conversation.php?id=${activeConvId.value}`);
                    if (convRes.success) activeConv.value = convRes.conversation;
                }
            }
            if (res.success) visitorTyping.value = !!res.visitor_typing;
        }

        function notifyNewMsg(msgs) {
            msgs.forEach(msg => {
                // Only notify for visitor messages — skip agent, system, bot, note
                if (msg.sender_type === 'visitor') {
                    if (notifPrefs.sound) playSound();
                    if (notifPrefs.browser && Notification.permission === 'granted') {
                        const name = activeConv.value?.contact_name || 'Visitor';
                        new Notification(`New message from ${name}`, { body: msg.content || 'New message' });
                    }
                }
            });
        }

        // ── Polling ───────────────────────────────────────────
        function isNearBottom() {
            const c = msgContainer.value;
            if (!c) return true;
            return c.scrollHeight - c.scrollTop - c.clientHeight < 120;
        }

        let smsInboxTimer = null;

        async function pollSmsInbox() {
            const res = await api('GET', 'agent/sms_inbox.php');
            if (res.success && res.new_messages > 0) {
                await loadConversations();
                if (view.value === 'sms') await loadSmsConversations();
            }
        }

        function startPolling() {
            if (pollTimer) return;
            smsInboxTimer = setInterval(pollSmsInbox, 15000);
            pollTimer = setInterval(async () => {
                const wasNearBottom = isNearBottom();
                const prevLen = activeMessages.value.length;
                await loadMessages();
                await loadConversations();
                if (view.value === 'sms') await loadSmsConversations();
                await checkNotifications();
                // Only auto-scroll if user was already near the bottom
                if (wasNearBottom || activeMessages.value.length !== prevLen) {
                    scrollToBottom();
                }
            }, 2000);
        }

        function stopPolling() {
            clearInterval(pollTimer);
            clearInterval(smsInboxTimer);
            pollTimer = null;
            smsInboxTimer = null;
        }

        async function checkNotifications(silent = false) {
            const res = await api('GET', `agent/notifications.php?last_conv_id=${lastConvId.value}`);
            if (!res.success) return;

            unreadTotal.value     = res.unread_total;
            myOpenCount.value     = res.my_open_count     ?? myOpenCount.value;
            unassignedCount.value = res.unassigned_count  ?? unassignedCount.value;

            // ── New conversations ─────────────────────────────
            if (res.new_conversations?.length) {
                const newMax = Math.max(...res.new_conversations.map(c => c.id));
                if (!silent && newMax > lastConvId.value) {
                    res.new_conversations.forEach(c => {
                        if (notifPrefs.toast_new_conv) toast(`New ${c.channel === 'whatsapp' ? '📱 WhatsApp' : c.channel === 'sms' ? '✉ SMS' : '💬 Chat'} from ${c.contact_name || 'Visitor'}`, 'info');
                        if (notifPrefs.sound) playSound();
                        if (notifPrefs.browser && Notification.permission === 'granted') {
                            new Notification('New Conversation', { body: `${c.contact_name || 'Visitor'} started a conversation` });
                        }
                    });
                }
                // Always update baseline (silent or not) so refresh doesn't re-fire
                lastConvId.value = newMax;
                localStorage.setItem('_dash_lcid', String(newMax));
            }

            // ── Conversations assigned to me (by someone else) ─
            if (!silent && res.assigned_to_me?.length) {
                res.assigned_to_me.forEach(c => {
                    if (!notifiedAssignIds.has(c.id)) {
                        notifiedAssignIds.add(c.id);
                        if (notifPrefs.toast_assigned) toast(`📋 Assigned to you: ${c.contact_name || 'Visitor'}`, 'info');
                        if (notifPrefs.sound) playSound();
                        if (notifPrefs.browser && Notification.permission === 'granted') {
                            new Notification('Conversation Assigned', { body: `${c.contact_name || 'Visitor'} has been assigned to you` });
                        }
                    }
                });
            }

            // ── New messages in my conversations (unread increase) ─
            if (!silent && res.my_unread > prevMyUnread && res.my_unread > 0) {
                // Sound already plays via notifyNewMsg for active conv; only play for background convs
                if (!activeConvId.value && notifPrefs.sound) playSound();
            }
            prevMyUnread = res.my_unread;
        }

        // ── Send Message ──────────────────────────────────────
        const inputText     = ref('');
        const inputMode     = ref('text'); // text | note
        const cannedSuggestions = ref([]);
        const cannedAll     = ref([]);
        const agentInput    = ref(null);
        const msgContainer  = ref(null);
        const fileInput     = ref(null);

        function toggleNote() {
            inputMode.value = inputMode.value === 'note' ? 'text' : 'note';
        }

        async function onAgentInput() {
            // Auto resize
            const el = agentInput.value;
            if (el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 120) + 'px'; }

            // Canned response search
            const val = inputText.value;
            if (val.startsWith('/') && val.length > 1) {
                const q = val.slice(1).toLowerCase();
                cannedSuggestions.value = cannedAll.value.filter(c =>
                    c.shortcut.toLowerCase().startsWith(q) || c.title.toLowerCase().includes(q)
                ).slice(0, 6);
            } else {
                cannedSuggestions.value = [];
            }
        }

        function applyCanned(c) {
            inputText.value         = c.content;
            cannedSuggestions.value = [];
            agentInput.value?.focus();
        }

        let typingTimer = null;
        function onAgentTyping() {
            if (!activeConvId.value) return;
            clearTimeout(typingTimer);
            api('POST', 'agent/typing.php', { conv_id: activeConvId.value });
            typingTimer = setTimeout(() => {}, 3000);
        }

        async function sendAgentMessage() {
            const text = inputText.value.trim();
            if (!text || !activeConvId.value) return;

            const type = inputMode.value === 'note' ? 'note' : 'text';
            inputText.value         = '';
            cannedSuggestions.value = [];
            if (agentInput.value) agentInput.value.style.height = '40px';

            const res = await api('POST', 'agent/messages.php', {
                conv_id: activeConvId.value,
                content: text,
                type,
            });

            if (res.success) {
                await loadMessages();
                scrollToBottom();
                if (res.wa_warning) {
                    toast('⚠ ' + res.wa_warning, 'error');
                }
            }
        }

        function triggerFileUpload() { fileInput.value?.click(); }

        async function uploadFile(e) {
            const file = e.target.files?.[0];
            if (!file || !activeConvId.value) return;

            const fd = new FormData();
            fd.append('file', file);
            fd.append('conv_id', activeConvId.value);
            fd.append('uid', '_agent_' + auth.agent.id);

            try {
                const res = await fetch(`${API_BASE}/api/widget/upload.php`, {
                    method: 'POST',
                    headers: { Authorization: `Bearer ${auth.token}` },
                    body: fd,
                });
                const data = await res.json();
                if (data.success) {
                    await loadMessages();
                    scrollToBottom();
                    toast('File uploaded', 'success');
                }
            } catch (e) {
                toast('Upload failed', 'error');
            }
            e.target.value = '';
        }

        // ── Conversation Actions ──────────────────────────────
        async function closeConv() {
            if (!confirm('Close this conversation?')) return;
            const res = await api('PATCH', `agent/conversation.php?id=${activeConvId.value}`, { status: 'closed' });
            if (res.success) {
                activeConvId.value = null;
                activeConv.value = null;
                convFilter.value = 'open';
                await loadConversations();
                toast('Conversation closed', 'success');
            }
        }

        async function reopenConv() {
            const res = await api('PATCH', `agent/conversation.php?id=${activeConvId.value}`, { status: 'open' });
            if (res.success) {
                activeConv.value = res.conversation;
                await loadConversations();
                toast('Conversation reopened', 'success');
            }
        }

        // Assign
        const showAssignModal = ref(false);
        const assignForm      = reactive({ agent_id: '', dept_id: '' });

        async function submitAssign() {
            const payload = {};
            if (assignForm.agent_id !== '') payload.assigned_agent_id = assignForm.agent_id || null;
            if (assignForm.dept_id  !== '') payload.dept_id            = assignForm.dept_id  || null;

            const res = await api('PATCH', `agent/conversation.php?id=${activeConvId.value}`, payload);
            if (res.success) {
                activeConv.value  = res.conversation;
                showAssignModal.value = false;
                await loadMessages();
                await loadConversations();
                toast('Assigned', 'success');
            }
        }

        async function assignAgent(id) {
            await api('PATCH', `agent/conversation.php?id=${activeConvId.value}`, { assigned_agent_id: id || null });
            const res = await api('GET', `agent/conversation.php?id=${activeConvId.value}`);
            if (res.success) activeConv.value = res.conversation;
        }

        async function assignDept(id) {
            await api('PATCH', `agent/conversation.php?id=${activeConvId.value}`, { dept_id: id || null });
            const res = await api('GET', `agent/conversation.php?id=${activeConvId.value}`);
            if (res.success) activeConv.value = res.conversation;
        }

        // Tags
        const convTags = ref([]);
        const newTag   = ref('');

        async function addTag() {
            const tag = newTag.value.trim().replace(',', '');
            if (!tag || convTags.value.includes(tag)) { newTag.value = ''; return; }
            convTags.value.push(tag);
            newTag.value = '';
            await saveTags();
        }

        async function removeTag(tag) {
            convTags.value = convTags.value.filter(t => t !== tag);
            await saveTags();
        }

        async function saveTags() {
            await api('PATCH', `agent/conversation.php?id=${activeConvId.value}`, { tags: convTags.value });
        }

        // Status
        const statusMenuOpen = ref(false);

        async function setStatus(s) {
            const res = await api('POST', 'agent/status.php', { status: s });
            if (res.success) auth.agent.status = s;
        }

        // ── Canned Responses ──────────────────────────────────
        const cannedList       = ref([]);
        const showCannedModal  = ref(false);
        const editingCanned    = ref(null);
        const cannedForm       = reactive({});

        async function loadCanned() {
            const res = await api('GET', 'agent/canned.php');
            if (res.success) {
                cannedList.value = res.canned_responses;
                cannedAll.value  = res.canned_responses;
            }
        }

        function editCanned(c) {
            editingCanned.value  = c;
            Object.assign(cannedForm, c);
            showCannedModal.value = true;
        }

        async function saveCanned() {
            let res;
            if (editingCanned.value) {
                res = await api('PATCH', `agent/canned.php?id=${editingCanned.value.id}`, cannedForm);
            } else {
                res = await api('POST', 'agent/canned.php', cannedForm);
            }
            if (res.success) {
                showCannedModal.value = false;
                await loadCanned();
                toast('Saved', 'success');
            }
        }

        async function deleteCanned(id) {
            if (!confirm('Delete this canned response?')) return;
            await api('DELETE', `agent/canned.php?id=${id}`);
            await loadCanned();
            toast('Deleted', 'success');
        }

        // ── Agents (admin) ────────────────────────────────────
        const agentList       = ref([]);
        const showAgentModal  = ref(false);
        const editingAgent    = ref(null);
        const agentForm       = reactive({ name: '', email: '', password: '', role: 'agent' });

        async function loadAgents() {
            const res = await api('GET', 'admin/agents.php');
            if (res.success) {
                agentList.value = res.agents;
                agents.value    = res.agents;
            }
        }

        function editAgent(a) {
            editingAgent.value  = a;
            Object.assign(agentForm, { name: a.name, email: a.email, password: '', role: a.role });
            showAgentModal.value = true;
        }

        async function saveAgent() {
            const payload = { ...agentForm };
            if (!payload.password) delete payload.password;
            let res;
            if (editingAgent.value) {
                res = await api('PATCH', `admin/agents.php?id=${editingAgent.value.id}`, payload);
            } else {
                res = await api('POST', 'admin/agents.php', payload);
            }
            if (res.success) {
                showAgentModal.value = false;
                await loadAgents();
                toast('Saved', 'success');
            } else {
                toast(res.error || 'Error', 'error');
            }
        }

        async function deleteAgent(id) {
            if (!confirm('Delete this agent?')) return;
            await api('DELETE', `admin/agents.php?id=${id}`);
            await loadAgents();
            toast('Deleted', 'success');
        }

        // ── Departments (admin) ───────────────────────────────
        const showDeptModal  = ref(false);
        const editingDept    = ref(null);
        const deptForm       = reactive({ name: '', color: '#2563eb', description: '' });
        const selectedDept   = ref(null);   // dept open in right panel

        async function loadDepartments() {
            const res = await api('GET', 'admin/departments.php');
            if (res.success) {
                departments.value = res.departments;
                // Keep right-panel in sync after reload
                if (selectedDept.value) {
                    selectedDept.value = res.departments.find(d => d.id === selectedDept.value.id) || null;
                }
            }
        }

        function selectDept(d) {
            selectedDept.value = d;
            Object.assign(deptForm, { name: d.name, color: d.color, description: d.description || '' });
        }

        function newDept() {
            selectedDept.value = null;
            editingDept.value  = null;
            Object.assign(deptForm, { name: '', color: '#2563eb', description: '' });
            showDeptModal.value = true;
        }

        function editDept(d) {
            editingDept.value = d;
            Object.assign(deptForm, { name: d.name, color: d.color, description: d.description || '' });
            showDeptModal.value = true;
        }

        async function saveDept() {
            let res;
            if (editingDept.value) {
                res = await api('PATCH', `admin/departments.php?id=${editingDept.value.id}`, deptForm);
            } else {
                res = await api('POST', 'admin/departments.php', deptForm);
            }
            if (res.success) {
                showDeptModal.value = false;
                await loadDepartments();
                toast('Department saved', 'success');
            } else {
                toast(res.error || 'Failed to save department', 'error');
            }
        }

        // Save name/colour from right panel inline form
        async function saveDeptInline() {
            if (!selectedDept.value) return;
            const res = await api('PATCH', `admin/departments.php?id=${selectedDept.value.id}`, deptForm);
            if (res.success) {
                await loadDepartments();
                toast('Saved', 'success');
            } else {
                toast(res.error || 'Failed to save', 'error');
            }
        }

        async function deleteDept(id) {
            if (!confirm('Remove this department? Existing conversations will be unassigned.')) return;
            await api('DELETE', `admin/departments.php?id=${id}`);
            if (selectedDept.value?.id === id) selectedDept.value = null;
            await loadDepartments();
            toast('Removed', 'success');
        }

        function isDeptMember(agentId) {
            return !!(selectedDept.value?.members?.find(m => m.id === agentId));
        }

        async function toggleDeptMember(agentId, checked) {
            if (checked) {
                await api('POST', 'admin/dept_agents.php', { dept_id: selectedDept.value.id, agent_id: agentId });
            } else {
                await api('DELETE', `admin/dept_agents.php?dept_id=${selectedDept.value.id}&agent_id=${agentId}`);
            }
            await loadDepartments();
        }

        // ── Roles (admin) ────────────────────────────────────
        const rolesList      = ref([]);
        const showRoleModal  = ref(false);
        const editingRole    = ref(null);
        const selectedRole   = ref(null);
        const roleForm       = reactive({ name: '', description: '', color: '#2563eb', permissions: [] });

        const permissionGroups = computed(() => {
            const groups = {};
            for (const p of ALL_PERMISSIONS) {
                if (!groups[p.group]) groups[p.group] = [];
                groups[p.group].push(p);
            }
            return groups;
        });

        async function loadRoles() {
            const res = await api('GET', 'admin/roles.php');
            if (res.success) {
                rolesList.value = res.roles;
                if (selectedRole.value) {
                    selectedRole.value = res.roles.find(r => r.id === selectedRole.value.id) || null;
                    if (selectedRole.value) {
                        Object.assign(roleForm, {
                            name: selectedRole.value.name,
                            description: selectedRole.value.description || '',
                            color: selectedRole.value.color,
                            permissions: [...(selectedRole.value.permissions || [])],
                        });
                    }
                }
            }
        }

        function selectRole(r) {
            selectedRole.value = r;
            Object.assign(roleForm, {
                name: r.name,
                description: r.description || '',
                color: r.color,
                permissions: [...(r.permissions || [])],
            });
        }

        function newRole() {
            selectedRole.value = null;
            editingRole.value  = null;
            Object.assign(roleForm, { name: '', description: '', color: '#2563eb', permissions: [] });
            showRoleModal.value = true;
        }

        function editRole(r) {
            editingRole.value = r;
            Object.assign(roleForm, {
                name: r.name,
                description: r.description || '',
                color: r.color,
                permissions: [...(r.permissions || [])],
            });
            showRoleModal.value = true;
        }

        async function saveRole() {
            let res;
            if (editingRole.value) {
                res = await api('PATCH', `admin/roles.php?id=${editingRole.value.id}`, roleForm);
            } else {
                res = await api('POST', 'admin/roles.php', roleForm);
            }
            if (res.success) {
                showRoleModal.value = false;
                await loadRoles();
                toast('Role saved', 'success');
            } else {
                toast(res.error || 'Failed to save role', 'error');
            }
        }

        async function saveRoleInline() {
            if (!selectedRole.value) return;
            const res = await api('PATCH', `admin/roles.php?id=${selectedRole.value.id}`, roleForm);
            if (res.success) {
                await loadRoles();
                toast('Saved', 'success');
            } else {
                toast(res.error || 'Failed to save', 'error');
            }
        }

        async function deleteRole(id) {
            if (!confirm('Delete this role? Agents currently assigned this role will not be changed.')) return;
            const res = await api('DELETE', `admin/roles.php?id=${id}`);
            if (res.success) {
                if (selectedRole.value?.id === id) selectedRole.value = null;
                await loadRoles();
                toast('Removed', 'success');
            } else {
                toast(res.error || 'Cannot delete', 'error');
            }
        }

        function isPermEnabled(key) {
            return roleForm.permissions.includes(key);
        }

        function toggleRolePerm(key, checked) {
            if (checked) {
                if (!roleForm.permissions.includes(key)) roleForm.permissions.push(key);
            } else {
                roleForm.permissions = roleForm.permissions.filter(p => p !== key);
            }
        }

        // ── Settings (admin) ──────────────────────────────────
        const settings = reactive({});

        async function loadSettings() {
            const res = await api('GET', 'admin/settings.php');
            if (res.success) Object.assign(settings, res.settings);
        }

        // ── Embed code ────────────────────────────────────────
        const embedCopied = ref(false);

        const embedCode = computed(() => {
            const color    = settings.widget_color    || '#2563eb';
            const position = settings.widget_position || 'bottom-right';
            return `<script src="${API_BASE}/widget/loader.js"\n        data-color="${color}"\n        data-position="${position}">\n<\/script>`;
        });

        function copyEmbed() {
            const text = embedCode.value;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    embedCopied.value = true;
                    setTimeout(() => { embedCopied.value = false; }, 2000);
                });
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                embedCopied.value = true;
                setTimeout(() => { embedCopied.value = false; }, 2000);
            }
        }

        async function saveSettings() {
            const res = await api('POST', 'admin/settings.php', { ...settings });
            if (res.success) {
                toast('Settings saved', 'success');
            } else {
                toast(res.error || 'Failed to save', 'error');
            }
        }

        // ── WhatsApp Accounts (admin) ─────────────────────────
        const waAccounts        = ref([]);
        const showWaAccountModal = ref(false);
        const editingWaAccount  = ref(null);
        const waAccountForm     = reactive({ name: '', phone_number_id: '', access_token: '', verify_token: '', bot_flow: 'standard', is_enabled: 1 });

        async function loadWaAccounts() {
            const res = await api('GET', 'admin/wa_accounts.php');
            if (res.success) waAccounts.value = res.accounts;
        }

        function newWaAccount() {
            editingWaAccount.value = null;
            Object.assign(waAccountForm, { name: '', phone_number_id: '', access_token: '', verify_token: '', bot_flow: 'standard', is_enabled: 1 });
            showWaAccountModal.value = true;
        }

        function editWaAccount(acc) {
            editingWaAccount.value = acc;
            Object.assign(waAccountForm, {
                name: acc.name,
                phone_number_id: acc.phone_number_id,
                access_token: '',         // don't pre-fill token
                verify_token: acc.verify_token,
                bot_flow: acc.bot_flow,
                is_enabled: acc.is_enabled,
            });
            showWaAccountModal.value = true;
        }

        async function saveWaAccount() {
            let res;
            if (editingWaAccount.value) {
                res = await api('PATCH', `admin/wa_accounts.php?id=${editingWaAccount.value.id}`, { ...waAccountForm });
            } else {
                res = await api('POST', 'admin/wa_accounts.php', { ...waAccountForm });
            }
            if (res.success) {
                showWaAccountModal.value = false;
                await loadWaAccounts();
                toast(editingWaAccount.value ? 'Account updated' : 'Number added', 'success');
            } else {
                toast(res.error || 'Failed to save', 'error');
            }
        }

        async function deleteWaAccount(id) {
            if (!confirm('Remove this WhatsApp number? Existing conversations will not be deleted.')) return;
            const res = await api('DELETE', `admin/wa_accounts.php?id=${id}`);
            if (res.success) {
                await loadWaAccounts();
                toast('Number removed', 'success');
            } else {
                toast(res.error || 'Failed to remove', 'error');
            }
        }

        // ── SMS View ──────────────────────────────────────────
        const smsConversations    = ref([]);
        const smsActiveConvId     = ref(null);
        const smsSearch           = ref('');

        const filteredSmsConversations = computed(() => {
            if (!smsSearch.value) return smsConversations.value;
            const q = smsSearch.value.toLowerCase();
            return smsConversations.value.filter(c =>
                (c.contact_name || '').toLowerCase().includes(q) ||
                (c.sms_number   || '').includes(q) ||
                (c.last_message || '').toLowerCase().includes(q)
            );
        });

        async function loadSmsConversations() {
            const res = await api('GET', 'agent/conversations.php?channel=sms&status=open');
            if (res.success) smsConversations.value = res.conversations;
        }

        async function openSmsConversation(conv) {
            smsActiveConvId.value = conv.id;
            await openConversation(conv);
        }

        function smsClearActiveAndCompose() {
            smsActiveConvId.value = null;
            activeConvId.value    = null;
            activeConv.value      = null;
            smsNewPhone.value     = '';
            smsNewName.value      = '';
            smsNewMsg.value       = '';
        }

        // ── SMS Accounts (admin) ──────────────────────────────
        const smsAccounts         = ref([]);
        const showSmsAccountModal = ref(false);
        const editingSmsAccount   = ref(null);
        const smsAccountForm      = reactive({ name: '', sender_id: '', is_enabled: 1 });

        async function loadSmsAccounts() {
            const res = await api('GET', 'admin/sms_accounts.php');
            if (res.success) smsAccounts.value = res.accounts;
        }

        function newSmsAccount() {
            editingSmsAccount.value = null;
            Object.assign(smsAccountForm, { name: '', sender_id: '', is_enabled: 1 });
            showSmsAccountModal.value = true;
        }

        function editSmsAccount(acc) {
            editingSmsAccount.value = acc;
            Object.assign(smsAccountForm, {
                name:      acc.name,
                sender_id: acc.sender_id,
                is_enabled: acc.is_enabled,
            });
            showSmsAccountModal.value = true;
        }

        async function saveSmsAccount() {
            let res;
            if (editingSmsAccount.value) {
                res = await api('PATCH', `admin/sms_accounts.php?id=${editingSmsAccount.value.id}`, { ...smsAccountForm });
            } else {
                res = await api('POST', 'admin/sms_accounts.php', { ...smsAccountForm });
            }
            if (res.success) {
                showSmsAccountModal.value = false;
                await loadSmsAccounts();
                toast(editingSmsAccount.value ? 'SMS account updated' : 'SMS account added', 'success');
            } else {
                toast(res.error || 'Failed to save', 'error');
            }
        }

        async function deleteSmsAccount(id) {
            if (!confirm('Remove this SMS account? Existing conversations will not be deleted.')) return;
            const res = await api('DELETE', `admin/sms_accounts.php?id=${id}`);
            if (res.success) {
                await loadSmsAccounts();
                toast('SMS account removed', 'success');
            } else {
                toast(res.error || 'Failed to remove', 'error');
            }
        }

        // ── New WhatsApp conversation ─────────────────────────
        const waNewModal      = ref(false);
        const waNewPhone      = ref('');
        const waNewMsg        = ref('');
        const waNewAccountId  = ref(null);

        async function startWaConv() {
            if (!waNewPhone.value.trim() || !waNewMsg.value.trim()) {
                toast('Phone and message are required', 'error');
                return;
            }
            const res = await api('POST', 'agent/wa_start.php', {
                phone:      waNewPhone.value.trim(),
                message:    waNewMsg.value.trim(),
                account_id: waNewAccountId.value || null,
            });
            if (res.success) {
                waNewModal.value = false;
                waNewPhone.value = '';
                waNewMsg.value   = '';
                await loadConversations();
                openConversationById(res.conversation_id);
                toast('WhatsApp conversation started', 'success');
            } else {
                toast(res.error || 'Failed to start conversation', 'error');
            }
        }

        // ── New SMS conversation ──────────────────────────────
        const smsNewModal      = ref(false);
        const smsNewPhone      = ref('');
        const smsNewName       = ref('');
        const smsNewMsg        = ref('');
        const smsNewAccountId  = ref(null);

        async function startSmsConv() {
            if (!smsNewPhone.value.trim() || !smsNewMsg.value.trim()) {
                toast('Phone and message are required', 'error');
                return;
            }
            const res = await api('POST', 'agent/sms_start.php', {
                phone:      smsNewPhone.value.trim(),
                message:    smsNewMsg.value.trim(),
                name:       smsNewName.value.trim() || undefined,
                account_id: smsNewAccountId.value || null,
            });
            if (res.success) {
                smsNewModal.value = false;
                smsNewPhone.value = '';
                smsNewName.value  = '';
                smsNewMsg.value   = '';
                await loadConversations();
                await loadSmsConversations();
                smsActiveConvId.value = res.conversation_id;
                openConversationById(res.conversation_id);
                if (res.sms_warning) toast('⚠ ' + res.sms_warning, 'error');
                else toast('SMS sent', 'success');
            } else {
                toast(res.error || 'Failed to start conversation', 'error');
            }
        }

        // ── Analytics (admin) ─────────────────────────────────
        const analytics    = ref(null);
        const analyticsFrom = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10));
        const analyticsTo   = ref(new Date().toISOString().slice(0, 10));

        // ── SMS Log ───────────────────────────────────────────
        const smsLogRows     = ref([]);
        const smsLogTotal    = ref(0);
        const smsLogPage     = ref(1);
        const smsLogLimit    = ref(50);
        const smsLogLoading  = ref(false);
        const smsLogSearch   = ref('');
        const smsLogAgent    = ref('');
        const smsLogAccount  = ref('');
        const smsLogFrom     = ref('');
        const smsLogTo       = ref('');
        const smsLogAgents   = ref([]);
        const smsLogAccounts = ref([]);

        async function loadSmsLog() {
            smsLogLoading.value = true;
            const params = new URLSearchParams({
                page:  smsLogPage.value,
                limit: smsLogLimit.value,
            });
            if (smsLogSearch.value)  params.set('search',  smsLogSearch.value);
            if (smsLogAgent.value)   params.set('agent',   smsLogAgent.value);
            if (smsLogAccount.value) params.set('account', smsLogAccount.value);
            if (smsLogFrom.value)    params.set('from',    smsLogFrom.value);
            if (smsLogTo.value)      params.set('to',      smsLogTo.value);
            const res = await api('GET', `admin/sms_log.php?${params}`);
            smsLogLoading.value = false;
            if (res.success) {
                smsLogRows.value     = res.rows;
                smsLogTotal.value    = res.total;
                if (res.agents)   smsLogAgents.value   = res.agents;
                if (res.accounts) smsLogAccounts.value = res.accounts;
            }
        }

        function formatDateTime(dt) {
            if (!dt) return '—';
            const d = new Date(dt);
            return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' })
                 + ' ' + d.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' });
        }

        async function loadAnalytics() {
            const res = await api('GET', `admin/analytics.php?from=${analyticsFrom.value}&to=${analyticsTo.value}`);
            if (res.success) analytics.value = res;
        }

        function barPct(val, total) {
            if (!total) return 0;
            return Math.round((val / total) * 100);
        }

        // ── Role / Access ─────────────────────────────────────
        // Covers both short names used in UI calls and full DB enum values
        const ROLE_RANK = {
            agent: 1, senior_agent: 2, senior: 2,
            supervisor: 3, admin: 4, super_admin: 5, super: 5,
        };

        function canAccess(minRole) {
            const role = auth.agent?.role || 'agent';
            return (ROLE_RANK[role] || 1) >= (ROLE_RANK[minRole] || 1);
        }

        // Must take the conversation before replying — no role bypass
        const canReply = computed(() => {
            if (!activeConv.value) return false;
            if (activeConv.value.status === 'closed') return false;
            return activeConv.value.assigned_agent_id == auth.agent.id;
        });

        async function takeConversation() {
            if (!activeConvId.value) return;
            const res = await api('PATCH', `agent/conversation.php?id=${activeConvId.value}`, {
                assigned_agent_id: auth.agent.id,
            });
            if (res.success) {
                activeConv.value = res.conversation;
                await loadMessages();
                convFilter.value = 'mine';
                await loadConversations();
                toast('Conversation taken', 'success');
            } else {
                toast('Failed to take conversation: ' + (res.error || 'Unknown error'), 'error');
            }
        }

        function rankLabel(role) {
            const map = { super: 'Super Admin', admin: 'Admin', supervisor: 'Supervisor', senior: 'Senior Agent', agent: 'Agent' };
            return map[role] || role || 'Agent';
        }

        function rankIcon(role) {
            const icons = { super: '🟣', admin: '🔴', supervisor: '🟠', senior: '🔵', agent: '🟢' };
            return icons[role] || '🟢';
        }

        function roleDescription(role) {
            const map = {
                agent:      'Can view and reply to assigned conversations',
                senior:     'Can view all conversations and use canned responses',
                supervisor: 'Can view analytics and manage conversations',
                admin:      'Full access except super-admin features',
                super:      'Unrestricted access to all features',
            };
            return map[role] || '';
        }

        function avatarColor(name) {
            if (!name) return '#6b7280';
            const palette = ['#2563eb','#7c3aed','#db2777','#dc2626','#ea580c','#ca8a04','#16a34a','#0891b2','#4f46e5','#be185d'];
            let hash = 0;
            for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
            return palette[Math.abs(hash) % palette.length];
        }

        // WhatsApp 24h window: expired if last visitor message > 24h ago
        const waWindowExpired = computed(() => {
            if (activeConv.value?.channel !== 'whatsapp') return false;
            const msgs = activeMessages.value;
            // Find the last visitor message
            const lastVisitor = [...msgs].reverse().find(m => m.sender_type === 'visitor');
            if (!lastVisitor) return true; // no visitor message yet
            const diff = (Date.now() - new Date(lastVisitor.created_at).getTime()) / 1000;
            return diff > 86400; // older than 24 hours
        });

        const fmtDuration = formatDuration;

        // Assignment select models (avoid DOM refs for cross-browser consistency)
        const selectedAgentId = ref('');
        const selectedDeptId  = ref('');
        watch(activeConv, () => {
            selectedAgentId.value = activeConv.value?.assigned_agent_id ?? '';
            selectedDeptId.value  = activeConv.value?.dept_id ?? '';
        }, { immediate: true });

        // ── Contact Details Update ────────────────────────────
        async function saveContactDetails() {
            if (!activeConv.value?.contact_id) return;
            contactEditLoading.value = true;
            const res = await api('PATCH', 'agent/contact_update.php', {
                contact_id: activeConv.value.contact_id,
                ...contactEditForm,
            });
            contactEditLoading.value = false;
            if (res.success) {
                if (res.bitrix24)       bitrix24Data.value     = res.bitrix24;
                if (res.bitrix24_synced_at) bitrix24SyncedAt.value = res.bitrix24_synced_at;
                toast('Contact details saved', 'success');
            } else {
                toast(res.error || 'Save failed', 'error');
            }
        }

        // ── Bitrix24 Functions ────────────────────────────────
        async function refreshBitrix24(silent = false) {
            if (!activeConvId.value) return;
            bitrix24Loading.value = true;
            const res = await api('POST', 'agent/bitrix_lookup.php', { conv_id: activeConvId.value });
            bitrix24Loading.value = false;
            if (res.success) {
                bitrix24Data.value     = res.bitrix24_data || null;
                bitrix24SyncedAt.value = res.synced_at || null;
                if (!silent) toast(res.found ? 'CRM record refreshed' : 'No CRM record found', res.found ? 'success' : 'info');
            } else {
                if (!silent) toast(res.error || 'Refresh failed', 'error');
            }
        }

        async function loadBitrix24Settings() {
            const res = await api('GET', 'admin/bitrix.php?action=credentials');
            if (res.success) {
                bitrix24Settings.enabled     = res.enabled;
                bitrix24Settings.webhook_url = res.webhook_url;
                bitrix24Settings.cache_ttl   = res.cache_ttl;
            }
            await loadBitrix24FieldConfig();
        }

        async function saveBitrix24Credentials() {
            const res = await api('POST', 'admin/bitrix.php?action=credentials', {
                enabled:     bitrix24Settings.enabled ? 1 : 0,
                webhook_url: bitrix24Settings.webhook_url,
                cache_ttl:   bitrix24Settings.cache_ttl,
            });
            if (res.success) toast('Bitrix24 credentials saved', 'success');
            else toast(res.error || 'Save failed', 'error');
        }

        async function loadBitrix24Fields() {
            bitrix24FieldsLoading.value = true;
            const res = await api('GET', 'admin/bitrix.php?action=fields');
            bitrix24FieldsLoading.value = false;
            if (res.success) {
                // Convert Bitrix24 field map into flat array, merge with existing config
                const existingMap = {};
                bitrix24FieldConfig.value.forEach(f => { existingMap[f.field_key] = f; });
                bitrix24AvailableFields.value = Object.entries(res.fields).map(([key, meta], i) => ({
                    field_key:  key,
                    label:      existingMap[key]?.label || (meta.listLabel || meta.title || key),
                    field_type: meta.type || 'string',
                    is_enabled: existingMap[key] ? !!existingMap[key].is_enabled : false,
                    sort_order: existingMap[key]?.sort_order ?? i,
                }));
            } else {
                toast(res.error || 'Failed to load fields', 'error');
            }
        }

        async function loadBitrix24FieldConfig() {
            const res = await api('GET', 'admin/bitrix.php?action=field_config');
            if (res.success) bitrix24FieldConfig.value = res.fields || [];
        }

        async function saveBitrix24FieldConfig() {
            const fields = (bitrix24AvailableFields.value.length ? bitrix24AvailableFields.value : bitrix24FieldConfig.value)
                .map((f, i) => ({ ...f, sort_order: i }));
            const res = await api('POST', 'admin/bitrix.php?action=field_config', { fields });
            if (res.success) {
                toast('Field configuration saved', 'success');
                await loadBitrix24FieldConfig();
            } else {
                toast(res.error || 'Save failed', 'error');
            }
        }

        // ── View Switching ────────────────────────────────────
        function switchView(v) {
            view.value = v;
            if (v === 'agents') {
                loadAgents();
                if (!agentsTimer) agentsTimer = setInterval(loadAgents, 30000);
            } else {
                clearInterval(agentsTimer);
                agentsTimer = null;
            }
            if (v === 'departments') { loadDepartments(); if (!agents.value.length) loadAgents(); }
            if (v === 'roles')       loadRoles();
            if (v === 'canned')      loadCanned();
            if (v === 'sms')         { loadSmsConversations(); if (!smsAccounts.value.length) loadSmsAccounts(); }
            if (v === 'settings')    { loadSettings(); loadWaAccounts(); loadSmsAccounts(); loadBitrix24Settings(); }
            if (v === 'smslog')      loadSmsLog();
            if (v === 'analytics')   loadAnalytics();
        }

        // ── Filter ────────────────────────────────────────────
        function setFilter(f) {
            convFilter.value = f;
            loadConversations();
        }

        // ── Helpers ───────────────────────────────────────────
        function scrollToBottom() {
            nextTick(() => {
                const c = msgContainer.value;
                if (c) c.scrollTop = c.scrollHeight + 9999;
            });
        }

        function initials(name) {
            if (!name) return '?';
            return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
        }

        function truncate(str, n) {
            if (!str) return '';
            // Extract text from bot button messages
            try {
                const p = JSON.parse(str);
                if (p && Array.isArray(p.buttons)) str = p.text || str;
            } catch (_) {}
            return str.length > n ? str.slice(0, n) + '…' : str;
        }

        function timeAgo(iso) {
            if (!iso) return '';
            const diff = (Date.now() - new Date(iso).getTime()) / 1000;
            if (diff < 60)   return 'now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h';
            return Math.floor(diff / 86400) + 'd';
        }

        function formatTime(iso) {
            if (!iso) return '';
            return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        function formatDate(iso) {
            if (!iso) return '';
            const d    = new Date(iso);
            const today = new Date();
            const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
            if (d.toDateString() === today.toDateString())     return 'Today';
            if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
            return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
        }

        function showDateSep(msg, prev) {
            if (!prev) return true;
            return new Date(msg.created_at).toDateString() !== new Date(prev.created_at).toDateString();
        }

        function nl2br(str) {
            if (!str) return '';
            // Render bot button messages as text + button labels
            try {
                const p = JSON.parse(str);
                if (p && Array.isArray(p.buttons)) {
                    const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    const btns = p.buttons.map(b => `<span style="display:inline-block;margin:2px 4px 0 0;padding:2px 10px;border:1px solid currentColor;border-radius:12px;font-size:.8rem;opacity:.75">${esc(b.title)}</span>`).join('');
                    return `${esc(p.text||'').replace(/\n/g,'<br>')}<br>${btns}`;
                }
            } catch (_) {}
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/\n/g, '<br>');
        }

        function formatDuration(secs) {
            if (!secs) return '—';
            if (secs < 60)   return secs + 's';
            if (secs < 3600) return Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
            return Math.floor(secs / 3600) + 'h ' + Math.floor((secs % 3600) / 60) + 'm';
        }

        let _audioCtx = null;

        // Unlock AudioContext on first user interaction so polling-triggered sounds work
        function _unlockAudio() {
            if (!_audioCtx) {
                _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (_audioCtx.state === 'suspended') _audioCtx.resume();
        }
        document.addEventListener('click',   _unlockAudio, { once: true });
        document.addEventListener('keydown',  _unlockAudio, { once: true });

        async function playSound() {
            try {
                if (!_audioCtx) {
                    _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (_audioCtx.state === 'suspended') {
                    await _audioCtx.resume();
                }
                const ctx = _audioCtx;
                const now = ctx.currentTime;

                // Two-tone notification chime
                [[660, 0, 0.18], [880, 0.16, 0.22]].forEach(([freq, start, end]) => {
                    const osc  = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0, now + start);
                    gain.gain.linearRampToValueAtTime(0.4, now + start + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + end);
                    osc.start(now + start);
                    osc.stop(now + end);
                });
            } catch (e) {
                console.warn('playSound failed:', e);
            }
        }

        // ── API ───────────────────────────────────────────────
        async function api(method, endpoint, body = null) {
            const url  = `${API_BASE}/api/${endpoint}`;
            const opts = { method, headers: {} };

            if (auth.token) opts.headers['Authorization'] = `Bearer ${auth.token}`;

            if (body && method !== 'GET') {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }

            try {
                const res  = await fetch(url, opts);
                if (res.status === 401) {
                    auth.token = '';
                    auth.agent = {};
                    localStorage.removeItem('_dash_token');
                    localStorage.removeItem('_dash_agent');
                    stopPolling();
                    return { success: false, error: 'Session expired' };
                }
                const text = await res.text();
                try {
                    return JSON.parse(text);
                } catch (_) {
                    console.error('Non-JSON response from', endpoint, ':', text.slice(0, 500));
                    return { success: false, error: 'Server error: ' + text.slice(0, 200) };
                }
            } catch (e) {
                return { success: false, error: 'Network error: ' + e.message };
            }
        }

        // ── Init ─────────────────────────────────────────────
        function init() {
            if (!auth.token) return;
            loadConversations();
            loadDepartments();
            loadAgents();
            loadCanned();
            loadWaAccounts();
            loadSmsAccounts();
            checkNotifications(true); // silent baseline — prevents re-alerting on refresh
            startPolling();

            // Browser notification permission
            if (notifApiSupported && Notification.permission === 'default') {
                Notification.requestPermission().then(p => { notifPermission.value = p; });
            }
        }

        onMounted(() => {
            if (auth.token) init();
        });

        onUnmounted(() => {
            stopPolling();
        });

        return {
            // Auth
            auth, loginForm, loginLoading, loginError, login, logout,
            // View
            view,
            // API base (for template use e.g. webhook URL display)
            apiBase: API_BASE,
            // Toasts
            toasts,
            // Conversations
            conversations, convFilter, convSearch, activeConvId, activeConv, activeMessages,
            unreadTotal, myOpenCount, unassignedCount, visitorTyping, departments, activeDepts, agents, contactHistory,
            loadConversations, debouncedLoadConvs, debouncedLoad: debouncedLoadConvs,
            openConversation, openConversationById,
            // Send
            inputText, inputMode, cannedSuggestions, agentInput, msgContainer, fileInput,
            toggleNote, onAgentInput, onAgentTyping, applyCanned, sendAgentMessage,
            triggerFileUpload, uploadFile,
            // Conv actions
            closeConv, reopenConv,
            showAssignModal, assignForm, submitAssign, assignAgent, assignDept, selectedAgentId, selectedDeptId,
            convTags, newTag, addTag, removeTag,
            statusMenuOpen, setStatus,
            // Canned
            cannedList, cannedAll, showCannedModal, editingCanned, cannedForm,
            loadCanned, editCanned, saveCanned, deleteCanned,
            // Admin
            agentList, showAgentModal, editingAgent, agentForm, loadAgents, editAgent, saveAgent, deleteAgent,
            showDeptModal, editingDept, deptForm, newDept, editDept, loadDepartments, saveDept, deleteDept,
            selectedDept, selectDept, saveDeptInline, isDeptMember, toggleDeptMember,
            rolesList, showRoleModal, editingRole, selectedRole, roleForm, permissionGroups,
            loadRoles, selectRole, newRole, editRole, saveRole, saveRoleInline, deleteRole,
            isPermEnabled, toggleRolePerm, ALL_PERMISSIONS,
            settings, loadSettings, saveSettings, embedCode, embedCopied, copyEmbed,
            analytics, analyticsFrom, analyticsTo, loadAnalytics, barPct,
            // WhatsApp accounts management
            waAccounts, showWaAccountModal, editingWaAccount, waAccountForm,
            loadWaAccounts, newWaAccount, editWaAccount, saveWaAccount, deleteWaAccount,
            // New WA conversation
            waNewModal, waNewPhone, waNewMsg, waNewAccountId, startWaConv,
            // SMS view
            smsConversations, smsActiveConvId, smsSearch, filteredSmsConversations,
            loadSmsConversations, openSmsConversation, smsClearActiveAndCompose,
            // SMS accounts management
            smsAccounts, showSmsAccountModal, editingSmsAccount, smsAccountForm,
            smsLogRows, smsLogTotal, smsLogPage, smsLogLimit, smsLogLoading,
            smsLogSearch, smsLogAgent, smsLogAccount, smsLogFrom, smsLogTo,
            smsLogAgents, smsLogAccounts, loadSmsLog, formatDateTime,
            loadSmsAccounts, newSmsAccount, editSmsAccount, saveSmsAccount, deleteSmsAccount,
            // New SMS conversation
            smsNewModal, smsNewPhone, smsNewName, smsNewMsg, smsNewAccountId, startSmsConv,
            waWindowExpired,
            // Contact edit
            contactEditForm, contactEditLoading, saveContactDetails, historyOpen,
            // Bitrix24
            bitrix24Data, bitrix24Fields, bitrix24Enabled, bitrix24SyncedAt, bitrix24Loading,
            bitrix24Settings, bitrix24AvailableFields, bitrix24FieldConfig, bitrix24FieldsLoading,
            refreshBitrix24, loadBitrix24Settings, saveBitrix24Credentials,
            loadBitrix24Fields, loadBitrix24FieldConfig, saveBitrix24FieldConfig,
            // Notification preferences
            notifPrefs, saveNotifPrefs, requestBrowserPermission, notifApiSupported, notifPermission, playSound,
            // Access / view / filter / take
            canAccess, canReply, takeConversation,
            rankLabel, rankIcon, roleDescription, switchView, setFilter,
            // Helpers
            avatarColor, fmtDuration,
            initials, truncate, timeAgo, formatTime, formatDate, showDateSep, nl2br, formatDuration,
        };
    }
}).mount('#app');
