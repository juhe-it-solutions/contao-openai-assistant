# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.2] - 2024-12-19
- CI/CD pipeline implementation with GitHub Actions, PHP compatibility testing (8.1-8.4), code quality checks (ECS/PHPStan), security scanning, and automated release workflow

## [1.0.1] - 2024-12-19
- Bug fixes (CSS, JS improvements)

## [1.0.0] - 2024-07-03

### 🎉 Initial Release

Initial release of the Contao OpenAI Assistant extension.

### Added
- Initial release of Contao OpenAI Assistant extension
- Backend management interface for OpenAI configurations
- Assistant creation and management functionality
- File upload and vector store integration
- Frontend chatbot widget with customizable themes
- CSRF protection and security features
- Model selection with validation and custom model input option
- Session management and conversation persistence
- Responsive design with accessibility support
- Multi-language support (English/German)

### Features
- OpenAI API key encryption and secure storage
- Automatic vector store creation and management
- Real-time synchronization with OpenAI platform
- Customizable chat widget positioning and styling
- Rate limiting and error handling
- Comprehensive logging and debugging support
- Dynamic model selection with custom model input option in second position

### Security
- API key encryption using AES-256-CBC
- CSRF token validation
- Input sanitization and validation
- Secure file upload handling

### Technical
- PHP 8.1+ compatibility
- Contao 5.3+ compatibility
- PSR-4 autoloading
- Symfony service container integration
- Event-driven architecture

### Documentation
- Comprehensive installation and configuration guides
- Security documentation and best practices
- Development documentation for contributors
- API documentation and technical specifications 