(function () {
  function init(instance) {
    const ajaxUrl = instance.getAttribute('data-ajax-url');
    const moduleId = instance.getAttribute('data-module-id');
    const position = instance.getAttribute('data-position') || 'bottom-right';
    const offsetBottom = parseInt(instance.getAttribute('data-offset-bottom') || '20', 10);
    const offsetSide = parseInt(instance.getAttribute('data-offset-side') || '20', 10);
    try {
      console.debug('[Bears AI Chatbot] init', { moduleId, ajaxUrl, position, offsetBottom, offsetSide });
    } catch (e) {}

    // Apply offsets via CSS variables
    instance.style.setProperty('--bears-offset-bottom', offsetBottom + 'px');
    instance.style.setProperty('--bears-offset-side', offsetSide + 'px');

    instance.setAttribute('data-position', position);

    const messages = instance.querySelector('.bears-aichatbot-messages');
    const input = instance.querySelector('.bears-aichatbot-text');
    const sendBtn = instance.querySelector('.bears-aichatbot-send');

    function appendMessage(role, text) {
      const wrap = document.createElement('div');
      wrap.className = 'message ' + (role === 'user' ? 'user' : 'bot');
      const bubble = document.createElement('div');
      bubble.className = 'bubble';
      bubble.textContent = text;
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
