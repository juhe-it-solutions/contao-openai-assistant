# Frontend strings to translate (currently hardcoded)

All user-visible frontend strings that are hardcoded and should be translated for i18n. No file changes were made; this is a reference only.

---

## 1. Template: `contao/templates/frontend_module/ai_chat_module.html.twig`

| Line(s) | Context | Hardcoded string (DE) | Notes |
|---------|---------|------------------------|--------|
| 12 | `data-initial-message` default | `Hallo! Wie kann ich dir helfen?` | Fallback when `initial_bot_message` not set |
| 12 | `role` / `aria-label` | `AI Chat Module` | Region label for assistive tech |
| 24 | `{{ chat_title\|default(...) }}` | `Assistent - JUHE IT-solutions.` | Default chat header title |
| 25 | `{{ welcome_message\|default(...) }}` | `Wie kann ich dir helfen?` | Default subheadline |
| 28 | `aria-label` | `Disclaimer anzeigen` | Disclaimer toggle button |
| 31 | `title` | `Disclaimer` | Disclaimer toggle tooltip |
| 38 | `aria-label` | `Theme wechseln` | Theme toggle button |
| 39 | `title` | `Theme wechseln` | Theme toggle tooltip |
| 46 | `aria-label` | `Chat minimieren` | Minimize button |
| 68 | `placeholder` | `Frage hier eingeben...` | Textarea placeholder |
| 73 | `aria-label` | `Frage eingeben` | Textarea label |
| 76 | `title` | `Frage abschicken` | Send button tooltip |
| 79 | `aria-label` | `Frage abschicken` | Send button label |
| 93 | Heading text | `Disclaimer` | Dialog title (often kept as "Disclaimer") |
| 94 | `aria-label` | `Dialog schließen` | Close dialog button |

---

## 2. JavaScript: `public/js/ai-chat.js`

| Line(s) | Context | Hardcoded string (DE) | Notes |
|---------|---------|------------------------|--------|
| 67 | `setAttribute('aria-label', …)` | `AI Chat öffnen` | Toggle button (when created in JS) |
| 352 | `wrapper.dataset.initialMessage \|\| …` | `Hallo! Wie kann ich dir helfen?` | Fallback initial bot message in `welcome()` |
| 530 | `addMsg('assistant', …)` | `Es ist ein Fehler aufgetreten. Bitte erneut versuchen.` | Shown when retry after CSRF fails |
| 533 | `addMsg('assistant', …)` | `Bitte lade die Seite neu und versuche es erneut.` | Shown when no new token on retry |
| 544 | `addMsg('assistant', data.error \|\| …)` | `Es ist ein Fehler aufgetreten. Bitte erneut versuchen.` | Fallback when API returns error without message |
| 555 | `addMsg('assistant', …)` | `Es ist ein Fehler aufgetreten. Bitte erneut versuchen.` | Shown on catch (network/parse error) |

---

## 3. Defaults set in PHP (used when module has no value)

These defaults are passed to the template from `src/Controller/FrontendModule/AiChatModuleController.php`. They are effectively frontend defaults; translating them requires either translating in the controller (e.g. via Contao language files) or moving them into the template with translation.

| Line | Variable | Hardcoded default (DE) |
|------|----------|-------------------------|
| 53 | `chat_title` | `Chat-Header-Titel` |
| 54 | `welcome_message` | `Wie kann ich dir helfen?` |
| 55 | `initial_bot_message` | `Hallo! Wie kann ich dir helfen?` |
| 62 | `default_disclaimer_text` | Long disclaimer paragraph (from `tl_module` lang file or inline default in PHP) |

---

## Summary

- **Template:** 14 distinct strings (placeholders, aria-labels, titles, defaults).
- **ai-chat.js:** 4 distinct user-facing strings (1 aria-label, 1 welcome fallback, 3 error messages).
- **Controller defaults:** 3 short strings + 1 long disclaimer; consider feeding them from a language file or the same i18n mechanism as the template/JS.

Recommended approach: introduce a single source of translations (e.g. Contao lang files or a small JSON/JS map keyed by `navigator.language`) and replace these hardcoded strings with lookups. Template strings can be passed from the controller or from a Twig extension; JS strings can be provided via a small inline script or data attributes from the template.

---

## Detailed task list

### A. Translation source and wiring

- [x] **A1** Define a frontend language file (e.g. `contao/languages/en/mod_ai_chat.php` and `.../de/...`) or extend an existing one with keys for all chat UI strings (see sections 1–3). Include at least `de` and `en`; add keys for every string in the tables above.
- [x] **A2** In `AiChatModuleController`, detect frontend language (e.g. from request locale or `Accept-Language`) and load the corresponding language file (or use Contao’s page language). Resolve all default copy (chat_title, welcome_message, initial_bot_message, default_disclaimer_text) via this source instead of hardcoding.
- [x] **A3** Build a JS-accessible translation map for strings that only the script needs (e.g. `ai_chat_open`, `initial_message_fallback`, `error_generic`, `error_reload_page`). Either inject a `<script type="application/json">` with the map for the current language from the controller, or output data attributes on the chat root element and read them in `ai-chat.js`.

### B. Template: `contao/templates/frontend_module/ai_chat_module.html.twig`

