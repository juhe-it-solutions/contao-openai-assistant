# Requirements

This document outlines the system requirements for the Contao OpenAI Assistant Bundle.

## System Requirements

### Minimum Requirements

- **PHP**: 8.2 or higher
- **Contao**: 5.0 or higher (tested with Contao 5.5)
- **Composer**: Latest stable version
- **OpenAI API**: Valid API key with Assistants API access

### Recommended Requirements

- **PHP**: 8.2 or higher
- **Contao**: 5.3 or higher
- **Memory**: 256MB PHP memory limit
- **Storage**: 1GB free space for file uploads
- **Network**: Stable internet connection for OpenAI API

## PHP Extensions

### Required Extensions

- **cURL**: For HTTP requests to OpenAI API
- **JSON**: For JSON encoding/decoding
- **OpenSSL**: For API key encryption
- **mbstring**: For string handling
- **PDO**: For database operations

### Optional Extensions

- **APCu**: For caching (improves performance)
- **Redis**: For advanced caching
- **ZIP**: For file compression

## OpenAI API Requirements

### Account Setup

1. **OpenAI Account**: Valid account at [platform.openai.com](https://platform.openai.com)
2. **API Access**: Access to OpenAI API
3. **Assistants API**: Beta access to Assistants API
4. **File Upload**: Access to file upload functionality

### API Limits

- **Rate Limits**: Varies by plan (check your OpenAI dashboard)
- **File Size**: Maximum 512MB per file
- **File Types**: PDF, DOCX, TXT, MD, XLSX, PPTX, JSON, CSV
- **Concurrent Requests**: Depends on your plan

## Database Requirements

### Supported Databases

- **MySQL**: 5.7 or higher
- **MariaDB**: 10.2 or higher
- **PostgreSQL**: 10 or higher (experimental)

### Database Permissions

- **CREATE**: For table creation
- **ALTER**: For schema modifications
- **INSERT**: For data insertion
- **UPDATE**: For data updates
- **DELETE**: For data deletion
- **SELECT**: For data retrieval

## Web Server Requirements

### Apache

- **mod_rewrite**: Enabled
- **PHP**: Configured as module or FastCGI
- **SSL**: Recommended for production

### Nginx

- **PHP-FPM**: Configured
- **FastCGI**: Properly configured
- **SSL**: Recommended for production

### IIS

- **PHP**: Configured as FastCGI
- **URL Rewrite**: Module installed
- **SSL**: Recommended for production

## Browser Requirements

### Frontend Chatbot

- **Chrome**: 90 or higher
- **Firefox**: 88 or higher
- **Safari**: 14 or higher
- **Edge**: 90 or higher

### Backend Interface

- **Chrome**: 90 or higher
- **Firefox**: 88 or higher
- **Safari**: 14 or higher
- **Edge**: 90 or higher

## Performance Considerations

### Server Resources

- **CPU**: 2+ cores recommended
- **RAM**: 4GB+ recommended
- **Storage**: SSD recommended
- **Network**: 10Mbps+ upload/download

### Optimization

- **OPcache**: Enable for better PHP performance
- **APCu**: Enable for caching
- **CDN**: Use for static assets
- **Database**: Optimize queries and indexes

## Security Requirements

### SSL/TLS

- **HTTPS**: Required for production
- **TLS 1.2+**: Minimum TLS version
- **Valid Certificate**: Proper SSL certificate

### File Permissions

- **Upload Directory**: Writable by web server
- **Config Files**: Readable by web server
- **Log Files**: Writable by web server

### API Security

- **API Key**: Secure storage and transmission
- **Rate Limiting**: Implemented
- **Input Validation**: Comprehensive validation
- **Output Sanitization**: Proper escaping

## Development Requirements

### Development Tools

- **Git**: Version control
- **Composer**: Dependency management
- **ECS**: Code style checking
- **PHPStan**: Static analysis
- **Composer Audit**: Built-in security scanning
- **Code Quality Tools**: PHP CS Fixer, PHPStan

### Development Environment

- **Local Server**: XAMPP, MAMP, or similar
- **IDE**: PHPStorm, VS Code, or similar
- **Debug Tools**: Xdebug, browser dev tools

## Troubleshooting

### Common Issues

1. **PHP Version**: Ensure PHP 8.2+ is installed
2. **Extensions**: Check all required extensions are loaded
3. **Permissions**: Verify file and directory permissions
4. **API Access**: Confirm OpenAI API access and limits
5. **Network**: Test connectivity to OpenAI API

### Verification Commands

```bash
# Check PHP version
php -v

# Check PHP extensions
php -m

# Check Composer
composer --version

# Check Contao
php bin/console contao:version

# Test OpenAI API
curl -H "Authorization: Bearer YOUR_API_KEY" https://api.openai.com/v1/models
```

## Support

If you encounter issues with requirements:

1. Check this documentation
2. Review [Troubleshooting Guide](development/troubleshooting.md)
3. Contact support with system information
4. Check Contao and OpenAI documentation 