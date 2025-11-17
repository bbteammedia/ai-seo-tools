<?php
/**
 * Chatbot Example front-end.
 */
$restBase = esc_url_raw(rest_url('chatbot/v1/'));
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('AI Chatbot Demo', 'ai-seo-tool'); ?></title>
    <?php wp_head(); ?>
    <style>
        body.chatbot-example {
            font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #f8fafc;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: stretch;
            justify-content: center;
            padding: 32px 16px;
        }
        .chatbot-shell {
            width: 100%;
            max-width: 1100px;
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
        }
        .chatbot-card {
            background: rgba(30, 41, 59, 0.6);
            border-radius: 18px;
            padding: 20px;
            border: 1px solid rgba(148, 163, 184, 0.15);
            margin-bottom: 16px;
        }
        .chatbot-card h2, .chatbot-card h3 {
            margin-top: 0;
            color: #f1f5f9;
        }
        label { display: block; font-size: 14px; margin-bottom: 6px; color: #cbd5f5; }
        input, textarea {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.5);
            color: #f8fafc;
            font-size: 15px;
        }
        input:focus, textarea:focus {
            outline: 2px solid #38bdf8;
        }
        button {
            border: none;
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: #0f172a;
        }
        .btn-secondary {
            background: transparent;
            color: #e2e8f0;
            border: 1px solid rgba(226, 232, 240, 0.3);
        }
        .sessions-list {
            max-height: 220px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .session-item {
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 14px;
            padding: 12px;
            cursor: pointer;
            transition: border-color 0.2s, transform 0.2s;
        }
        .session-item:hover {
            border-color: #38bdf8;
            transform: translateY(-1px);
        }
        .chat-panel {
            background: rgba(15, 23, 42, 0.5);
            border-radius: 24px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            display: flex;
            flex-direction: column;
            height: 720px;
        }
        .chat-history {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .bubble {
            max-width: 75%;
            padding: 14px 16px;
            border-radius: 18px;
            position: relative;
            line-height: 1.5;
            white-space: pre-wrap;
        }
        .bubble.user {
            align-self: flex-end;
            background: #38bdf8;
            color: #0f172a;
            border-bottom-right-radius: 4px;
        }
        .bubble.assistant {
            align-self: flex-start;
            background: rgba(148, 163, 184, 0.2);
            border-bottom-left-radius: 4px;
        }
        .chat-input {
            border-top: 1px solid rgba(148, 163, 184, 0.2);
            padding: 16px 24px;
            }
        .chat-input form {
            display: flex;
            gap: 12px;
        }
        .chat-input textarea {
            resize: none;
            height: 80px;
        }
        .chatbot-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
        }
        .chatbot-actions button {
            border-radius: 999px;
            border: 1px solid rgba(248, 250, 252, 0.4);
            background: transparent;
            color: #f8fafc;
            padding: 8px 14px;
            font-size: 14px;
        }
        .status-line {
            font-size: 14px;
            color: #fbbf24;
            min-height: 24px;
        }
        @media (max-width: 960px) {
            body.chatbot-example { padding: 16px; }
            .chatbot-shell {
                grid-template-columns: 1fr;
                height: auto;
            }
            .chat-panel { height: 600px; }
            .bubble { max-width: 100%; }
        }
    </style>
</head>
<body <?php body_class('chatbot-example'); ?>>
    <div class="chatbot-shell">
        <div>
            <div class="chatbot-card">
                <h2><?php esc_html_e('Start chatting', 'ai-seo-tool'); ?></h2>
                <p><?php esc_html_e('Enter your details so we can look up any previous sessions.', 'ai-seo-tool'); ?></p>
                <form id="chatbot-user-form">
                    <label for="chatbot-name"><?php esc_html_e('Name', 'ai-seo-tool'); ?></label>
                    <input type="text" id="chatbot-name" required placeholder="<?php esc_attr_e('Jane Doe', 'ai-seo-tool'); ?>" />
                    <label for="chatbot-email"><?php esc_html_e('Email', 'ai-seo-tool'); ?></label>
                    <input type="email" id="chatbot-email" required placeholder="you@example.com" />
                    <button class="btn-primary" type="submit" style="margin-top:16px;"><?php esc_html_e('Find conversations', 'ai-seo-tool'); ?></button>
                </form>
            </div>
            <div class="chatbot-card">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <h3><?php esc_html_e('Previous sessions', 'ai-seo-tool'); ?></h3>
                    <button id="chatbot-new-session" class="btn-secondary" type="button"><?php esc_html_e('Start new chat', 'ai-seo-tool'); ?></button>
                </div>
                <div class="sessions-list" id="chatbot-session-list">
                    <p style="color:#94a3b8;" id="chatbot-session-placeholder"><?php esc_html_e('No sessions yet. Fill the form above to begin.', 'ai-seo-tool'); ?></p>
                </div>
            </div>
        </div>
        <div class="chat-panel">
            <div class="chat-history" id="chatbot-history">
                <p style="color:#94a3b8;"><?php esc_html_e('Choose a session or start a new chat to see messages.', 'ai-seo-tool'); ?></p>
            </div>
            <div class="chat-input">
                <div class="status-line" id="chatbot-status"></div>
                <div class="chatbot-actions" id="chatbot-actions"></div>
                <form id="chatbot-message-form">
                    <textarea id="chatbot-message" placeholder="<?php esc_attr_e('Ask anything about the company…', 'ai-seo-tool'); ?>" disabled></textarea>
                    <button class="btn-primary" type="submit" id="chatbot-send" disabled><?php esc_html_e('Send', 'ai-seo-tool'); ?></button>
                </form>
            </div>
        </div>
    </div>
    <script>
        (() => {
            const API_BASE = '<?php echo $restBase; ?>';
            const form = document.getElementById('chatbot-user-form');
            const sessionList = document.getElementById('chatbot-session-list');
            const historyEl = document.getElementById('chatbot-history');
            const actionsEl = document.getElementById('chatbot-actions');
            const messageForm = document.getElementById('chatbot-message-form');
            const messageInput = document.getElementById('chatbot-message');
            const sendBtn = document.getElementById('chatbot-send');
            const statusLine = document.getElementById('chatbot-status');
            const newSessionBtn = document.getElementById('chatbot-new-session');

            const state = {
                profile: null,
                sessions: [],
                session: null,
                actions: [],
                loading: false
            };

            function setStatus(text, tone = 'info') {
                statusLine.textContent = text || '';
                statusLine.style.color = tone === 'error' ? '#f87171' : '#fbbf24';
            }

            function renderSessions() {
                sessionList.innerHTML = '';
                if (!state.sessions.length) {
                    sessionList.innerHTML = '<p style="color:#94a3b8;"><?php echo esc_js(__('No sessions yet. Start chatting to create one.', 'ai-seo-tool')); ?></p>';
                    return;
                }
                state.sessions.forEach((session) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'session-item';
                    const stamp = session.updated_at ? new Date(session.updated_at).toLocaleString() : '—';
                    btn.innerHTML = `
                        <strong>${session.title || '<?php echo esc_js(__('Conversation', 'ai-seo-tool')); ?>'}</strong>
                        <div style="font-size:13px;color:#cbd5f5;margin-top:6px;">${stamp}</div>
                        <div style="font-size:13px;color:#94a3b8;margin-top:4px;">${session.preview || ''}</div>
                    `;
                    btn.addEventListener('click', () => loadSession(session.id));
                    sessionList.appendChild(btn);
                });
            }

            function renderHistory() {
                historyEl.innerHTML = '';
                if (!state.session || !state.session.messages || !state.session.messages.length) {
                    historyEl.innerHTML = '<p style="color:#94a3b8;"><?php echo esc_js(__('No messages yet. Say hello to begin.', 'ai-seo-tool')); ?></p>';
                    return;
                }
                state.session.messages.forEach((msg) => {
                    const bubble = document.createElement('div');
                    bubble.className = `bubble ${msg.role === 'assistant' ? 'assistant' : 'user'}`;
                    bubble.innerHTML = `<div style="font-size:12px;color:rgba(255,255,255,0.6);margin-bottom:4px;">${new Date(msg.timestamp).toLocaleString()}</div>${msg.text.replace(/\n/g,'<br>')}`;
                    historyEl.appendChild(bubble);
                });
                historyEl.scrollTop = historyEl.scrollHeight;
            }

            function renderActions() {
                actionsEl.innerHTML = '';
                if (!state.actions || !state.actions.length) {
                    actionsEl.style.display = 'none';
                    return;
                }
                state.actions.forEach((action) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = action.label;
                    btn.addEventListener('click', () => triggerAction(action.type));
                    actionsEl.appendChild(btn);
                });
                actionsEl.style.display = 'flex';
            }

            function applySessionPayload(payload) {
                if (!payload) {
                    return;
                }
                const session = payload.session || payload;
                state.session = session;
                state.actions = payload.actions || [];
                renderHistory();
                renderActions();
            }

            async function callApi(method, path, body) {
                const options = { method, headers: {'Content-Type': 'application/json'} };
                if (body) {
                    options.body = JSON.stringify(body);
                }
                const res = await fetch(API_BASE + path, options);
                const text = await res.text();
                let json = {};
                if (text) {
                    try {
                        json = JSON.parse(text);
                    } catch (err) {
                        throw new Error('<?php echo esc_js(__('Unexpected response from server.', 'ai-seo-tool')); ?>');
                    }
                }
                if (!res.ok) {
                    throw new Error(json.message || 'Request failed');
                }
                return json;
            }

            async function identify() {
                const name = document.getElementById('chatbot-name').value.trim();
                const email = document.getElementById('chatbot-email').value.trim();
                if (!name || !email) {
                    setStatus('<?php echo esc_js(__('Name and email are required.', 'ai-seo-tool')); ?>', 'error');
                    return;
                }
                setStatus('<?php echo esc_js(__('Looking up your sessions…', 'ai-seo-tool')); ?>');
                try {
                    const response = await callApi('POST', 'identify', { name, email });
                    state.profile = response.profile;
                    state.sessions = response.sessions || [];
                    renderSessions();
                    setStatus('<?php echo esc_js(__('Select a session or start a new chat.', 'ai-seo-tool')); ?>', 'info');
                } catch (error) {
                    console.error(error);
                    setStatus(error.message, 'error');
                }
            }

            async function loadSession(sessionId) {
                if (!state.profile) {
                    setStatus('<?php echo esc_js(__('Enter your details first.', 'ai-seo-tool')); ?>', 'error');
                    return;
                }
                setStatus('<?php echo esc_js(__('Loading conversation…', 'ai-seo-tool')); ?>');
                try {
                    const payload = await callApi('GET', `session?email=${encodeURIComponent(state.profile.email)}&session_id=${encodeURIComponent(sessionId)}`);
                    applySessionPayload(payload);
                    toggleInput(true);
                    setStatus('<?php echo esc_js(__('You can now continue the chat.', 'ai-seo-tool')); ?>', 'info');
                } catch (error) {
                    console.error(error);
                    setStatus(error.message, 'error');
                }
            }

            async function createSession() {
                if (!state.profile) {
                    setStatus('<?php echo esc_js(__('Enter name and email first.', 'ai-seo-tool')); ?>', 'error');
                    return;
                }
                setStatus('<?php echo esc_js(__('Creating a fresh chat…', 'ai-seo-tool')); ?>');
                try {
                    const session = await callApi('POST', 'session', { name: state.profile.name, email: state.profile.email });
                    applySessionPayload({ session });
                    state.sessions = [{ id: session.id, title: session.title, updated_at: session.updated_at, preview: '' }, ...state.sessions];
                    renderSessions();
                    toggleInput(true);
                    setStatus('<?php echo esc_js(__('Say hello to kick things off.', 'ai-seo-tool')); ?>', 'info');
                } catch (error) {
                    setStatus(error.message, 'error');
                }
            }

            async function sendMessage(evt) {
                evt.preventDefault();
                if (!state.session) {
                    setStatus('<?php echo esc_js(__('Pick a session to chat.', 'ai-seo-tool')); ?>', 'error');
                    return;
                }
                const text = messageInput.value.trim();
                if (!text) {
                    return;
                }
                toggleInput(false);
                setStatus('<?php echo esc_js(__('Thinking…', 'ai-seo-tool')); ?>');
                try {
                    const payload = await callApi('POST', 'message', {
                        name: state.profile.name,
                        email: state.profile.email,
                        session_id: state.session.id,
                        message: text
                    });
                    applySessionPayload(payload);
                    const latestSession = payload.session;
                    const latestMessage = latestSession && latestSession.messages ? latestSession.messages[latestSession.messages.length - 1] : null;
                    state.sessions = state.sessions.map((item) => item.id === latestSession.id ? {
                        ...item,
                        updated_at: latestSession.updated_at,
                        preview: latestMessage ? latestMessage.text : item.preview
                    } : item);
                    renderSessions();
                    messageInput.value = '';
                    if (payload.message) {
                        setStatus(payload.message, 'info');
                    } else {
                        setStatus('<?php echo esc_js(__('Reply delivered.', 'ai-seo-tool')); ?>', 'info');
                    }
                } catch (error) {
                    setStatus(error.message, 'error');
                } finally {
                    toggleInput(true);
                }
            }

            async function triggerAction(actionType) {
                if (!state.session) {
                    return;
                }
                setStatus('<?php echo esc_js(__('Working on your request…', 'ai-seo-tool')); ?>');
                toggleInput(false);
                try {
                    const payload = await callApi('POST', 'action', {
                        action: actionType,
                        name: state.profile.name,
                        email: state.profile.email,
                        session_id: state.session.id
                    });
                    applySessionPayload(payload);
                    if (payload.message) {
                        setStatus(payload.message, 'info');
                    } else {
                        setStatus('');
                    }
                } catch (error) {
                    setStatus(error.message, 'error');
                } finally {
                    toggleInput(true);
                }
            }

            function toggleInput(enabled) {
                messageInput.disabled = !enabled;
                sendBtn.disabled = !enabled;
            }

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                identify();
            });
            newSessionBtn.addEventListener('click', createSession);
            messageForm.addEventListener('submit', sendMessage);
        })();
    </script>
    <?php wp_footer(); ?>
</body>
</html>