- [x] **B1** Replace the `data-initial-message` default (line 12) with a variable passed from the controller (e.g. `{{ initial_bot_message|default(initial_bot_message_default)|escape('html_attr') }}` where the default comes from the lang file).
- [x] **B2** Replace the region `aria-label` (line 12) with a translated string from the controller (e.g. `aria_label_region`).
- [x] **B3** Replace `chat_title|default('Assistent - JUHE IT-solutions.')` (line 24) with a default that comes from the controller (already set in controller; ensure controller uses translated default).
- [x] **B4** Replace `welcome_message|default('Wie kann ich dir helfen?')` (line 25) with a controller-provided translated default.
- [x] **B5** Replace disclaimer toggle `aria-label` and `title` (lines 28, 31) with variables (e.g. `{{ aria_label_disclaimer }}`, `{{ title_disclaimer }}`).
- [x] **B6** Replace theme toggle `aria-label` and `title` (lines 38, 39) with variables (e.g. `{{ aria_label_theme }}`, `{{ title_theme }}`).
- [x] **B7** Replace minimize button `aria-label` (line 46) with a variable (e.g. `{{ aria_label_minimize }}`).
- [x] **B8** Replace textarea `placeholder` (line 68) with a variable (e.g. `{{ placeholder_message }}`).
- [x] **B9** Replace textarea `aria-label` (line 73) with a variable (e.g. `{{ aria_label_message }}`).
- [x] **B10** Replace send button `title` and `aria-label` (lines 76, 79) with variables (e.g. `{{ title_send }}`, `{{ aria_label_send }}`).
- [x] **B11** Replace disclaimer dialog heading (line 93) with a variable (e.g. `{{ disclaimer_title }}`) so it can be translated.
- [x] **B12** Replace close button `aria-label` (line 94) with a variable (e.g. `{{ aria_label_close_dialog }}`).

### C. Controller: `src/Controller/FrontendModule/AiChatModuleController.php`

- [x] **C1** Load the frontend chat language file (or the relevant subset) for the current frontend language (see A2).
- [x] **C2** Replace hardcoded `chat_title` default (line 53) with a value from the language file (e.g. `$this->getTranslated('chat_title', 'Chat-Header-Titel')` or equivalent).
- [x] **C3** Replace hardcoded `welcome_message` default (line 54) with a value from the language file.
- [x] **C4** Replace hardcoded `initial_bot_message` default (line 55) with a value from the language file.
- [x] **C5** Ensure `default_disclaimer_text` (line 62) is taken from the loaded language file for the current language (already uses `tl_module`; ensure that file has per-language entries or add chat-specific lang keys).
- [x] **C6** Pass all new template variables required by B2 and B5–B12 (aria-labels, titles, placeholder, disclaimer title) from the controller, resolving each from the same language file.
- [x] **C7** (If using inline script for JS i18n) Output a script tag or data attribute that exposes the JS translation map (see A3) so the template can render it (e.g. `data-i18n='{{ i18n_json|raw }}'` or inline `<script type="application/json" id="ai-chat-i18n">...</script>`).

### D. JavaScript: `public/js/ai-chat.js`

- [x] **D1** Read the JS translation map once at init (from `data-i18n` on the wrapper, or from `document.getElementById('ai-chat-i18n').textContent`, or from data attributes per string). Use a fallback to the current German strings if a key is missing.
- [x] **D2** Replace the hardcoded `'AI Chat öffnen'` aria-label (line 67) with the value from the translation map (e.g. `i18n.ai_chat_open`).
- [x] **D3** Replace the fallback in `welcome()` (line 352) with the value from the translation map (e.g. `i18n.initial_message_fallback`); keep using `wrapper.dataset.initialMessage` when present (from template).
- [x] **D4** Replace the three user-facing error strings (lines 530, 533, 544, 555) with keys from the translation map: e.g. `i18n.error_generic` for "Es ist ein Fehler aufgetreten...", `i18n.error_reload_page` for "Bitte lade die Seite neu...". Use the same key for all occurrences of the same message.

### E. Testing and edge cases

- [ ] **E1** Test with browser language set to English: all listed strings (placeholder, buttons, titles, errors) appear in English.
- [ ] **E2** Test with browser language set to German: all strings appear in German (current behaviour preserved).
- [ ] **E3** Test with an unsupported locale: fall back to a defined default (e.g. English or German) and ensure no empty or undefined labels.
- [ ] **E4** Test with module-specific overrides (custom chat_title, welcome_message, etc.): backend-configured values still override translated defaults and are shown correctly.
- [ ] **E5** Test error paths (e.g. invalid CSRF, network error): error messages shown in the chat use the correct translation for the current language.
- [ ] **E6** Verify accessibility: all `aria-label` and `title` values are present and correct in both languages.

---

## Files changed (i18n implementation)

- `contao/languages/en/mod_ai_chat.php` — created
- `contao/languages/de/mod_ai_chat.php` — created
- `src/Controller/FrontendModule/AiChatModuleController.php` — edited
- `contao/templates/frontend_module/ai_chat_module.html.twig` — edited
- `public/js/ai-chat.js` — edited

---

## Post-implementation changes

### Language detection fix (Accept-Language order)

Initial detection used regexes that matched `de` or `en` anywhere in the header. With a header like `en,de;q=0.9,nl;q=0.8` (English first), the code checked German first and matched `de` in the second segment, so the UI stayed in German. **Change:** the controller now parses `Accept-Language` in order (comma-separated segments), takes the first listed language, and maps it to `de` or `en`; the first supported language in the list is used so browser preference order is respected.

### Use main request for Accept-Language

Fragment controllers receive a sub-request that may not forward the browser’s `Accept-Language` header. **Change:** the controller uses Symfony’s `RequestStack::getMainRequest()` to read `Accept-Language` from the main (browser) request so the chat language follows the visitor’s browser setting even when the module is rendered in a sub-request.

### Debug removal

Temporary debugging added during the above fixes was removed:

- **Controller:** Removed `$template->set('debug_lang', ...)` and `$template->set('debug_accept_language', ...)`. Renamed `getAcceptLanguageForDebug()` to `getAcceptLanguageFromMainRequest()` (still used for language detection only).
- **Template:** Removed `data-debug-lang` and `data-debug-accept-language` attributes from the chat root `<div>`.
