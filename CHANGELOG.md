# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.2] - 2026-02-24

### Fixed
- **Frontend chat links:** LLM sometimes appended "br" to http(s) link URLs and link text (e.g. `.htmlbr` instead of `.html`). A post-processing step now strips trailing "br" from http(s) links only; mailto: and tel: links are unchanged.

## [1.1.1] - 2026-02-24

### Added
- **Frontend i18n for the AI chat module:** All user-visible strings (placeholder, buttons, labels, titles, errors, disclaimer) are now translated based on the browser’s preferred language. German and English are supported via `contao/languages/de/mod_ai_chat.php` and `contao/languages/en/mod_ai_chat.php`. The controller reads `Accept-Language` from the main request, parses it in priority order, and loads the matching language file; the template and JavaScript use server-provided labels and a small JSON map for client-side strings. Unsupported locales fall back to English. Module-specific titles and messages from the backend still override the translated defaults.

### Changed
- Language detection now respects the order of the `Accept-Language` header (e.g. `en,de;q=0.9` correctly yields English when English is listed first).
- `Accept-Language` is read from the main request via `RequestStack::getMainRequest()` so the chat language follows the visitor’s browser even when the module is rendered in a fragment sub-request.

### Fixed
- **Frontend chat links:** Trailing `<` or `>` could appear in link `href` values and link text, breaking or misdisplaying links. URLs are now sanitized when turning plain URLs into links (strip `<`/`>` from captured URL), all `href="..."` values are cleaned of `<`/`>` in a final pass, and a stray `>` immediately after `</a>` (e.g. from angle-bracket notation or model output) is removed.

### Notes
- No database migration required. Clear frontend cache after update if needed.

## [1.1.0] - 2026-02-18

### Added
- Backend "Key prüfen" button for OpenAI config API key field: validate key before save (works in Contao 5.3 and 5.7).
- Dedicated backend JS asset `backend-api-key-check.js` as fallback for button binding.
- Backend CSS for API key check wrapper (placement below input, spinner, result message).

### Changed
- OpenAI config DCA: API key field now uses `xlabel` callback instead of `wizard` for reliable rendering in Contao 5.7.
- `OpenAiConfigListener::apiKeyWizard()`: outputs HTML + data attributes and inline script so the button works without depending on global backend JS in all Contao versions.
- Button is placed below the API key input in both 5.3 and 5.7; input lookup supports `ctrl_<field>`, `<field>`, and `input[name="..."]` for compatibility.
- Removed `fields.api_key.wizard` service callback tag from `config/services.yaml` (wizard registration is via DCA only).

### Fixed
- "Key prüfen" button not visible in Contao 5.7.0 (wizard callback no longer used for password widget in 5.7).
- Button overlapping the API key input in Contao 5.7; wrapper is moved below the field and styled with clear spacing/z-index.
- Button click having no effect when backend JS did not load; inline script in widget ensures validation runs in both 5.3 and 5.7.

### Notes
- No database migration required. Clear backend cache after update.

## [1.0.8] - 2025-09-25

### Changed
- Use configured web directory parameter (`%contao.web_dir%`) to resolve absolute file paths instead of hardcoding `public/`.
- Preserve absolute `%contao.web_dir%` values and only prefix with `%kernel.project_dir%` when relative.

### Fixed
- "File not found" errors on systems with non-default web roots (e.g., custom doc roots or legacy `web/`).
- Improved user-facing error messages for missing files, including resolved web root and attempted absolute path.

### Notes
- No database migration required. Clear cache after update so the container picks up the new service argument.

## [1.0.7] - 2025-08-23

### Fixed
- **CRITICAL**: Database migration issue with disclaimer_text column causing "Data truncated" error in MySQL
- Updated DCA configuration to use proper Doctrine schema representation for Contao 5.3+
- Removed database default values for TEXT columns (not supported in MySQL)
- Enhanced migration system to handle TEXT columns properly without database defaults

## [1.0.6] - 2025-01-27

### Added
- Set default value for disclaimer in frontend chatbot
- Auto-focus on chatbot input field for better user experience

### Changed
- Improved release script with better error handling and validation

## [1.0.5] - 2025-08-23

### Added
- Enhanced frontend module with improved user experience
- New documentation structure with feature guides
- Migration system for database schema updates

### Changed
- Updated module templates and styling for better responsiveness
- Improved JavaScript functionality for chat interactions
- Enhanced language files with better translations
- Updated PHPStan configuration for stricter type checking

### Fixed
- Various code style and formatting improvements
- Enhanced documentation clarity and structure

## [1.0.4] - 2025-08-13

### Fixed
- Load services via `AbstractBundle::loadExtension` with `ContainerConfigurator`; fixes service loading after v1.0.3 where services might not have been registered properly.

## [1.0.3] - 2025-08-13

### Added
- Surface assistant failure causes and bind vector store per run
- Add `.gitattributes`
- Add project homepage
- CI/CD pipeline documentation and quick reference

### Changed
- Update to PHPStan 2.x, adjust configuration and code accordingly
- Preserve system instructions exactly as entered (decode entities; preserve quotes/brackets)
- Adapt regex to prettify bot answers
- Update CI/CD workflows and documentation

### Fixed
- Code style issues and ECS workflow inconsistencies
- Default value of DCA field `top_p`
- Mobile: ensure chat window is collapsed by default on small screens
- Various CI/CD pipeline fixes; remove invalid `--dry-run` flag in ECS check
- Add explicit nullable type hint for PHP 8.4 compatibility

### Removed
- `composer.lock` from the repository

## [1.0.2] - 2025-07-07

### Added
- Complete CI/CD pipeline implementation and production readiness

### Changed
- Prevent auto-focus on mobile devices to avoid unwanted keyboard popups

### Removed
- Obsolete package metadata and logo/preview files from `contao` directory (moved to package-metadata repository)
- One GitHub Actions workflow to simplify development

## [1.0.1] - 2025-07-05

### Added
- Optimized package metadata, company logo, and preview image for the Contao extension marketplace

### Changed
- Bump internal version metadata to 1.0.1

### Fixed
- Critical CSS issue on mobile devices

### Removed
- Unnecessary `contao/README.md` file

## [1.0.0] - 2025-07-03

### Added
- Initial release of Contao OpenAI Assistant Bundle
- OpenAI Assistant integration with backend management
- Frontend chatbot with customizable styling
- File upload support for knowledge base
- Secure API key management with encryption
- Vector store integration for file processing
- Model selection with validation
- CSRF protection and security features
- Responsive design with theme support
- Comprehensive documentation and guides

### Changed
- CI/CD pipeline implementation with GitHub Actions, PHP 8.2 testing, code quality checks (ECS/PHPStan), security scanning, and automated release workflow
- Simplified testing approach focusing on code quality, formatting, and security
- PHP 8.2+ compatibility

### Security
- API key encryption using AES-256-CBC
- CSRF token validation for all forms
- Input sanitization and validation
- Secure file upload handling

- Environment variable support for API keys

