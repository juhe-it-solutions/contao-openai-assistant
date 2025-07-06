/**
 * AI Chat Module JavaScript
 * Simple and reliable implementation following Contao 5 best practices
 */

// Mobile viewport height calculation for accurate 100vh on mobile devices
function appHeight() {
  const doc = document.documentElement;
  doc.style.setProperty('--vh', (window.innerHeight * 0.01) + 'px');
}

// Initialize
appHeight();

function initAiChat(wrapper) {
  if (!wrapper) return;

  // Get DOM elements
  const container = wrapper.querySelector('.ai-chat-container');
  const toggleBtn = wrapper.querySelector('.ai-chat-toggle');
  const minimizeBtn = wrapper.querySelector('.ai-chat-minimize');
  const themeToggleBtn = wrapper.querySelector('.ai-chat-theme-toggle');
  const form = wrapper.querySelector('.ai-chat-form');
  const input = wrapper.querySelector('.ai-chat-input');
  const sendBtn = wrapper.querySelector('.ai-chat-send');
  const log = wrapper.querySelector('.ai-chat-log');

  if (!container || !form || !input || !log) {
    console.error('AI Chat: Required elements not found');
    return;
  }

  // Get configuration from data attributes
  const config = {
    initialState: wrapper.dataset.initialState || 'collapsed',
    position: wrapper.dataset.position || 'right-bottom',
    theme: wrapper.classList.contains('theme-light') ? 'light' : 'dark'
  };

  // Language detection
  const getUserLanguage = () => {
    const lang = navigator.language || navigator.userLanguage;
    if (lang.startsWith('de')) {
      return 'de-DE';
    }
    return 'en-US';
  };

  const userLanguage = getUserLanguage();

  // CSRF Token Management
  const csrfTokenField = form.querySelector('input[name="REQUEST_TOKEN"]');
  const chatEndpoint = sendBtn?.dataset.endpoint || '/ai-chat/send';
  const tokenEndpoint = sendBtn?.dataset.tokenEndpoint || '/ai-chat/token';

  if (!csrfTokenField) {
    console.error('AI Chat: CSRF token field not found');
    return;
  }

  // Create toggle button if it doesn't exist
  let toggleButton = toggleBtn;
  if (!toggleButton) {
    toggleButton = document.createElement('button');
    toggleButton.type = 'button';
    toggleButton.className = 'ai-chat-toggle';
    toggleButton.setAttribute('aria-label', 'AI Chat öffnen');
    toggleButton.setAttribute('data-position', config.position);
    
    // Set initial colors
    const currentTheme = config.theme;
    const lightToggleIconColor = getComputedStyle(wrapper).getPropertyValue('--ai-chat-light-toggle-icon-color').trim();
    const darkToggleIconColor = getComputedStyle(wrapper).getPropertyValue('--ai-chat-dark-toggle-icon-color').trim();
    
    if (currentTheme === 'light') {
      toggleButton.style.setProperty('--ai-chat-toggle-icon-color', lightToggleIconColor);
    } else {
      toggleButton.style.setProperty('--ai-chat-toggle-icon-color', darkToggleIconColor);
    }
    
    toggleButton.innerHTML = `
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M20 2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h4l4 4 4-4h4c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" fill="currentColor"/>
        <path d="M8 9h8M8 13h6" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
    `;
    wrapper.before(toggleButton);
  }

  // Theme management
  const getCurrentTheme = () => {
    if (wrapper.classList.contains('theme-light')) return 'light';
    if (wrapper.classList.contains('theme-dark')) return 'dark';
    return 'light';
  };

  const setTheme = (theme) => {
    wrapper.classList.remove('theme-light', 'theme-dark');
    wrapper.classList.add(`theme-${theme}`);
    
    // Update CSS variables
    const lightToggleIconColor = getComputedStyle(wrapper).getPropertyValue('--ai-chat-light-toggle-icon-color').trim();
    const darkToggleIconColor = getComputedStyle(wrapper).getPropertyValue('--ai-chat-dark-toggle-icon-color').trim();
    
    if (theme === 'light') {
      wrapper.style.setProperty('--ai-chat-toggle-icon-color', lightToggleIconColor);
      wrapper.style.setProperty('--ai-chat-toggle-focus-shadow', `rgba(${parseInt(lightToggleIconColor.substr(0,2), 16)}, ${parseInt(lightToggleIconColor.substr(2,2), 16)}, ${parseInt(lightToggleIconColor.substr(4,2), 16)}, 0.2)`);
    } else {
      wrapper.style.setProperty('--ai-chat-toggle-icon-color', darkToggleIconColor);
      wrapper.style.setProperty('--ai-chat-toggle-focus-shadow', `rgba(${parseInt(darkToggleIconColor.substr(0,2), 16)}, ${parseInt(darkToggleIconColor.substr(2,2), 16)}, ${parseInt(darkToggleIconColor.substr(4,2), 16)}, 0.2)`);
    }
    
    // Update toggle button colors
    if (toggleButton) {
      if (theme === 'light') {
        toggleButton.style.setProperty('--ai-chat-toggle-icon-color', lightToggleIconColor);
      } else {
        toggleButton.style.setProperty('--ai-chat-toggle-icon-color', darkToggleIconColor);
      }
    }
    
    // Update theme toggle button icon
    if (themeToggleBtn) {
      themeToggleBtn.innerHTML = theme === 'light' ? `
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" fill="currentColor"/>
        </svg>
      ` : `
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="12" cy="12" r="5" fill="currentColor"/>
          <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      `;
    }
    
    // Store theme preference
    try {
      localStorage.setItem('ai-chat-theme', theme);
    } catch (error) {
      console.warn('AI Chat: Could not save theme preference');
    }
  };

  const toggleTheme = () => {
    const currentTheme = getCurrentTheme();
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    setTheme(newTheme);
  };

  // Initialize theme
  const initializeTheme = () => {
    try {
      const savedTheme = localStorage.getItem('ai-chat-theme');
      if (savedTheme && (savedTheme === 'light' || savedTheme === 'dark')) {
        setTheme(savedTheme);
      } else {
        setTheme(config.theme);
      }
    } catch (error) {
      console.warn('AI Chat: Could not load theme preference');
      setTheme(config.theme);
    }
  };

  // Chat state management
  const collapse = () => {
    wrapper.classList.add('ai-chat-collapsed');
    if (toggleButton) {
      toggleButton.hidden = false;
      toggleButton.focus();
    }
  };

  const expand = () => {
    wrapper.classList.remove('ai-chat-collapsed');
    if (toggleButton) {
      toggleButton.hidden = true;
    }
    // Only auto-focus input on desktop devices (width >= 768px)
    if (input && window.innerWidth >= 768) {
      // Small delay to ensure DOM transition is complete
      setTimeout(() => {
        input.focus();
      }, 100);
    }
  };

  // Initialize chat state based on configuration
  if (config.initialState === 'expanded') {
    expand();
  } else {
    collapse();
  }

  // Event listeners
  if (toggleButton) {
    toggleButton.addEventListener('click', expand);
  }

  if (minimizeBtn) {
    minimizeBtn.addEventListener('click', collapse);
  }

  if (themeToggleBtn) {
    themeToggleBtn.addEventListener('click', toggleTheme);
  }

  // Initialize theme
  initializeTheme();

  // Chat functionality
  let inFlight = false;
  let lastTokenRefresh = 0;
  const TOKEN_REFRESH_INTERVAL = 2 * 60 * 1000; // 2 minutes

  const shouldRefreshToken = () => {
    const now = Date.now();
    return (now - lastTokenRefresh) > TOKEN_REFRESH_INTERVAL;
  };

  const refreshToken = async () => {
    try {
      const response = await fetch(tokenEndpoint, {
        method: 'GET',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
          'Cache-Control': 'no-cache'
        },
        credentials: 'same-origin'
      });
      
      if (response.ok) {
        const responseText = await response.text();
        try {
          const data = JSON.parse(responseText);
          if (data.token) {
            csrfTokenField.value = data.token;
            lastTokenRefresh = Date.now();
            return data.token;
          }
        } catch (e) {
          console.error('Failed to parse token response:', e);
        }
      }
    } catch (error) {
      console.error('Token refresh failed:', error);
    }
    return null;
  };

  const ensureValidToken = async () => {
    if (shouldRefreshToken()) {
      const newToken = await refreshToken();
      if (newToken) {
        return newToken;
      }
    }
    return csrfTokenField.value;
  };

  const welcome = () => {
    const initialMessage = wrapper.dataset.initialMessage || 'Hallo! Wie kann ich dir helfen?';
    addMsg('assistant', initialMessage);
  };

  const loadHist = async () => {
    try {
      const response = await fetch('/ai-chat/history', {
        method: 'GET',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });
      
      if (response.ok) {
        const data = await response.json();
        if (data.history && data.history.length > 0) {
          data.history.forEach(msg => {
            addMsg(msg.role, msg.content, msg.timestamp);
          });
          return true;
        }
      }
    } catch (error) {
      console.warn('Could not load chat history:', error);
    }
    return false;
  };

  const fmt = c => {
    let result = c.replace(/【[^】]*】/g, '')
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/`(.*?)`/g, '<code>$1</code>')
      .replace(/\n/g, '<br>')
      .trim();

    // Make URLs clickable
    // URLs mit http/https, ohne nachfolgende Satzzeichen
    result = result.replace(
      /(https?:\/\/[^\s\)\]\}\>,!?:;"]+)([.,!?:;)\]]?)/g,
      '<a href="$1" target="_blank">$1</a>$2'
    );
    // URLs mit www., ohne nachfolgende Satzzeichen
    result = result.replace(
      /(?<!\/)(www\.[^\s\)\]\}\>,!?:;"]+)([.,!?:;)\]]?)/g,
      '<a href="https://$1" target="_blank">$1</a>$2'
    );

    // Make phone numbers clickable, keeping optional "+" at the start
    result = result.replace(/(\+?[\d\s\(\)\-]{7,})/g, match => {
      // Prüfe, ob die Nummer mit "+" beginnt
      const hasPlus = match.trim().startsWith('+');
      // Extrahiere nur Ziffern (und ggf. das Plus)
      let telLink = match.replace(/\D/g, '');
      if (hasPlus) {
        telLink = '+' + telLink;
      }
      // Nur als Link, wenn mindestens 7 Ziffern vorhanden sind
      if (telLink.replace(/\D/g, '').length < 7) return match;
      return `<a href="tel:${telLink}">${match}</a>`;
    });

    // Make email addresses clickable
    result = result.replace(/([\w.-]+@[\w.-]+\.\w+)/g, '<a href="mailto:$1">$1</a>');

    return result;
  };

  const ts = t => new Date(t).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});

  const addMsg = (role, c, t = null) => {
    const row = document.createElement('div');
    row.className = `ai-chat-message ai-chat-${role}`;
    row.innerHTML = `<div class="ai-chat-bubble">${fmt(c)}${t ? `<span class='ai-chat-timestamp'>${ts(t)}</span>` : ''}</div>`;
    log.appendChild(row);
    log.scrollTo({top: log.scrollHeight, behavior: 'smooth'});
    log.setAttribute('aria-live', 'polite');
    log.setAttribute('aria-atomic', 'false');
  };

  const typing = () => {
    const r = document.createElement('div');
    r.className = 'ai-chat-message ai-chat-assistant ai-chat-typing';
    r.innerHTML = '<div class="ai-chat-bubble"><div class="ai-chat-typing-indicator"><span></span><span></span><span></span></div></div>';
    log.appendChild(r);
    log.scrollTo({top: log.scrollHeight, behavior: 'smooth'});
    return r;
  };

  // Form submission
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const msg = input.value.trim();
    if (!msg || inFlight) return;
    
    inFlight = true;
    input.disabled = true;
    if (sendBtn) sendBtn.setAttribute('disabled', '');
    
    addMsg('user', msg, new Date().toISOString());
    input.value = '';
    const tRow = typing();
    
    try {
      let csrfToken = await ensureValidToken();
      const formDataString = `message=${encodeURIComponent(msg)}&REQUEST_TOKEN=${encodeURIComponent(csrfToken)}&language=${encodeURIComponent(userLanguage)}`;
      
      const r = await fetch(chatEndpoint, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        credentials: 'same-origin',
        body: formDataString
      });
      
      let data;
      try {
        const responseText = await r.text();
        data = JSON.parse(responseText);
      } catch (e) {
        console.error('Failed to parse send response:', e);
        throw new Error('Invalid JSON response');
      }
      
      tRow.remove();
      
      if (r.status === 403 && (data.error && (data.error.includes('CSRF') || data.error.includes('Invalid CSRF token')))) {
        const newToken = await refreshToken();
        if (newToken) {
          const retryFormDataString = `message=${encodeURIComponent(msg)}&REQUEST_TOKEN=${encodeURIComponent(newToken)}&language=${encodeURIComponent(userLanguage)}`;
          
          const retryResponse = await fetch(chatEndpoint, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json',
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: retryFormDataString
          });
          
          let retryData;
          try {
            const retryResponseText = await retryResponse.text();
            retryData = JSON.parse(retryResponseText);
          } catch (e) {
            console.error('Failed to parse retry response:', e);
            throw new Error('Invalid JSON response on retry');
          }
          
          if (retryResponse.ok && retryData.reply) {
            addMsg('assistant', retryData.reply, retryData.timestamp);
          } else {
            addMsg('assistant', retryData.error || 'Es ist ein Fehler aufgetreten. Bitte erneut versuchen.');
          }
        } else {
          addMsg('assistant', 'Bitte lade die Seite neu und versuche es erneut.');
        }
      } else if (r.ok && data.reply) {
        addMsg('assistant', data.reply, data.timestamp);
      } else {
        addMsg('assistant', data.error || 'Es ist ein Fehler aufgetreten. Bitte erneut versuchen.');
      }
    } catch (e) {
      console.error('Chat error:', e);
      tRow.remove();
      addMsg('assistant', 'Es ist ein Fehler aufgetreten. Bitte erneut versuchen.');
    } finally {
      input.disabled = false;
      if (sendBtn) sendBtn.removeAttribute('disabled');
      inFlight = false;
    }
  });

  // Input handling
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });

  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (sendBtn) sendBtn.click();
    }
  });

  // Load initial content
  loadHist().then(historyLoaded => {
    if (!historyLoaded) {
      welcome();
    }
  }).catch(error => {
    console.warn('Could not load chat history:', error);
    welcome();
  });
}

  // Initialize all AI chat modules when DOM is ready
  document.addEventListener('DOMContentLoaded', () => {
    const chatModules = document.querySelectorAll('.mod_ai_chat');
    chatModules.forEach(module => {
      initAiChat(module);
    });
  });
  
  // Handle viewport height changes (for mobile browsers)
  window.addEventListener('resize', appHeight);
  window.addEventListener('orientationchange', appHeight);
