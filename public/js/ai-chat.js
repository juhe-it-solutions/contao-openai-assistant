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

  // i18n: read from data-i18n (JSON from server), fallback to German for missing keys
  const i18n = (() => {
    try {
      const raw = wrapper.dataset.i18n;
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') return parsed;
      }
    } catch (e) {
      console.warn('AI Chat: Could not parse i18n data', e);
    }
    return {
      ai_chat_open: 'AI Chat öffnen',
      initial_message_fallback: 'Hallo! Wie kann ich dir helfen?',
      error_generic: 'Es ist ein Fehler aufgetreten. Bitte erneut versuchen.',
      error_reload_page: 'Bitte lade die Seite neu und versuche es erneut.',
      link_label_download: 'Download',
      link_label_page: 'Seite aufrufen'
    };
  })();

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
    toggleButton.setAttribute('aria-label', i18n.ai_chat_open || 'AI Chat öffnen');
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
    // Auto-focus input when expanding the chat (both desktop and mobile)
    if (input) {
      // Small delay to ensure DOM transition is complete
      setTimeout(() => {
        input.focus();
      }, 100);
    }
  };

  // Initialize chat state based on configuration
  // On mobile devices (width < 768px), always start collapsed for better UX
  const isMobile = window.innerWidth < 768;
  if (config.initialState === 'expanded' && !isMobile) {
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

  // Disclaimer functionality
  const disclaimerToggleBtn = wrapper.querySelector('.ai-chat-disclaimer-toggle');
  const disclaimerDialog = wrapper.querySelector('.ai-chat-disclaimer-dialog');
  const disclaimerCloseBtn = wrapper.querySelector('.ai-chat-disclaimer-close');

  const showDisclaimer = () => {
    if (disclaimerDialog) {
      disclaimerDialog.classList.add('show');
      disclaimerDialog.setAttribute('aria-hidden', 'false');
      // Update aria-expanded state
      if (disclaimerToggleBtn) {
        disclaimerToggleBtn.setAttribute('aria-expanded', 'true');
      }
      // Focus the close button for accessibility
      if (disclaimerCloseBtn) {
        disclaimerCloseBtn.focus();
      }
      // Prevent body scroll when dialog is open
      document.body.style.overflow = 'hidden';
    }
  };

  const hideDisclaimer = () => {
    if (disclaimerDialog) {
      disclaimerDialog.classList.remove('show');
      disclaimerDialog.setAttribute('aria-hidden', 'true');
      // Update aria-expanded state
      if (disclaimerToggleBtn) {
        disclaimerToggleBtn.setAttribute('aria-expanded', 'false');
      }
      // Restore body scroll
      document.body.style.overflow = '';
      // Return focus to the disclaimer toggle button
      if (disclaimerToggleBtn) {
        disclaimerToggleBtn.focus();
      }
    }
  };

  // Event listeners for disclaimer
  if (disclaimerToggleBtn) {
    disclaimerToggleBtn.addEventListener('click', showDisclaimer);
  }

  if (disclaimerCloseBtn) {
    disclaimerCloseBtn.addEventListener('click', hideDisclaimer);
  }

  // Close dialog when clicking outside
  if (disclaimerDialog) {
    disclaimerDialog.addEventListener('click', (e) => {
      if (e.target === disclaimerDialog) {
        hideDisclaimer();
      }
    });
  }

  // Close dialog with Escape key and handle focus trap
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && disclaimerDialog && disclaimerDialog.classList.contains('show')) {
      hideDisclaimer();
    }
    
    // Focus trap for dialog
    if (disclaimerDialog && disclaimerDialog.classList.contains('show') && e.key === 'Tab') {
      const focusableElements = disclaimerDialog.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      
      if (e.shiftKey) {
        // Shift + Tab
        if (document.activeElement === firstElement) {
          e.preventDefault();
          lastElement.focus();
        }
      } else {
        // Tab
        if (document.activeElement === lastElement) {
          e.preventDefault();
          firstElement.focus();
        }
      }
    }
  });

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
    const initialMessage = wrapper.dataset.initialMessage || i18n.initial_message_fallback || 'Hello! How can I help you?';
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

  // Escape HTML metacharacters so raw markup in a message (including model /
  // knowledge-base output) is rendered as text, never executed. Runs BEFORE the
  // markdown/link transforms below, which then re-introduce only the tags we
  // generate ourselves and therefore match &lt;/&gt; where they mean a literal
  // bracket. Ampersand first so we don't double-escape our own entities. Quotes
  // stay literal: without "<" no tag or attribute context can open, and every URL
  // pattern below already excludes them, so their handling is unchanged.
  const escapeHtml = s => s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  // Shorten plain URLs (module option "shorten_urls", default ON): plain URLs
  // are rendered as a short localized label instead of the full URL. The full
  // URL stays in href and title; Markdown links keep their model-provided text
  // (buildMarkdownLink is untouched). Missing attribute (e.g. cached template
  // without data-shorten-urls) defaults to ON, matching the DCA default.
  const shortenPlainUrls = wrapper.dataset.shortenUrls !== '0';
  const linkLabelDownload = i18n.link_label_download || 'Download';
  const linkLabelPage = i18n.link_label_page || 'Seite aufrufen';
  // File extensions labeled "Download" (checked against the URL path only,
  // query/fragment stripped). Everything else gets the "visit page" label.
  const downloadExtensionsRe = /\.(pdf|zip|rar|7z|tar|gz|tgz|doc|docx|xls|xlsx|ppt|pptx|pps|ppsx|csv|txt|rtf|odt|ods|odp|epub|ics|vcf|mp3|m4a|wav|mp4|mov|avi|webm|jpg|jpeg|png|gif|svg|webp)$/i;
  const isDownloadUrl = (url) => downloadExtensionsRe.test(url.split(/[?#]/)[0]);
  // Hostname for the aria-label so screen-reader users know the link target
  // domain even though the visible text is only the generic label. Userinfo
  // ("user:pass@") is dropped - credentials must never reach the aria-label.
  const hostnameOf = (url) => {
    const m = url.match(/^(?:https?:\/\/)?(?:[^\/?#@]*@)?([^\/?#]+)/i);
    return m ? m[1] : url;
  };
  // URL for tooltip/title display: same as the href but without userinfo, so
  // credentials embedded in a URL are not shown on hover or read by a screen
  // reader. The href itself stays verbatim (the link must still work).
  const displayUrlOf = (url) => url.replace(/^((?:https?:\/\/)?)[^\/?#@]*@/i, '$1');

  const fmt = c => {
    // Emphasis/code delimiters must start at a word boundary (start of line,
    // whitespace or an opening bracket/quote). Without this, a single "*" or
    // "`" INSIDE a URL (e.g. "?flags=x!y$z*w") pairs up with one in a second
    // URL later in the message and the <em>/<code> tags shred the URL - and
    // any Markdown link around it - before the link transforms run. No
    // lookbehind (Safari/iOS < 16.4): the boundary char is captured and
    // re-emitted. Content stays line-local ([^*\n]) like the old ".*?".
    let result = escapeHtml(c)
      // Complete 【...】 pairs are citation markers from OpenAI file search
      // ("【4:0†source】") and are stripped - UNLESS the content is a URL:
      // models also wrap URLs in the same brackets, and deleting those loses
      // the answer's link. URL-like content is unwrapped to ASCII brackets so
      // the bracket-peeling and anchor-cleanup rules below apply to it.
      .replace(/【([^】]*)】/g, (match, inner, offset, str) => {
        if (!/https?:\/\/|www\./i.test(inner)) return '';
        // If text follows the closing bracket with no separator ("【url】mehr"),
        // add a space: otherwise the bare-URL pass would swallow "]mehr" into
        // the href. Peel-handled punctuation and token-terminating chars after
        // the bracket need no space.
        const next = str[offset + match.length] || '';
        const sep = next && !/[\s.,!?;:…)\]}<>"']/.test(next) ? ' ' : '';
        return `[${inner}]${sep}`;
      })
      // Lone CJK brackets left over after citation stripping: models mix
      // bracket styles when wrapping URLs (observed live: "[<url>】." - ASCII
      // "[" opened, U+3011 "】" closed). Mapped to their ASCII counterparts so
      // every bracket-peeling and wrapper-cleanup rule below applies to them.
      .replace(/【/g, '[')
      .replace(/】/g, ']')
      .replace(/(^|[\s([{"'])\*\*([^*\n]+?)\*\*/gm, '$1<strong>$2</strong>')
      .replace(/(^|[\s([{"'])\*([^*\n]+?)\*/gm, '$1<em>$2</em>')
      .replace(/(^|[\s([{"'])`([^`\n]+?)`/gm, '$1<code>$2</code>')
      .replace(/\n/g, '<br>')
      .trim();

    const replaceOutsideAnchors = (html, replacer) => html
      .split(/(<a\b[^>]*>.*?<\/a>)/gi)
      .map(part => /^<a\b/i.test(part) ? part : replacer(part))
      .join('');
    // Strip literal brackets AND their escaped forms - the input is entity-escaped,
    // so a bracket smuggled into a URL arrives as &lt;/&gt;.
    const sanitizeUrl = (url) => (url || '').replace(/[<>]/g, '').replace(/&(?:lt|gt);/gi, '');
    const countChar = (value, char) => value.split(char).length - 1;
    const splitTrailingUrlPunctuation = (url) => {
      let clean = url;
      let trailing = '';
      // Sentence punctuation, plus Unicode ellipsis and fullwidth CJK
      // punctuation - models decorate URLs with these and they are never a
      // legitimate URL tail.
      while (/[.,!?;:…。、！？：；，]+$/.test(clean)) {
        trailing = clean.slice(-1) + trailing;
        clean = clean.slice(0, -1);
      }

      // Balance-checked closers: ASCII plus fullwidth/CJK counterparts
      // (corner brackets, fullwidth parens/brackets) so decorative wrappers
      // like 「url」 or （url） do not leak into the href. Balanced pairs
      // inside the URL (wiki-style "Function_(mathematics)") stay untouched.
      const pairs = {')': '(', ']': '[', '}': '{', '）': '（', '」': '「', '』': '『', '］': '［'};
      while (/[)\]}）」』］]$/.test(clean)) {
        const close = clean.slice(-1);
        if (countChar(clean, close) <= countChar(clean, pairs[close])) break;
        trailing = close + trailing;
        clean = clean.slice(0, -1);
      }

      return {clean, trailing};
    };
    const buildExternalLink = (url, hrefPrefix = '') => {
      const {clean, trailing} = splitTrailingUrlPunctuation(sanitizeUrl(url));
      if (!clean) return url;
      if (shortenPlainUrls) {
        // Attribute values are safe unquoted-free: escapeHtml ran already and
        // every URL pattern excludes double quotes, so `clean` cannot break out
        // of the double-quoted attributes. title keeps &amp; entities - the
        // browser decodes them for display; the href post-processing step below
        // restores the literal & in href only.
        const label = isDownloadUrl(clean) ? linkLabelDownload : linkLabelPage;
        return `<a href="${hrefPrefix}${clean}" target="_blank" rel="noopener" title="${hrefPrefix}${displayUrlOf(clean)}" aria-label="${label}, ${hostnameOf(clean)}">${label}</a>${trailing}`;
      }
      return `<a href="${hrefPrefix}${clean}" target="_blank" rel="noopener">${clean}</a>${trailing}`;
    };
    const buildMarkdownLink = (text, url) => {
      // Peel stray trailing "]"/")" and sentence punctuation from the explicit
      // destination and DROP them: unlike a bare URL there is no surrounding
      // sentence they could belong to - inside "(...)" they are model
      // artifacts (observed: "[Download](<url>])"). Balanced brackets, e.g.
      // ".../Function_(mathematics)", are untouched by the balance check.
      const {clean} = splitTrailingUrlPunctuation(sanitizeUrl(url));
      if (!clean) return `[${text}](${url})`;

      const hrefPrefix = clean.toLowerCase().startsWith('www.') ? 'https://' : '';
      // Models often echo the URL itself as the link text ("[<url>](<url>)",
      // e.g. when the vector-store document stores links that way). A URL used
      // as its own label carries no information a shortened label wouldn't, so
      // the shorten_urls option applies here too - real descriptive text is
      // always kept as-is. Leading "[" in the text (from "[[label](url)]"
      // double-wraps) is ignored for the URL-likeness test; the anchor-label
      // cleanup pass below strips it from the rendered label.
      if (shortenPlainUrls
          && /^(?:https?:\/\/|www\.)/i.test(clean)
          && /^(?:https?:\/\/|www\.)\S+$/i.test(text.replace(/^\[+/, '').trim())) {
        const label = isDownloadUrl(clean) ? linkLabelDownload : linkLabelPage;
        return `<a href="${hrefPrefix}${clean}" target="_blank" rel="noopener" title="${hrefPrefix}${displayUrlOf(clean)}" aria-label="${label}, ${hostnameOf(clean)}">${label}</a>`;
      }
      // Descriptive labels hide the destination, so expose the full URL as a
      // hover tooltip - same as the shortened rendering. Only for http/www
      // destinations: a tel:/mailto: tooltip adds nothing (and displayUrlOf
      // would mangle a mailto address, whose "userinfo" IS the address).
      const titleAttr = /^(?:https?:\/\/|www\.)/i.test(clean)
        ? ` title="${hrefPrefix}${displayUrlOf(clean)}"`
        : '';
      return `<a href="${hrefPrefix}${clean}" target="_blank" rel="noopener"${titleAttr}>${text}</a>`;
    };

    // Render explicit Markdown links before bare URL autolinking.
    // Supports [text](url), [text](<url>), optional titles, and balanced parentheses in URLs.
    // The input is entity-escaped, so literal angle brackets appear as &lt;/&gt; -
    // the angled-destination form matches those, and the (?!&lt;|&gt;) tempering makes
    // an escaped bracket terminate a URL exactly like the literal bracket used to.
    result = replaceOutsideAnchors(result, text => text.replace(
      /(^|[^!])\[([^\]]+)\]\(\s*(?:&lt;((?:https?:\/\/|www\.|mailto:|tel:)(?:(?!&lt;|&gt;)[^<>\s])+)&gt;|((?:https?:\/\/|www\.|mailto:|tel:)(?:(?!&lt;|&gt;)[^\s()<>"']|\([^\s()]*\))+))\s*(?:"[^"]*"|'[^']*'|\([^)]*\))?\s*\)/gi,
      (_, prefix, linkText, angledUrl, plainUrl) => prefix + buildMarkdownLink(linkText, angledUrl || plainUrl)
    ));

    // URLs wrapped in angle brackets, e.g. <https://example.com> (escaped to
    // &lt;https://example.com&gt; before this runs).
    result = replaceOutsideAnchors(result, text => text.replace(
      /&lt;((?:https?:\/\/|www\.)(?:(?!&lt;|&gt;)[^<>\s])+)&gt;/gi,
      (_, url) => buildExternalLink(url, url.toLowerCase().startsWith('www.') ? 'https://' : '')
    ));

    // Explicit mailto/tel links before generic email/phone autolinking.
    result = replaceOutsideAnchors(result, text => text.replace(
      /(^|[^\w"=])(mailto:(?:(?!&lt;|&gt;)[^\s<>"'])+@(?:(?!&lt;|&gt;)[^\s<>"'])+)/gi,
      (_, prefix, href) => `${prefix}<a href="${sanitizeUrl(href)}">${sanitizeUrl(href.replace(/^mailto:/i, ''))}</a>`
    ));
    result = replaceOutsideAnchors(result, text => text.replace(
      /(^|[^\w"=])(tel:\+?[\d\s().-]{7,})/gi,
      (_, prefix, href) => `${prefix}<a href="${sanitizeUrl(href.replace(/\s/g, ''))}">${sanitizeUrl(href.replace(/^tel:/i, ''))}</a>`
    ));

    // Remove square brackets around links. (An earlier "dedup identical
    // anchors" pass here also swallowed LEGITIMATE repeated links - two
    // [A](same-url) links render byte-identical - so it was removed; the
    // malformed-Markdown cleanup below handles accidental double emits.)
    result = result.replace(/\[<a[^>]*>.*?<\/a>\]/g, (match) => {
      return match.replace(/^\[|\]$/g, ''); // Remove [ and ]
    });

    // Make URLs clickable (only if they're not already in <a> tags).
    // Keep query strings/fragments intact, then peel off sentence punctuation.
    // Models sometimes break long URLs with a newline at ?, &, /, = or #.
    // After \n→<br> those become <br> inside the URL; allow them at those breakpoints
    // and strip them from the href before building the link.
    // No lookbehind here on purpose: (?<=...) is a PARSE-time syntax error on
    // Safari/iOS < 16.4 and would kill this whole script. The breakpoint char and
    // its <br> are consumed together instead - same accepted language.
    // URLs mit http/https. (?!&lt;|&gt;) stops the URL at an escaped bracket, the
    // same place the literal bracket in [^<>] used to stop it before escaping.
    // The breakpoint alternative also accepts &amp; - the input is entity-escaped,
    // so a literal & before a model-inserted newline arrives as "&amp;<br>".
    // The (?!\]\() tempering stops a bare URL at a "](" boundary: when a
    // Markdown link failed to parse (e.g. newline inside the destination),
    // the text and destination URLs must become two separate anchors instead
    // of one giant "url1](url2" href; the malformed-Markdown cleanup below
    // then merges the leftover wrapper. Lone "["/"]" still pass through
    // (unresolved Contao basic entities like "[&]" in old vector stores).
    result = replaceOutsideAnchors(result, text => text.replace(
      /https?:\/\/(?:(?:[?&\/=#]|&amp;)<br\s*\/?>|(?!&lt;|&gt;|\]\()[^\s<>"'])+/g,
      url => buildExternalLink(url.replace(/<br\s*\/?>/gi, ''))
    ));
    // URLs mit www.
    result = replaceOutsideAnchors(result, text => text.replace(
      /(^|[^\w/])((?:www\.)(?:(?:[?&\/=#]|&amp;)<br\s*\/?>|(?!&lt;|&gt;|\]\()[^\s<>"'])+)/g,
      (_, prefix, url) => prefix + buildExternalLink(url.replace(/<br\s*\/?>/gi, ''), 'https://')
    ));

    // Malformed-Markdown cleanup. Models echo vector-store links in broken
    // shapes ("[url](url)" with a newline-wrapped destination, "[[label](url)]"
    // double-wraps, reference-style "[label][url]"). After the bare-URL pass
    // those leave Markdown wrapper syntax around finished anchors or garbage
    // brackets inside anchor labels - normalize them to one clean anchor.
    const isUrlLike = (s) => /^(?:https?:\/\/|www\.)/i.test(s.trim());
    const relabel = (anchor, label) => isUrlLike(label)
      ? anchor
      : anchor.replace(/>[^]*?<\/a>$/, `>${label}</a>`);
    // "[<a>..</a>](<a>..</a>)" (both halves of a failed Markdown link got
    // autolinked separately): keep the destination anchor.
    result = result.replace(/\[<a\b[^>]*>[^]*?<\/a>\]\((<a\b[^>]*>[^]*?<\/a>)\)/g, '$1');
    // "[label](<a>..</a>)" and reference-style "[label][<a>..</a>]": the
    // destination anchor wins, descriptive label text becomes its label.
    result = result.replace(/\[([^\][<>]+)\]\((<a\b[^>]*>[^]*?<\/a>)\)/g, (_, label, anchor) => relabel(anchor, label));
    result = result.replace(/\[([^\][<>]+)\]\[(<a\b[^>]*>[^]*?<\/a>)\]/g, (_, label, anchor) => relabel(anchor, label));
    // Square brackets wrapping an anchor (also covers ones formed by the
    // bare-URL pass, which runs after the early bracket-strip step).
    result = result.replace(/\[(<a\b[^>]*>[^]*?<\/a>)\]/g, '$1');
    // Stray "[" at the start of an anchor label ("[[Download](url)]" leftovers):
    // drop it together with a "]" right after the anchor, or alone.
    result = result.replace(/(<a\b[^>]*>)\[([^<]*<\/a>)\]/g, '$1$2');
    result = result.replace(/(<a\b[^>]*>)\[([^<\]]*<\/a>)/g, '$1$2');

    // Make phone numbers clickable, keeping optional "+" at the start.
    // Bare digit runs also match invoice numbers, ISBNs, SKUs and datetimes,
    // so a number only autolinks when it starts with "+" (international
    // format) OR a phone keyword appears shortly before it ("Tel: 030 ...",
    // "Rufen Sie an: ..."). Context is checked by slicing the string before
    // the match - no lookbehind (parse error on Safari/iOS < 16.4).
    const phoneCueRe = /(?:^|[^a-zäöüß])(?:tel(?:efon)?|phone|fax|call|hotline|mobil(?:e)?|handy|whatsapp|anruf(?:en)?|ruf(?:en)?|durchwahl)[^\d]{0,30}$/i;
    result = replaceOutsideAnchors(result, text => text.replace(/(\+?[\d\s\(\)\-]{7,})/g, (match, _p1, offset) => {
      // Prüfe, ob die Nummer mit "+" beginnt
      const hasPlus = match.trim().startsWith('+');
      if (!hasPlus && !phoneCueRe.test(text.slice(0, offset))) return match;
      // Extrahiere nur Ziffern (und ggf. das Plus)
      let telLink = match.replace(/\D/g, '');
      if (hasPlus) {
        telLink = '+' + telLink;
      }
      // Nur als Link, wenn mindestens 7 Ziffern vorhanden sind
      if (telLink.replace(/\D/g, '').length < 7) return match;
      return `<a href="tel:${telLink}">${match}</a>`;
    }));

    // Make email addresses clickable
    result = replaceOutsideAnchors(result, text => text.replace(/([\w.-]+@[\w.-]+\.\w+)/g, '<a href="mailto:$1">$1</a>'));

    // Sanitize href: strip literal and escaped angle brackets, then decode &amp;
    // back to & so the attribute carries the exact original URL. Decoding only &amp;
    // (never &lt;/&gt;) is safe inside a double-quoted attribute and simply inverts
    // the one escapeHtml pass for the characters a URL legitimately contains.
    result = result.replace(/href="([^"]*)"/g, (_, val) => 'href="'
      + (val || '').replace(/[<>]/g, '').replace(/&(?:lt|gt);/gi, '').replace(/&amp;/gi, '&')
      + '"');

    // Remove stray ">" immediately after </a> (e.g. from "https://example.com>" or
    // angle-bracket notation like <https://example.com>). The bracket arrives
    // entity-escaped now, so match both forms.
    result = result.replace(/<\/a>(?:&gt;|>)/g, '</a>');

    // Remove exclamation mark + dot combinations
    result = result.replace(/!\./g, '!');

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

    // Abort the request client-side after 2 minutes so the visitor gets a
    // friendly message instead of a spinner that runs until a proxy gives up.
    const abortCtrl = new AbortController();
    const abortTimer = setTimeout(() => abortCtrl.abort(), 120000);

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
        body: formDataString,
        signal: abortCtrl.signal
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
            body: retryFormDataString,
            signal: abortCtrl.signal
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
            addMsg('assistant', retryData.error || i18n.error_generic);
          }
        } else {
          addMsg('assistant', i18n.error_reload_page);
        }
      } else if (r.ok && data.reply) {
        addMsg('assistant', data.reply, data.timestamp);
        // Auto-focus input after bot response (both desktop and mobile)
        setTimeout(() => {
          if (input && !input.disabled) {
            input.focus();
          }
        }, 100);
      } else {
        addMsg('assistant', data.error || i18n.error_generic);
        // Auto-focus input after error response
        setTimeout(() => {
          if (input && !input.disabled) {
            input.focus();
          }
        }, 100);
      }
    } catch (e) {
      console.error('Chat error:', e);
      tRow.remove();
      addMsg('assistant', e.name === 'AbortError'
        ? (i18n.error_timeout || i18n.error_generic)
        : i18n.error_generic);
      // Auto-focus input after error
      setTimeout(() => {
        if (input && !input.disabled) {
          input.focus();
        }
      }, 100);
    } finally {
      clearTimeout(abortTimer);
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
  
  // Handle responsive behavior for chat state
  let lastWindowWidth = window.innerWidth;
  window.addEventListener('resize', () => {
    const currentWidth = window.innerWidth;
    const wasDesktop = lastWindowWidth >= 768;
    const isMobile = currentWidth < 768;
    
    // If transitioning from desktop to mobile and chat is expanded, collapse it
    if (wasDesktop && isMobile && !wrapper.classList.contains('ai-chat-collapsed')) {
      collapse();
    }
    
    lastWindowWidth = currentWidth;
  });
}

  // Initialize all AI chat modules — guard against double-init on Turbo navigation.
  function initAllAiChats() {
    document.querySelectorAll('.mod_ai_chat:not([data-ai-chat-init])').forEach(function (module) {
      module.setAttribute('data-ai-chat-init', '1');
      initAiChat(module);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllAiChats);
  } else {
    initAllAiChats();
  }

  // Re-init after Turbo-driven page transitions (Contao 5.7+).
  document.addEventListener('turbo:load', initAllAiChats);
  
  // Handle viewport height changes (for mobile browsers)
  window.addEventListener('resize', appHeight);
  window.addEventListener('orientationchange', appHeight);
