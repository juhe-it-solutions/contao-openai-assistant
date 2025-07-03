# Security Policy

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security vulnerability in this project, please follow these steps:

### 1. **DO NOT** create a public GitHub issue
Security vulnerabilities should be reported privately to avoid potential exploitation.

### 2. **DO** report via email
Send an email to: **office@juhe-it-solutions.at**

### 3. Include the following information:
- **Description**: Clear description of the vulnerability
- **Steps to reproduce**: Detailed steps to reproduce the issue
- **Impact**: Potential impact of the vulnerability
- **Environment**: PHP version, Contao version, extension version
- **Proof of concept**: If possible, include a proof of concept

### 4. What happens next:
- We will acknowledge receipt within 48 hours
- We will investigate the report
- We will provide updates on the status
- We will coordinate the fix and release
- We will credit you in the security advisory (unless you prefer to remain anonymous)

### 5. Responsible disclosure timeline:
- **Initial response**: Within 48 hours
- **Status update**: Within 1 week
- **Fix timeline**: Depends on severity (1-4 weeks)
- **Public disclosure**: After fix is released

## Security Best Practices

When using this extension:

1. **Keep your OpenAI API key secure**
   - Never commit API keys to version control
   - Use environment variables when possible
   - Regularly rotate your API keys

2. **Keep the extension updated**
   - Regularly update to the latest version
   - Monitor for security advisories

3. **Follow Contao security guidelines**
   - Keep Contao CMS updated
   - Use HTTPS in production
   - Follow Contao's security best practices

4. **Monitor usage**
   - Monitor OpenAI API usage
   - Set up rate limiting if needed
   - Review logs regularly

## Security Features

This extension includes several security features:

- **API Key Encryption**: All API keys are encrypted using AES-256-CBC
- **CSRF Protection**: All forms and API endpoints are protected
- **Input Validation**: Comprehensive input sanitization
- **Rate Limiting**: Built-in protection against abuse
- **Secure File Uploads**: File type and size validation
- **Session Security**: Secure session management

## Security Contact

For security-related questions or concerns:

- **Email**: office@juhe-it-solutions.at
- **Response Time**: Within 48 hours
- **PGP Key**: Available upon request

Thank you for helping keep this project secure! 