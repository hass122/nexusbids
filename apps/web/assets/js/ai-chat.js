/**
 * NexusBids AI Assistant Floating Widget v2.0
 * Fully theme-responsive (dark/light/system) using CSS custom properties
 * Connects directly to /api/v1/ai/chat
 */

(function () {
  function injectChatbot() {
    if (document.getElementById('nb-ai-chat-btn')) return;

    // Floating launcher button
    const chatBtn = document.createElement('button');
    chatBtn.id = 'nb-ai-chat-btn';
    chatBtn.className = 'fixed bottom-6 right-6 z-50 w-14 h-14 rounded-full bg-gradient-to-tr from-brand-600 to-indigo-500 text-white shadow-2xl flex items-center justify-center hover:scale-105 active:scale-95 transition-all duration-300';
    chatBtn.setAttribute('aria-label', 'Open AI Assistant');
    chatBtn.innerHTML = '<i class="fa-solid fa-robot text-xl"></i>';
    chatBtn.onclick = toggleChatWindow;
    document.body.appendChild(chatBtn);

    // Chat Window
    const chatModal = document.createElement('div');
    chatModal.id = 'nb-ai-chat-modal';
    chatModal.style.cssText = `
      position: fixed;
      bottom: 5.5rem;
      right: 1.5rem;
      z-index: 50;
      width: 24rem;
      max-width: calc(100vw - 3rem);
      height: 520px;
      max-height: calc(100vh - 7rem);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl, 1.5rem);
      box-shadow: var(--shadow-lg, 0 10px 40px rgba(0,0,0,0.3));
      display: flex;
      flex-direction: column;
      overflow: hidden;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      transform: scale(0.95);
      opacity: 0;
      pointer-events: none;
    `;

    chatModal.innerHTML = `
      <!-- Header -->
      <div style="padding: 1rem 1.25rem; background: var(--surface-2); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <div style="width: 2rem; height: 2rem; border-radius: 9999px; background: rgba(99,102,241,0.15); color: var(--brand-500); display: flex; align-items: center; justify-content: center; font-size: 0.875rem;">
            <i class="fa-solid fa-robot"></i>
          </div>
          <div>
            <h4 style="margin: 0; font-size: 0.875rem; font-weight: 700; color: var(--text);">NexusBids Assistant</h4>
            <p style="margin: 0; font-size: 0.65rem; color: #10b981; display: flex; align-items: center; gap: 0.35rem; font-weight: 600;">
              <span style="width: 0.375rem; height: 0.375rem; border-radius: 9999px; background: #10b981;"></span> Online &bull; Urdu / English
            </p>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
          <button onclick="clearAiChat()" style="background: none; border: none; color: var(--text-muted); padding: 0.25rem 0.5rem; font-size: 0.75rem; cursor: pointer; border-radius: 0.375rem;" title="Clear Chat" aria-label="Clear chat history">
            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
          </button>
          <button onclick="toggleChatWindow()" style="background: none; border: none; color: var(--text-muted); padding: 0.25rem 0.5rem; font-size: 0.875rem; cursor: pointer; border-radius: 0.375rem;" title="Close" aria-label="Close AI assistant">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <!-- Messages Area -->
      <div id="nb-ai-messages" style="flex: 1; padding: 1rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.75rem; line-height: 1.5; background: var(--bg);">
        <div style="display: flex; gap: 0.5rem;">
          <div style="width: 1.5rem; height: 1.5rem; border-radius: 9999px; background: rgba(99,102,241,0.15); color: var(--brand-500); display: flex; align-items: center; justify-content: center; font-size: 0.625rem; flex-shrink: 0; margin-top: 0.125rem;">
            <i class="fa-solid fa-robot"></i>
          </div>
          <div style="padding: 0.75rem 1rem; border-radius: 1rem; background: var(--surface); color: var(--text); border: 1px solid var(--border); max-width: 85%; box-shadow: var(--shadow-sm);">
            Assalam-o-Alaikum! I'm your NexusBids Assistant. How can I assist you with live auctions, NADRA KYC verification, bidding strategies, or escrow payments today?
          </div>
        </div>
      </div>

      <!-- Quick prompts -->
      <div id="nb-ai-chips" style="padding: 0.5rem 0.75rem; background: var(--surface-2); border-top: 1px solid var(--border); display: flex; gap: 0.35rem; overflow-x: auto; white-space: nowrap;">
        <button onclick="sendQuickPrompt('How does escrow protection work?')" style="padding: 0.25rem 0.6rem; border-radius: 9999px; background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); font-size: 0.65rem; cursor: pointer; font-weight: 600;">Escrow Protection?</button>
        <button onclick="sendQuickPrompt('How do I verify my CNIC / KYC?')" style="padding: 0.25rem 0.6rem; border-radius: 9999px; background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); font-size: 0.65rem; cursor: pointer; font-weight: 600;">KYC / CNIC?</button>
        <button onclick="sendQuickPrompt('How to place a proxy auto-bid?')" style="padding: 0.25rem 0.6rem; border-radius: 9999px; background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); font-size: 0.65rem; cursor: pointer; font-weight: 600;">Auto-Bidding?</button>
      </div>

      <!-- Input Bar -->
      <div style="padding: 0.75rem; background: var(--surface); border-top: 1px solid var(--border); display: flex; gap: 0.5rem; align-items: center;">
        <input type="text" id="nb-ai-input" placeholder="Type in English, Urdu or Roman Urdu..."
               style="flex: 1; padding: 0.5rem 0.875rem; border-radius: 0.75rem; background: var(--input-bg); border: 1px solid var(--input-border); color: var(--text); font-size: 0.75rem; outline: none;"
               onkeydown="if(event.key==='Enter') sendAiMessage()">
        <button onclick="sendAiMessage()" id="nb-ai-send-btn" aria-label="Send message to AI assistant"
                style="width: 2.25rem; height: 2.25rem; border-radius: 0.75rem; background: var(--brand-600); color: #fff; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; flex-shrink: 0;">
          <i class="fa-solid fa-paper-plane" style="font-size: 0.75rem;" aria-hidden="true"></i>
        </button>
      </div>
    `;
    document.body.appendChild(chatModal);
  }

  function toggleChatWindow() {
    const modal = document.getElementById('nb-ai-chat-modal');
    if (!modal) return;
    const isClosed = modal.style.opacity === '0' || modal.style.pointerEvents === 'none';
    if (isClosed) {
      modal.style.transform = 'scale(1)';
      modal.style.opacity = '1';
      modal.style.pointerEvents = 'auto';
      setTimeout(() => document.getElementById('nb-ai-input')?.focus(), 100);
    } else {
      modal.style.transform = 'scale(0.95)';
      modal.style.opacity = '0';
      modal.style.pointerEvents = 'none';
    }
  }

  function sendQuickPrompt(text) {
    const input = document.getElementById('nb-ai-input');
    if (!input) return;
    input.value = text;
    sendAiMessage();
  }

  async function sendAiMessage() {
    const input = document.getElementById('nb-ai-input');
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    const container = document.getElementById('nb-ai-messages');

    // Add user bubble
    const userDiv = document.createElement('div');
    userDiv.style.cssText = 'display: flex; justify-content: flex-end;';
    userDiv.innerHTML = `
      <div style="padding: 0.75rem 1rem; border-radius: 1rem; background: var(--brand-600); color: #ffffff; max-width: 85%; word-break: break-word; box-shadow: var(--shadow-sm);">
        ${escapeHtml(msg)}
      </div>
    `;
    container.appendChild(userDiv);
    container.scrollTop = container.scrollHeight;

    // Loading indicator
    const loadingId = 'ai-loading-' + Date.now();
    const loadingDiv = document.createElement('div');
    loadingDiv.id = loadingId;
    loadingDiv.style.cssText = 'display: flex; gap: 0.5rem;';
    loadingDiv.innerHTML = `
      <div style="width: 1.5rem; height: 1.5rem; border-radius: 9999px; background: rgba(99,102,241,0.15); color: var(--brand-500); display: flex; align-items: center; justify-content: center; font-size: 0.625rem; flex-shrink: 0; margin-top: 0.125rem;">
        <i class="fa-solid fa-robot"></i>
      </div>
      <div style="padding: 0.75rem 1rem; border-radius: 1rem; background: var(--surface); color: var(--text-muted); border: 1px solid var(--border); max-width: 85%;">
        <i class="fa-solid fa-spinner fa-spin"></i> NexusBids Assistant is typing...
      </div>
    `;
    container.appendChild(loadingDiv);
    container.scrollTop = container.scrollHeight;

    try {
      const res = await ApiClient.post('/ai/chat', { message: msg });
      const reply = res.data?.reply || 'I am ready to help you with your auctions and bidding.';
      document.getElementById(loadingId)?.remove();

      const botDiv = document.createElement('div');
      botDiv.style.cssText = 'display: flex; gap: 0.5rem;';
      botDiv.innerHTML = `
        <div style="width: 1.5rem; height: 1.5rem; border-radius: 9999px; background: rgba(99,102,241,0.15); color: var(--brand-500); display: flex; align-items: center; justify-content: center; font-size: 0.625rem; flex-shrink: 0; margin-top: 0.125rem;">
          <i class="fa-solid fa-robot"></i>
        </div>
        <div style="padding: 0.75rem 1rem; border-radius: 1rem; background: var(--surface); color: var(--text); border: 1px solid var(--border); max-width: 85%; line-height: 1.5; box-shadow: var(--shadow-sm); white-space: pre-line;">
          ${escapeHtml(reply)}
        </div>
      `;
      container.appendChild(botDiv);
    } catch (err) {
      document.getElementById(loadingId)?.remove();
      const errDiv = document.createElement('div');
      errDiv.style.cssText = 'display: flex; justify-content: center;';
      errDiv.innerHTML = `<span style="font-size: 0.7rem; color: #ef4444; background: var(--danger-bg); padding: 0.25rem 0.75rem; border-radius: 9999px;">${escapeHtml(err.message)}</span>`;
      container.appendChild(errDiv);
    }
    container.scrollTop = container.scrollHeight;
  }

  async function clearAiChat() {
    try {
      await ApiClient.post('/ai/chat', { action: 'clear' });
    } catch {}
    const container = document.getElementById('nb-ai-messages');
    if (container) {
      container.innerHTML = `
        <div style="display: flex; gap: 0.5rem;">
          <div style="width: 1.5rem; height: 1.5rem; border-radius: 9999px; background: rgba(99,102,241,0.15); color: var(--brand-500); display: flex; align-items: center; justify-content: center; font-size: 0.625rem; flex-shrink: 0; margin-top: 0.125rem;">
            <i class="fa-solid fa-robot"></i>
          </div>
          <div style="padding: 0.75rem 1rem; border-radius: 1rem; background: var(--surface); color: var(--text); border: 1px solid var(--border); max-width: 85%; box-shadow: var(--shadow-sm);">
            Chat cleared! How can I assist you today?
          </div>
        </div>
      `;
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  window.openAiChat = toggleChatWindow;
  window.sendAiMessage = sendAiMessage;
  window.sendQuickPrompt = sendQuickPrompt;
  window.clearAiChat = clearAiChat;
  document.addEventListener('DOMContentLoaded', injectChatbot);
})();
