# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
