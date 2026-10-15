<?php
/**
 * NexusBids AI Chat Widget — Reusable UI Component
 * Include in any page: <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>
 * For subfolders (user/, seller/): <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
 */

if (!isset($GLOBALS['__nb_chat_widget_rendered'])) {
    $GLOBALS['__nb_chat_widget_rendered'] = true;

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $chat_user_name = $_SESSION['full_name'] ?? 'Guest';
    $chat_initials = strtoupper(substr(preg_replace('/\s+/', '', $chat_user_name), 0, 2)) ?: 'NB';
    $chat_is_logged = !empty($_SESSION['user_id']);
    $chat_greeting = $chat_is_logged
        ? explode(' ', trim($chat_user_name))[0]
        : '';
    ?>
    <!-- ============ NexusBids AI Chat Widget ============ -->
    <style>
        #nb-chat-btn,
        #nb-chat-panel,
        #nb-chat-panel * {
            box-sizing: border-box;
        }

        /* ---------- Floating Button ---------- */
        #nb-chat-btn {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 9998;
            width: 62px;
            height: 62px;
            border-radius: 999px;
            border: 0;
            cursor: pointer;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 30px rgba(79, 70, 229, .45), 0 0 0 0 rgba(79, 70, 229, .4);
            transition: transform .25s ease, box-shadow .25s ease;
            animation: nbPulse 2.6s infinite;
        }

        #nb-chat-btn:hover {
            transform: scale(1.08);
        }

        #nb-chat-btn:active {
            transform: scale(.96);
        }

        @keyframes nbPulse {

            0%,
            100% {
                box-shadow: 0 12px 30px rgba(79, 70, 229, .45), 0 0 0 0 rgba(79, 70, 229, .4);
            }

            50% {
                box-shadow: 0 12px 30px rgba(79, 70, 229, .55), 0 0 0 16px rgba(79, 70, 229, 0);
            }
        }

        #nb-chat-btn .nb-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            background: #f43f5e;
            border: 2px solid #fff;
            border-radius: 999px;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(244, 63, 94, .5);
        }

        .dark #nb-chat-btn .nb-badge {
            border-color: #0f172a;
        }

        /* ---------- Chat Panel ---------- */
        #nb-chat-panel {
            position: fixed;
            right: 20px;
            bottom: 94px;
            z-index: 9999;
            width: 390px;
            max-width: calc(100vw - 32px);
            height: 580px;
            max-height: calc(100vh - 130px);
            background: #fff;
            border-radius: 22px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, .22), 0 0 0 1px rgba(0, 0, 0, .05);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform: translateY(24px) scale(.94);
            opacity: 0;
            pointer-events: none;
            transition: transform .28s cubic-bezier(.4, 0, .2, 1), opacity .28s ease;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        #nb-chat-panel.nb-open {
            transform: translateY(0) scale(1);
            opacity: 1;
            pointer-events: auto;
        }

        /* ---------- Header ---------- */
        .nb-header {
            padding: 14px 16px;
            color: #fff;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }

        .nb-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 0%, rgba(255, 255, 255, .15), transparent 60%);
            pointer-events: none;
        }

        .nb-header .nb-avatar {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            flex-shrink: 0;
            background: rgba(255, 255, 255, .18);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .5px;
            border: 1px solid rgba(255, 255, 255, .28);
            position: relative;
            z-index: 1;
        }

        .nb-header .nb-avatar i {
            font-size: 18px;
        }

        .nb-header .nb-info {
            flex: 1;
            min-width: 0;
            position: relative;
            z-index: 1;
        }

        .nb-header h4 {
            margin: 0;
            font-size: 14.5px;
            font-weight: 800;
            letter-spacing: -.2px;
            line-height: 1.2;
        }

        .nb-header p {
            margin: 3px 0 0;
            font-size: 11px;
            opacity: .9;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nb-header p .nb-dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: #34d399;
            box-shadow: 0 0 0 3px rgba(52, 211, 153, .35);
            animation: nbBlink 2s infinite;
        }

        @keyframes nbBlink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .5;
            }
        }

        .nb-header .nb-icon-btn {
            width: 32px;
            height: 32px;
            flex-shrink: 0;
            background: rgba(255, 255, 255, .15);
            border: 0;
            color: #fff;
            border-radius: 9px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s, transform .2s;
            position: relative;
            z-index: 1;
        }

        .nb-header .nb-icon-btn:hover {
            background: rgba(255, 255, 255, .28);
        }

        .nb-header .nb-icon-btn:active {
            transform: scale(.92);
        }

        /* ---------- Body ---------- */
        .nb-body {
            flex: 1;
            overflow-y: auto;
            padding: 18px 16px;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 12px;
            scroll-behavior: smooth;
        }

        .nb-body::-webkit-scrollbar {
            width: 6px;
        }

        .nb-body::-webkit-scrollbar-track {
            background: transparent;
        }

        .nb-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        .dark .nb-body::-webkit-scrollbar-thumb {
            background: #334155;
        }

        /* ---------- Messages ---------- */
        .nb-msg {
            display: flex;
            gap: 8px;
            max-width: 88%;
            animation: nbFadeUp .3s ease-out;
        }

        @keyframes nbFadeUp {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .nb-msg.nb-user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .nb-msg .nb-bubble {
            padding: 11px 14px;
            border-radius: 18px;
            font-size: 13.5px;
            line-height: 1.55;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
        }

        .nb-msg.nb-bot .nb-bubble {
            background: #fff;
            color: #0f172a;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 5px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .03);
        }

        .nb-msg.nb-user .nb-bubble {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            border-bottom-right-radius: 5px;
            box-shadow: 0 4px 12px rgba(79, 70, 229, .25);
        }

        .nb-msg .nb-time {
            font-size: 10px;
            opacity: .55;
            margin-top: 5px;
            padding: 0 4px;
        }

        .nb-msg .nb-bubble a {
            color: #6366f1;
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .nb-msg.nb-user .nb-bubble a {
            color: #fff;
        }

        .nb-msg .nb-bubble code {
            background: rgba(99, 102, 241, .12);
            padding: 1px 6px;
            border-radius: 5px;
            font-family: ui-monospace, 'SF Mono', Menlo, monospace;
            font-size: 12px;
        }

        .nb-msg .nb-bubble strong {
            font-weight: 700;
        }

        /* ---------- Typing ---------- */
        .nb-typing {
            display: inline-flex;
            gap: 5px;
            align-items: center;
            padding: 14px 16px;
        }

        .nb-typing span {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: #94a3b8;
            animation: nbBounce 1.3s infinite ease-in-out;
        }

        .nb-typing span:nth-child(2) {
            animation-delay: .15s;
        }

        .nb-typing span:nth-child(3) {
            animation-delay: .3s;
        }

        @keyframes nbBounce {

            0%,
            60%,
            100% {
                transform: translateY(0);
                opacity: .45;
            }

            30% {
                transform: translateY(-7px);
                opacity: 1;
            }
        }

        /* ---------- Suggestions ---------- */
        .nb-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
            padding: 0 4px;
        }

        .nb-suggest {
            background: #fff;
            border: 1px solid #c7d2fe;
            color: #4338ca;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            font-family: inherit;
        }

        .nb-suggest:hover {
            background: #eef2ff;
            border-color: #6366f1;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(99, 102, 241, .15);
        }

        .nb-suggest:active {
            transform: translateY(0);
        }

        /* ---------- Footer ---------- */
        .nb-footer {
            border-top: 1px solid #e2e8f0;
            padding: 12px;
            background: #fff;
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .nb-footer textarea {
            flex: 1;
            resize: none;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 11px 14px;
            font-size: 13.5px;
            font-family: inherit;
            outline: none;
            max-height: 110px;
            min-height: 44px;
            line-height: 1.45;
            color: #0f172a;
            background: #fff;
            transition: border-color .2s, box-shadow .2s;
        }

        .nb-footer textarea:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .15);
        }

        .nb-footer textarea::placeholder {
            color: #94a3b8;
        }

        .nb-send {
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            border-radius: 13px;
            border: 0;
            cursor: pointer;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .2s, box-shadow .2s, opacity .2s;
            box-shadow: 0 6px 14px rgba(79, 70, 229, .35);
        }

        .nb-send:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(79, 70, 229, .45);
        }

        .nb-send:active:not(:disabled) {
            transform: translateY(0) scale(.96);
        }

        .nb-send:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        /* ---------- Dark Mode ---------- */
        .dark #nb-chat-panel {
            background: #0f172a;
            box-shadow: 0 25px 60px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .06);
        }

        .dark .nb-body {
            background: #020617;
        }

        .dark .nb-msg.nb-bot .nb-bubble {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }

        .dark .nb-msg.nb-user .nb-bubble {
            background: linear-gradient(135deg, #4338ca, #6d28d9);
        }

        .dark .nb-footer {
            background: #0f172a;
            border-top-color: #1e293b;
        }

        .dark .nb-footer textarea {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }

        .dark .nb-footer textarea::placeholder {
            color: #64748b;
        }

        .dark .nb-footer textarea:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .25);
        }

        .dark .nb-suggest {
            background: #1e293b;
            border-color: #334155;
            color: #a5b4fc;
        }

        .dark .nb-suggest:hover {
            background: #312e81;
            border-color: #6366f1;
            color: #e0e7ff;
        }

        .dark .nb-typing span {
            background: #64748b;
        }

        /* ---------- Mobile ---------- */
        @media (max-width: 480px) {
            #nb-chat-panel {
                right: 8px;
                left: 8px;
                bottom: 86px;
                width: auto;
                height: calc(100vh - 110px);
                border-radius: 18px;
            }

            #nb-chat-btn {
                right: 14px;
                bottom: 14px;
                width: 56px;
                height: 56px;
                font-size: 22px;
            }

            .nb-msg {
                max-width: 92%;
            }
        }
    </style>

    <!-- Floating Button -->
    <button id="nb-chat-btn" aria-label="Open AI chat assistant" type="button">
        <i class="fa-solid fa-comment-dots" id="nb-chat-btn-icon"></i>
        <span class="nb-badge" id="nb-chat-badge">1</span>
    </button>

    <!-- Chat Panel -->
    <div id="nb-chat-panel" role="dialog" aria-label="NexusBot chat assistant" aria-hidden="true">

        <!-- Header -->
        <div class="nb-header">
            <div class="nb-avatar">
                <?php if ($chat_is_logged): ?>
                    <?php echo htmlspecialchars($chat_initials); ?>
                <?php else: ?>
                    <i class="fa-solid fa-robot"></i>
                <?php endif; ?>
            </div>
            <div class="nb-info">
                <h4>NexusBot</h4>
                <p><span class="nb-dot"></span> Online · AI Assistant</p>
            </div>
            <button class="nb-icon-btn" id="nb-chat-clear" title="Clear chat" type="button">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
            <button class="nb-icon-btn" id="nb-chat-close" title="Close" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Messages -->
        <div class="nb-body" id="nb-chat-body" aria-live="polite"></div>

        <!-- Footer -->
        <div class="nb-footer">
            <textarea id="nb-chat-input" rows="1" maxlength="500" placeholder="Ask me anything..."
                aria-label="Type your message"></textarea>
            <button class="nb-send" id="nb-chat-send" aria-label="Send message" type="button">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
    </div>

    <script>
        (function () {
            'use strict';

            /* ---------- Elements ---------- */
            const btn = document.getElementById('nb-chat-btn');
            const btnIcon = document.getElementById('nb-chat-btn-icon');
            const panel = document.getElementById('nb-chat-panel');
            const closeBtn = document.getElementById('nb-chat-close');
            const clearBtn = document.getElementById('nb-chat-clear');
            const body = document.getElementById('nb-chat-body');
            const input = document.getElementById('nb-chat-input');
            const sendBtn = document.getElementById('nb-chat-send');
            const badge = document.getElementById('nb-chat-badge');

            if (!btn || !panel) return;

            /* ---------- Config ---------- */
            // Auto-detect project root from current URL
            const API_URL = (function () {
                const path = window.location.pathname;
                const match = path.match(/^(.*?)(?:user|seller|admin|includes|auth)?\/[^\/]*$/);
                const root = match ? match[1] : '';
                return root + '/ai-chat-api.php';
            })();
            const CSRF_META = document.querySelector('meta[name="csrf-token"]');
            const CSRF = CSRF_META ? CSRF_META.content : '';

            const USER_NAME = <?php echo json_encode($chat_user_name); ?>;
            const IS_LOGGED = <?php echo $chat_is_logged ? 'true' : 'false'; ?>;

            const SUGGESTIONS = [
                'How do I place a bid?',
                'How does auto-bid work?',
                'What payment methods are accepted?',
                'How do I track my order?',
                'How to sell on NexusBids?'
            ];

            let isOpen = false;
            let isSending = false;
            let greeted = false;

            /* ---------- Escaping & formatting ---------- */
            function esc(str) {
                return String(str == null ? '' : str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function fmt(text) {
                let s = esc(text);
                // **bold**
                s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                // `code`
                s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
            // URLs
            s = s.replace(
                /(https?:\/\/[^\s<]+)/g,
                '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
            );
            return s;
        }

        /* ---------- Message rendering ---------- */
        function addMessage(role, text, time) {
            const wrap = document.createElement('div');
            wrap.className = 'nb-msg ' + (role === 'user' ? 'nb-user' : 'nb-bot');

            const inner = document.createElement('div');
            inner.style.maxWidth = '100%';

            const bubble = document.createElement('div');
            bubble.className = 'nb-bubble';
            bubble.innerHTML = fmt(text);

            const timeEl = document.createElement('div');
            timeEl.className = 'nb-time';
            timeEl.style.textAlign = role === 'user' ? 'right' : 'left';
            timeEl.textContent = time || '';

            inner.appendChild(bubble);
            if (time) inner.appendChild(timeEl);
            wrap.appendChild(inner);
            body.appendChild(wrap);
            body.scrollTop = body.scrollHeight;
        }

        function showTyping() {
            hideTyping();
            const el = document.createElement('div');
            el.className = 'nb-msg nb-bot';
            el.id = 'nb-typing';
            el.innerHTML = '<div class="nb-bubble nb-typing"><span></span><span></span><span></span></div>';
            body.appendChild(el);
            body.scrollTop = body.scrollHeight;
        }

        function hideTyping() {
            const el = document.getElementById('nb-typing');
            if (el) el.remove();
        }

        function showSuggestions() {
            const old = document.getElementById('nb-sugg');
            if (old) old.remove();

            const box = document.createElement('div');
            box.className = 'nb-suggestions';
            box.id = 'nb-sugg';

            SUGGESTIONS.forEach(function (s) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'nb-suggest';
                b.textContent = s;
                b.addEventListener('click', function () {
                    input.value = s;
                    box.remove();
                    send();
                });
                box.appendChild(b);
            });
            body.appendChild(box);
            body.scrollTop = body.scrollHeight;
        }

        function greet() {
            const hour = new Date().getHours();
            const part = hour < 12 ? 'Good morning'
                : hour < 17 ? 'Good afternoon'
                    : 'Good evening';

            let hello;
            if (IS_LOGGED && USER_NAME && USER_NAME !== 'Guest') {
                const first = USER_NAME.split(' ')[0];
                hello = '👋 ' + part + ', ' + first + '! I\'m NexusBot. How can I help you today?';
            } else {
                hello = '👋 ' + part + '! I\'m NexusBot — your NexusBids assistant. How can I help?';
            }
            addMessage('assistant', hello, 'just now');
            showSuggestions();
        }

        /* ---------- Open / Close ---------- */
        function openPanel() {
            panel.classList.add('nb-open');
            panel.setAttribute('aria-hidden', 'false');
            isOpen = true;
            badge.style.display = 'none';
            btnIcon.className = 'fa-solid fa-xmark';
            if (!greeted) {
                greet();
                greeted = true;
            }
            setTimeout(function () {input.focus();}, 220);
        }

        function closePanel() {
            panel.classList.remove('nb-open');
            panel.setAttribute('aria-hidden', 'true');
            isOpen = false;
            btnIcon.className = 'fa-solid fa-comment-dots';
        }

        btn.addEventListener('click', function () {
            isOpen ? closePanel() : openPanel();
        });
        closeBtn.addEventListener('click', closePanel);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) closePanel();
        });

        /* ---------- Send ---------- */
        async function send() {
            if (isSending) return;
            const text = input.value.trim();
            if (!text) return;

            isSending = true;
            sendBtn.disabled = true;

            input.value = '';
            input.style.height = 'auto';

            const s = document.getElementById('nb-sugg');
            if (s) s.remove();

            const nowTime = new Date().toLocaleTimeString('en-PK', {
                hour: '2-digit', minute: '2-digit'
            });

            addMessage('user', text, nowTime);
            showTyping();

            try {
                const fd = new FormData();
                fd.append('message', text);
                fd.append('action', 'send');
                if (CSRF) fd.append('csrf_token', CSRF);

                const res = await fetch(API_URL, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });

                let data;
                try {data = await res.json();}
                catch (err) {data = {success: false, error: 'Invalid server response.'};}

                hideTyping();

                if (data && data.success && data.reply) {
                    addMessage('assistant', data.reply, data.time || nowTime);
                } else {
                    addMessage('assistant',
                        '⚠️ ' + ((data && data.error) || 'Something went wrong. Please try again.'),
                        nowTime);
                }
            } catch (err) {
                hideTyping();
                addMessage('assistant',
                    '⚠️ Connection issue. Please check your internet and try again.',
                    '');
            } finally {
                isSending = false;
                sendBtn.disabled = false;
                input.focus();
            }
        }

        sendBtn.addEventListener('click', send);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });

        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 110) + 'px';
        });

        /* ---------- Clear ---------- */
        clearBtn.addEventListener('click', async function () {
            if (!confirm('Clear this conversation?')) return;

            try {
                const fd = new FormData();
                fd.append('action', 'clear');
                if (CSRF) fd.append('csrf_token', CSRF);
                await fetch(API_URL, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
            } catch (e) { /* silent */}

            body.innerHTML = '';
            greeted = false;
            greet();
            greeted = true;
        });

        /* ---------- Load prior history (silent) ---------- */
        (async function loadHistory() {
            try {
                const fd = new FormData();
                fd.append('action', 'history');
                if (CSRF) fd.append('csrf_token', CSRF);

                const res = await fetch(API_URL, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
                const data = await res.json();

                if (data && data.success && Array.isArray(data.history) && data.history.length > 0) {
                    data.history.forEach(function (m) {
                        addMessage(m.role, m.content, '');
                    });
                    greeted = true;
                }
            } catch (e) { /* silent */}
        })();

        /* ---------- Show badge after 6 seconds if not opened ---------- */
        setTimeout(function () {
            if (!isOpen) badge.style.display = 'flex';
        }, 6000);

    })();
</script>
<!-- ============ /NexusBids AI Chat Widget ============ -->
<?php } ?>