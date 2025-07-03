# Contributing to Contao OpenAI Assistant Bundle

Thank you for your interest in contributing to the Contao OpenAI Assistant Bundle! This document provides guidelines for contributing to the project.

## Code of Conduct

By participating in this project, you agree to abide by our Code of Conduct. Please read [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) before contributing.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When creating a bug report, include:

- **Clear and descriptive title**
- **Exact steps to reproduce the problem**
- **Expected behavior**
- **Actual behavior**
- **Environment details**:
  - Contao version
  - PHP version
  - Bundle version
  - Browser (for frontend issues)
- **Error messages** (if any)
- **Screenshots** (if applicable)

### Suggesting Enhancements

Enhancement suggestions are welcome! When suggesting features:

- **Describe the problem** you're trying to solve
- **Explain why** this enhancement would be useful
- **Provide examples** of how it would work
- **Consider the impact** on existing functionality

### Pull Requests

We welcome pull requests! Please follow these guidelines:

#### Before Submitting

1. **Fork the repository**
2. **Create a feature branch**: `git checkout -b feature/amazing-feature`
3. **Make your changes**
4. **Test thoroughly**
5. **Update documentation** if needed
6. **Commit your changes**: `git commit -m 'Add amazing feature'`
7. **Push to your branch**: `git push origin feature/amazing-feature`
8. **Open a Pull Request**

#### Code Style

- Follow **PSR-12** coding standards
- Use **meaningful variable and function names**
- Add **comments** for complex logic
- Write **unit tests** for new functionality
- Update **documentation** for new features

#### Commit Messages

Use clear, descriptive commit messages:

```
feat: add new model validation feature
fix: resolve API key encryption issue
docs: update installation guide
test: add unit tests for encryption service
```

#### Pull Request Guidelines

- **Clear title** describing the change
- **Detailed description** of what was changed and why
- **Reference related issues** using `#123`
- **Include tests** for new functionality
- **Update documentation** if needed
- **Screenshots** for UI changes

## Development Setup

### Prerequisites

- PHP 8.1 or higher
- Composer
- Contao 5.0 or higher
- OpenAI API key (for testing)

### Local Development

1. **Clone the repository**:
   ```bash
   git clone https://github.com/your-username/contao-openai-assistant.git
   cd contao-openai-assistant
   ```

2. **Install dependencies**:
   ```bash
   composer install
   ```

3. **Set up Contao**:
   ```bash
   # Create a test Contao installation
   composer create-project contao/managed-edition test-contao
   cd test-contao
   
   # Install the bundle
   composer require ../contao-openai-assistant
   ```

4. **Configure environment**:
   ```bash
   # Copy environment file
   cp .env.example .env
   
   # Edit .env with your settings
   # Add your OpenAI API key for testing
   ```

5. **Run tests**:
   ```bash
   vendor/bin/phpunit
   ```

### Testing

#### Unit Tests

Run the test suite:

```bash
vendor/bin/phpunit
```

#### Integration Tests

Test with a real Contao installation:

1. Set up a test Contao site
2. Install the bundle
3. Configure OpenAI API
4. Test all features manually

#### Code Quality

Check code quality:

```bash
# PHP CS Fixer
vendor/bin/php-cs-fixer fix --dry-run

# PHPStan
vendor/bin/phpstan analyse

# Psalm
vendor/bin/psalm
```

## Project Structure

```
contao-openai-assistant/
├── config/                 # Bundle configuration
├── contao/                 # Contao-specific files
│   ├── backend/           # Backend templates
│   ├── config/            # Contao configuration
│   ├── dca/               # Data container arrays
│   ├── languages/         # Language files
│   └── templates/         # Frontend templates
├── docs/                  # Documentation
├── public/                # Public assets
├── src/                   # Source code
│   ├── Controller/        # Controllers
│   ├── EventListener/     # Event listeners
│   ├── Security/          # Security classes
│   └── Service/           # Services
├── tests/                 # Test files
├── composer.json          # Composer configuration
├── README.md              # Project readme
└── LICENSE                # License file
```

## Documentation

### Writing Documentation

- Use **clear, concise language**
- Include **code examples**
- Add **screenshots** for UI features
- Keep **documentation up to date**
- Follow **markdown best practices**

### Documentation Structure

- **Getting Started**: Installation and basic setup
- **Configuration**: Detailed configuration options
- **Usage**: How to use features
- **Development**: Developer documentation
- **Troubleshooting**: Common issues and solutions

## Release Process

### Versioning

We follow [Semantic Versioning](https://semver.org/):

- **MAJOR**: Breaking changes
- **MINOR**: New features (backward compatible)
- **PATCH**: Bug fixes (backward compatible)

### Release Checklist

Before releasing:

- [ ] All tests pass
- [ ] Documentation is updated
- [ ] Changelog is updated
- [ ] Version numbers are updated
- [ ] Release notes are written
- [ ] Tag is created

## Communication

### Issues

- Use **GitHub Issues** for bug reports and feature requests
- **Be respectful** and constructive
- **Provide context** and details
- **Follow up** on your issues

### Discussions

- Use **GitHub Discussions** for questions and general discussion
- **Search existing discussions** before creating new ones
- **Be helpful** to other community members

### Security

For security issues:

- **Don't create public issues** for security vulnerabilities
- **Email security@juhe-it-solutions.com** instead
- **Include detailed information** about the vulnerability
- **Wait for response** before disclosing publicly

## Recognition

Contributors will be recognized in:

- **README.md** contributors section
- **Release notes**
- **GitHub contributors page**

## Questions?

If you have questions about contributing:

1. Check the [documentation](docs/)
2. Search existing [issues](https://github.com/your-username/contao-openai-assistant/issues)
3. Start a [discussion](https://github.com/your-username/contao-openai-assistant/discussions)
4. Contact the maintainers

Thank you for contributing to the Contao OpenAI Assistant Bundle! 