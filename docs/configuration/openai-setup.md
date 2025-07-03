# OpenAI Setup Guide

This guide will help you set up your OpenAI integration for the Contao OpenAI Assistant Bundle.

## Prerequisites

- OpenAI account with API access
- Valid OpenAI API key
- Contao 5 installation with the bundle installed

## Getting Your OpenAI API Key

### 1. Create OpenAI Account

1. Go to [OpenAI Platform](https://platform.openai.com/)
2. Sign up or log in to your account
3. Verify your email address

### 2. Generate API Key

1. Navigate to **API Keys** in your OpenAI dashboard
2. Click **Create new secret key**
3. Give your key a descriptive name (e.g., "Contao Assistant Bundle")
4. Copy the generated key immediately (you won't see it again)

### 3. Check API Access

Ensure your account has access to:
- **GPT-4o** or **GPT-4o-mini** models
- **Assistants API** (included with most plans)
- **File uploads** (for vector search functionality)

## Setting Up in Contao Backend

### 1. Access OpenAI Dashboard

1. Log into your Contao backend
2. Navigate to **System → KI-TOOLS**
3. Click on **OpenAI Dashboard**

### 2. Create OpenAI Configuration

1. Click **New** to create a new configuration
2. Fill in the required fields:

#### Basic Information
- **Name**: Give your configuration a descriptive name
- **Description**: Optional description for internal reference

#### API Configuration
- **API Key**: Paste your OpenAI API key
- **Test Connection**: Click to verify your API key works

### 3. Test Your Configuration

The system will automatically:
- ✅ Validate your API key format
- ✅ Test connection to OpenAI API
- ✅ Verify API key permissions
- ✅ Check model availability

## Security Best Practices

### API Key Management

- **Never share your API key** publicly
- **Use environment variables** in production
- **Rotate keys regularly** for security
- **Monitor usage** in OpenAI dashboard

### Access Control

- **Limit backend access** to trusted users
- **Use Contao permissions** to control access
- **Audit API usage** regularly

## Troubleshooting

### Common Issues

#### Invalid API Key
```
Error: Invalid API key provided
```
**Solution**: Verify your API key is correct and hasn't expired

#### Insufficient Credits
```
Error: You exceeded your current quota
```
**Solution**: Add credits to your OpenAI account

#### Model Not Available
```
Error: Model not found
```
**Solution**: Check if the model is available in your account

#### Rate Limiting
```
Error: Rate limit exceeded
```
**Solution**: Wait a moment and try again, or upgrade your plan

### Debug Information

Enable debug logging to get detailed error information:

```php
// In your Contao configuration
$GLOBALS['TL_CONFIG']['debugMode'] = true;
```

## Next Steps

After setting up your OpenAI configuration:

1. **[Upload Files](files.md)** - Add documents for vector search
2. **[Create Assistant](assistants.md)** - Configure your AI assistant
3. **[Set Up Frontend](../frontend/chatbot-module.md)** - Add chatbot to your website

## Support

If you encounter issues:

1. Check the [Troubleshooting Guide](../development/troubleshooting.md)
2. Review [OpenAI Documentation](https://platform.openai.com/docs)
3. Contact support with detailed error messages 