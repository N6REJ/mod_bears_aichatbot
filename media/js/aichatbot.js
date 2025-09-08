(function () {
  function ensureStyles() {
    try {
      if (document.getElementById('bears-aichatbot-inline-style')) return;
      const css = `
.bears-aichatbot--closed .bears-aichatbot-window{display:none}
.bears-aichatbot--open .bears-aichatbot-toggle{display:none}
.bears-aichatbot--open .bears-aichatbot-window{width:var(--bears-open-width, min(400px,90vw));height:var(--bears-open-height, 70vh);resize:both;overflow:auto}
.bears-aichatbot-toggle{width:56px;height:56px;border-radius:50%;background:#0b74de;color:#fff;border:none;box-shadow:0 6px 18px rgba(0,0,0,.2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:22px}
.bears-aichatbot-header{position:relative}
.bears-aichatbot-close{position:absolute;right:8px;top:8px;background:transparent;border:none;color:#fff;font-size:20px;cursor:pointer}
/* middle side positions */
.bears-aichatbot[data-position="middle-right"]{right:var(--bears-offset-side,20px);top:50%;transform:translateY(-50%)}
.bears-aichatbot[data-position="middle-left"]{left:var(--bears-offset-side,20px);top:50%;transform:translateY(-50%)}
/* vertical toggle for middle positions */
.bears-aichatbot[data-position="middle-right"] .bears-aichatbot-toggle,
.bears-aichatbot[data-position="middle-left"] .bears-aichatbot-toggle{writing-mode:vertical-rl;text-orientation:mixed;width:42px;height:auto;padding:10px 8px;border-radius:10px;font-size:14px}
@media (max-width: 767px){.bears-aichatbot{display:none !important}}
/* formatted message elements */
.bears-aichatbot .bubble a{color:#0b74de;text-decoration:underline;word-break:break-all}
.bears-aichatbot .bubble pre{background:#f6f8fa;padding:8px;border-radius:6px;overflow:auto}
.bears-aichatbot .bubble code{background:#f2f4f7;padding:2px 4px;border-radius:4px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.bears-aichatbot .bubble ul{margin:0.5em 0;padding-left:1.25em}
.bears-aichatbot .bubble li{margin:0.25em 0}
`;
      const style = document.createElement('style');
      style.id = 'bears-aichatbot-inline-style';
      style.type = 'text/css';
      style.appendChild(document.createTextNode(css));
      document.head.appendChild(style);
    } catch(e) {}
  }
  // Formatting helpers to render AI responses with basic Markdown and clickable links
  function formatAnswer(text) {
    if (!text) return '';
    let placeholderIndex = 0;
    const blocks = [];
    // Extract fenced code blocks and replace with placeholders
    let out = String(text).replace(/```([\s\S]*?)```/g, function (_, code) {
      const token = '[[[CODEBLOCK_' + (placeholderIndex++) + ']]]';
      blocks.push(code);
      return token;
    });

    // Escape HTML for remaining text
    out = escapeHtml(out);

    // Inline code `...`
    out = out.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Markdown links [text](url)
    out = out.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1<\/a>');

    // Autolink any remaining plain URLs
    out = autolink(out);

    // Restore fenced code blocks
    out = out.replace(/\[\[\[CODEBLOCK_(\d+)\]\]\]/g, function (_, idx) {
      const code = blocks[Number(idx)] || '';
      return '<pre><code>' + escapeHtml(code) + '<\/code><\/pre>';
    });

    // Turn leading dash/star lines into simple lists
    out = listify(out);

    return out;
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (s) {
      switch (s) {
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        case "'": return '&#39;';
        default: return s;
      }
    });
  }

  function autolink(str) {
    return String(str).replace(/(https?:\/\/[^\s<]+)(?![^<]*>)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1<\/a>');
  }

  function listify(html) {
    const lines = String(html).split(/\r?\n/);
    const out = [];
    let inList = false;
    for (let i = 0; i < lines.length; i++) {
      const ln = lines[i];
      if (/^\s*[-*]\s+/.test(ln)) {
        if (!inList) { out.push('<ul>'); inList = true; }
        out.push('<li>' + ln.replace(/^\s*[-*]\s+/, '') + '</li>');
      } else {
        if (inList) { out.push('</ul>'); inList = false; }
        out.push(ln);
      }
    }
    if (inList) out.push('</ul>');
    return out.join('\n');
  }

  function init(instance) {
    const ajaxUrl = instance.getAttribute('data-ajax-url');
    const moduleId = instance.getAttribute('data-module-id');
    const position = instance.getAttribute('data-position') || 'bottom-right';
    const offsetBottom = parseInt(instance.getAttribute('data-offset-bottom') || '20', 10);
    const offsetSide = parseInt(instance.getAttribute('data-offset-side') || '20', 10);
    const openWidth = parseInt(instance.getAttribute('data-open-width') || '400', 10);
    const openHeight = parseInt(instance.getAttribute('data-open-height') || '500', 10);
    const openHeightPercent = parseInt(instance.getAttribute('data-open-height-percent') || '50', 10);
    const buttonLabel = instance.getAttribute('data-button-label') || 'Knowledgebase';
    try {
      console.debug('[Bears AI Chatbot] init', { moduleId, ajaxUrl, position, offsetBottom, offsetSide });
    } catch (e) {}

    // Apply offsets via CSS variables
    instance.style.setProperty('--bears-offset-bottom', offsetBottom + 'px');
    instance.style.setProperty('--bears-offset-side', offsetSide + 'px');

    instance.setAttribute('data-position', position);

    // Apply open width/height as CSS variables for dynamic sizing
    instance.style.setProperty('--bears-open-width', `min(${openWidth}px, 90vw)`);
    // Use percentage of viewport height by default (openHeightPercent), fallback to px if provided
    const hPercent = Math.min(Math.max(openHeightPercent, 10), 100);
    instance.style.setProperty('--bears-open-height', `min(${openHeight}px, ${hPercent}vh)`);

    // Inject styles and set initial state (closed)
    ensureStyles();
    instance.classList.add('bears-aichatbot--closed');

    // Create toggle (bubble) and header close button
    const toggle = document.createElement('button');
    toggle.className = 'bears-aichatbot-toggle';
    toggle.setAttribute('aria-label', 'Open chat');
    toggle.title = 'Chat';
    // Vertical labeled toggle for middle positions
    if (position === 'middle-right' || position === 'middle-left') {
      toggle.textContent = buttonLabel;
    } else {
      toggle.textContent = '💬';
    }
    instance.appendChild(toggle);

    const headerEl = instance.querySelector('.bears-aichatbot-header');
    let closeBtn = headerEl && headerEl.querySelector('.bears-aichatbot-close');
    if (!closeBtn && headerEl) {
      closeBtn = document.createElement('button');
      closeBtn.className = 'bears-aichatbot-close';
      closeBtn.setAttribute('aria-label', 'Close chat');
      closeBtn.type = 'button';
      closeBtn.textContent = '×';
      headerEl.appendChild(closeBtn);
    }

    function openChat() {
      instance.classList.add('bears-aichatbot--open');
      instance.classList.remove('bears-aichatbot--closed');
      // Anchor to bottom when opened
      instance.style.removeProperty('top');
      instance.style.removeProperty('transform');
      instance.style.setProperty('bottom', `var(--bears-offset-bottom, 20px)`);
      try { input && input.focus(); } catch(e) {}
    }
    function closeChat() {
      instance.classList.remove('bears-aichatbot--open');
      instance.classList.add('bears-aichatbot--closed');
    }
    toggle.addEventListener('click', openChat);
    if (closeBtn) closeBtn.addEventListener('click', closeChat);

    const messages = instance.querySelector('.bears-aichatbot-messages');
    const input = instance.querySelector('.bears-aichatbot-text');
    const sendBtn = instance.querySelector('.bears-aichatbot-send');

    function appendMessage(role, text) {
      const wrap = document.createElement('div');
      wrap.className = 'message ' + (role === 'user' ? 'user' : 'bot');
      const bubble = document.createElement('div');
      bubble.className = 'bubble';
      if (role === 'bot') {
        bubble.innerHTML = formatAnswer(String(text || ''));
      } else {
        bubble.textContent = String(text || '');
      }
      wrap.appendChild(bubble);
      messages.appendChild(wrap);
      // Auto-scroll to bottom
      messages.scrollTop = messages.scrollHeight;
    }

    function setLoading(loading) {
      if (loading) {
        sendBtn.setAttribute('disabled', 'disabled');
        sendBtn.dataset.prevText = sendBtn.textContent;
        sendBtn.textContent = '...';
      } else {
        sendBtn.removeAttribute('disabled');
        if (sendBtn.dataset.prevText) sendBtn.textContent = sendBtn.dataset.prevText;
      }
    }

    async function sendMessage() {
      const text = (input.value || '').trim();
      if (!text) return;
      openChat();
      try { console.debug('[Bears AI Chatbot] sending message', { moduleId, text }); } catch (e) {}
      appendMessage('user', text);
      input.value = '';
      setLoading(true);

      try {
        const body = new URLSearchParams();
        body.set('message', text);
        body.set('module_id', moduleId);

        try { console.debug('[Bears AI Chatbot] fetch', ajaxUrl, { body: body.toString() }); } catch (e) {}
        const res = await fetch(ajaxUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          },
          body: body.toString()
        });

        try { console.debug('[Bears AI Chatbot] response', { status: res.status, ok: res.ok }); } catch (e) {}
        // Joomla com_ajax wraps responses in an envelope: { success, data, messages }
        // Our helper returns an object inside data. Unwrap it here.
        const raw = await res.json();
        const payload = (raw && typeof raw === 'object' && 'data' in raw && raw.data !== null) ? raw.data : raw;
        try { console.debug('[Bears AI Chatbot] raw', raw); console.debug('[Bears AI Chatbot] payload', payload); } catch (e) {}

        if (payload && typeof payload === 'object') {
          if (payload.success && (payload.answer || payload.answer === '')) {
            try { console.info('[Bears AI Chatbot] answer', { length: (payload.answer || '').length }); } catch (e) {}
            appendMessage('bot', payload.answer);
          } else if (payload.error) {
            let err = 'Error: ' + payload.error;
            if (payload.status && !/status\s+\d+/.test(err)) {
              err += ' (status ' + payload.status + ')';
            }
            if (payload.body) {
              const bodyTxt = typeof payload.body === 'string' ? payload.body : JSON.stringify(payload.body);
              err += '\nDetails: ' + bodyTxt.substring(0, 2000);
            }
            try { console.warn('[Bears AI Chatbot] error payload', payload); } catch (e) {}
            appendMessage('bot', err);
          } else if ('message' in payload) {
            appendMessage('bot', String(payload.message));
          } else {
            appendMessage('bot', 'No response.');
          }
        } else if (typeof payload === 'string') {
          appendMessage('bot', payload);
        } else {
          appendMessage('bot', 'Unexpected response.');
        }
      } catch (e) {
        try { console.error('[Bears AI Chatbot] fetch error', e); } catch (ignored) {}
        appendMessage('bot', 'Error: ' + (e && e.message ? e.message : 'Network error'));
      } finally {
        setLoading(false);
      }
    }

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    const nodes = document.querySelectorAll('.bears-aichatbot');
    try { console.debug('[Bears AI Chatbot] found instances', nodes.length); } catch (e) {}
    nodes.forEach(init);
  });
})();
