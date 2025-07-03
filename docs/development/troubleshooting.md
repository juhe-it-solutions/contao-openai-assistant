# Troubleshooting Guide

This guide helps you resolve common issues with the Contao OpenAI Assistant Bundle.

## Quick Diagnosis

### Check System Status

1. **Backend Access**: Can you access the KI-TOOLS menu?
2. **OpenAI Dashboard**: Is the dashboard loading?
3. **API Connection**: Are you getting connection errors?
4. **Frontend Chatbot**: Is the chatbot appearing on your website?

## Common Issues and Solutions

### Backend Issues

#### KI-TOOLS Menu Not Visible

**Symptoms**: KI-TOOLS menu doesn't appear in backend navigation

**Possible Causes**:
- Bundle not properly installed
- Database migration not run
- User permissions insufficient

**Solutions**:
```bash
# 1. Check if bundle is installed
composer show juhe-it-solutions/contao-openai-assistant

# 2. Run database migration
php bin/console contao:migrate

# 3. Clear cache
php bin/console cache:clear

# 4. Check user permissions in Contao backend
```

#### OpenAI Dashboard Not Loading

**Symptoms**: Dashboard shows error or blank page

**Possible Causes**:
- JavaScript errors
- Missing dependencies
- Database connection issues

**Solutions**:
1. Check browser console for JavaScript errors
2. Verify database connection
3. Clear browser cache
4. Check Contao system log

### API Connection Issues

#### Invalid API Key Error

**Error Message**: `Invalid API key provided`

**Solutions**:
1. Verify API key format (starts with `sk-`)
2. Check if key is copied correctly (no extra spaces)
3. Verify key hasn't expired
4. Test key in OpenAI platform

#### Rate Limit Exceeded

**Error Message**: `Rate limit exceeded`

**Solutions**:
1. Wait a few minutes before retrying
2. Check your OpenAI usage limits
3. Consider upgrading your OpenAI plan
4. Implement request throttling

#### Insufficient Credits

**Error Message**: `You exceeded your current quota`

**Solutions**:
1. Add credits to your OpenAI account
2. Check usage in OpenAI dashboard
3. Monitor API usage in Contao backend

### Model Selection Issues

#### No Models Available

**Symptoms**: Model dropdown is empty

**Possible Causes**:
- API key doesn't have model access
- Network connectivity issues
- API rate limiting

**Solutions**:
1. Check API key permissions
2. Verify internet connection
3. Try manual model entry
4. Check fallback models are working

#### Model Validation Fails

**Error Message**: `Model not compatible with Assistants API`

**Solutions**:
1. Use a different model (GPT-4o, GPT-4o-mini, etc.)
2. Check model availability in your account
3. Try manual model entry with a known compatible model

### File Upload Issues

#### File Upload Fails

**Error Message**: `File upload failed`

**Possible Causes**:
- File too large
- Unsupported file type
- API key doesn't have file upload access

**Solutions**:
1. Check file size (max 512MB)
2. Verify file type (PDF, DOCX, TXT, etc.)
3. Check API key permissions
4. Try smaller file

#### File Processing Error

**Error Message**: `File processing failed`

**Solutions**:
1. Check file content (not corrupted)
2. Try different file format
3. Verify file is readable
4. Check OpenAI service status

### Frontend Chatbot Issues

#### Chatbot Not Appearing

**Symptoms**: Chatbot module doesn't show on website

**Possible Causes**:
- Module not added to page
- CSS conflicts
- JavaScript errors

**Solutions**:
1. Check if module is added to page layout
2. Verify module is published
3. Check browser console for errors
4. Test in different browser

#### Chatbot Not Responding

**Symptoms**: Messages sent but no response

**Possible Causes**:
- Assistant not configured
- API connection issues
- JavaScript errors

**Solutions**:
1. Check assistant configuration in backend
2. Verify API key is working
3. Check browser console for errors
4. Test API connection

#### Styling Issues

**Symptoms**: Chatbot looks broken or unstyled

**Solutions**:
1. Check if CSS files are loading
2. Verify no CSS conflicts
3. Clear browser cache
4. Check responsive design

### Database Issues

#### Migration Errors

**Error Message**: `Migration failed`

**Solutions**:
```bash
# 1. Check database connection
php bin/console doctrine:database:create --if-not-exists

# 2. Run migrations manually
php bin/console doctrine:migrations:migrate

# 3. Check for conflicts
php bin/console doctrine:schema:validate
```

#### Data Corruption

**Symptoms**: Strange behavior, missing data

**Solutions**:
1. Check database integrity
2. Restore from backup
3. Re-run migrations
4. Clear cache

## Debug Mode

### Enable Debug Logging

```php
// In config/config.php
$GLOBALS['TL_CONFIG']['debugMode'] = true;
$GLOBALS['TL_CONFIG']['logErrors'] = true;
```

### Check Logs

```bash
# Contao system log
tail -f var/logs/contao.log

# Symfony debug log
tail -f var/logs/dev.log

# Error log
tail -f var/logs/error.log
```

### Browser Developer Tools

1. **Console**: Check for JavaScript errors
2. **Network**: Monitor API requests
3. **Application**: Check local storage and cookies
4. **Elements**: Inspect DOM structure

## Performance Issues

### Slow Loading

**Possible Causes**:
- Large file uploads
- Slow API responses
- Database queries

**Solutions**:
1. Optimize file sizes
2. Implement caching
3. Check database performance
4. Monitor API response times

### Memory Issues

**Error Message**: `Memory limit exceeded`

**Solutions**:
1. Increase PHP memory limit
2. Optimize file processing
3. Implement chunked uploads
4. Monitor memory usage

## Security Issues

### API Key Exposure

**Symptoms**: API key visible in logs or errors

**Solutions**:
1. Check encryption is working
2. Review error handling
3. Audit log output
4. Rotate API key

### Unauthorized Access

**Symptoms**: Users accessing restricted areas

**Solutions**:
1. Check user permissions
2. Review access controls
3. Audit user activities
4. Implement additional security

## Getting Help

### Before Contacting Support

1. **Document the Issue**:
   - Error messages (exact text)
   - Steps to reproduce
   - Browser/OS information
   - Contao version

2. **Check Logs**:
   - System logs
   - Error logs
   - Browser console

3. **Test Basic Functionality**:
   - API key works in OpenAI platform
   - Contao backend is accessible
   - Database is working

### Support Resources

1. **Contao Documentation**: [docs.contao.org](https://docs.contao.org/)
2. **OpenAI Documentation**: [platform.openai.com/docs](https://platform.openai.com/docs)
3. **Bundle Documentation**: Check the docs directory
4. **Community Forums**: Contao community forums

### Contact Information

When contacting support, include:
- Contao version
- Bundle version
- PHP version
- Error messages
- Steps to reproduce
- Log files (if relevant)

## Prevention

### Regular Maintenance

1. **Monitor Usage**: Check API usage regularly
2. **Update Regularly**: Keep bundle and Contao updated
3. **Backup Data**: Regular database backups
4. **Test Functionality**: Periodic testing of features

### Best Practices

1. **Use Strong API Keys**: Rotate keys regularly
2. **Monitor Logs**: Check for unusual activity
3. **Limit Access**: Restrict backend access
4. **Test Changes**: Test in development environment first

This troubleshooting guide should help you resolve most common issues. If you continue to experience problems, please contact support with detailed information about your specific situation. 