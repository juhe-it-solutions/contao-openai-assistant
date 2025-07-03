# API Key Management

This document explains the different methods for managing OpenAI API keys in the Contao OpenAI Assistant extension, with a focus on security best practices.

## 🔐 Security Overview

The extension provides multiple layers of API key security:

1. **Environment Variables** (Recommended) - Most secure
2. **Encrypted Database Storage** - Fallback option
3. **API Key Validation** - Real-time validation with OpenAI

## 🌍 Environment Variables (Recommended)

### What are Environment Variables?

Environment variables are system-level configuration values stored outside your application code. They're the most secure way to handle sensitive information like API keys.

### Benefits

- ✅ **Highest Security**: Keys never stored in code or database
- ✅ **Version Control Safe**: Can be excluded from Git
- ✅ **Environment Specific**: Different keys for dev/staging/production
- ✅ **Easy Rotation**: Change keys without touching code

### Setup Instructions

#### 1. Create `.env.local` File

Create a `.env.local` file in your Contao project root (same level as `composer.json`):

```bash
# Example .env.local file
OPENAI_API_KEY_1=sk-your-actual-openai-api-key
OPENAI_API_KEY_8=sk-another-api-key
```

#### 2. Variable Naming Convention

The extension uses this naming pattern:
```
OPENAI_API_KEY_{CONFIG_ID}
```

Where `{CONFIG_ID}` is the ID of your OpenAI configuration in Contao.

**Examples:**
- Config ID `1` → `OPENAI_API_KEY_1`
- Config ID `8` → `OPENAI_API_KEY_8`
- Config ID `15` → `OPENAI_API_KEY_15`

#### 3. Find Your Config ID

To find your configuration ID:

1. Go to **AI-TOOLS → OpenAI Dashboard** in Contao backend
2. Look at the URL when editing your configuration
3. The ID is in the URL: `/contao?do=openai_dashboard&act=edit&id=8`
4. In this example, the config ID is `8`

#### 4. Production Server Setup

For production servers, set environment variables at the system level:

**Apache with .htaccess:**
```apache
# .htaccess file
SetEnv OPENAI_API_KEY_1 "sk-your-actual-api-key"
SetEnv OPENAI_API_KEY_8 "sk-another-api-key"
```

**Nginx:**
```nginx
# nginx.conf or site configuration
fastcgi_param OPENAI_API_KEY_1 "sk-your-actual-api-key";
fastcgi_param OPENAI_API_KEY_8 "sk-another-api-key";
```

**Docker:**
```yaml
# docker-compose.yml
environment:
  - OPENAI_API_KEY_1=sk-your-actual-api-key
  - OPENAI_API_KEY_8=sk-another-api-key
```

**System Environment:**
```bash
export OPENAI_API_KEY_1="sk-your-actual-api-key"
export OPENAI_API_KEY_8="sk-another-api-key"
```

### Security Best Practices

1. **Never commit `.env.local` to version control**
   ```bash
   # Add to .gitignore
   echo ".env.local" >> .gitignore
   ```

2. **Use different keys for different environments**
   - Development: `OPENAI_API_KEY_1=sk-dev-key`
   - Staging: `OPENAI_API_KEY_1=sk-staging-key`
   - Production: `OPENAI_API_KEY_1=sk-prod-key`

3. **Regular key rotation**
   - Generate new API keys periodically
   - Update environment variables
   - No code changes required

4. **Restrict file permissions**
   ```bash
   chmod 600 .env.local
   ```

## 🗄️ Database Storage (Fallback)

If no environment variable is found, the extension falls back to encrypted database storage.

### How It Works

1. **Encryption**: API keys are encrypted using AES-256-CBC
2. **Key Generation**: Encryption key based on server configuration
3. **Storage**: Encrypted data stored in `tl_openai_config.api_key` field

### Security Features

- ✅ **AES-256-CBC Encryption**: Industry-standard encryption
- ✅ **Server-specific Keys**: Encryption key unique to your server
- ✅ **Automatic Decryption**: Transparent to the application

## 🔄 Priority System

The extension uses this priority order for API key retrieval:

1. **Environment Variable** (Highest Priority)
   ```php
   if (isset($_ENV['OPENAI_API_KEY_' . $configId])) {
       return $_ENV['OPENAI_API_KEY_' . $configId];
   }
   ```

2. **Encrypted Database Storage** (Fallback)
   ```php
   return $this->getApiKeyFromDatabase($configId);
   ```

## 🧪 Testing Your Setup

### Verify Environment Variable Loading

You can test if your environment variables are loaded correctly:

```bash
# Check if variable exists
php -r "echo isset(\$_ENV['OPENAI_API_KEY_1']) ? 'Loaded' : 'Not found';"
```

### Test API Key Validation

1. Go to **AI-TOOLS → OpenAI Dashboard**
2. Edit your configuration
3. Click **"Key prüfen"** (Check Key) button
4. Should show "✓ API-Schlüssel ist gültig!"

## 🚨 Troubleshooting

### Environment Variable Not Found

**Problem**: Extension falls back to database storage

**Solutions**:
1. Check variable name: `OPENAI_API_KEY_{CONFIG_ID}`
2. Verify `.env.local` file location (project root)
3. Check file permissions: `chmod 600 .env.local`
4. Clear Contao cache: `php bin/console cache:clear`

### Multiple Configurations

If you have multiple OpenAI configurations:

```bash
# .env.local
OPENAI_API_KEY_1=sk-first-config-key
OPENAI_API_KEY_8=sk-second-config-key
OPENAI_API_KEY_15=sk-third-config-key
```

### Production Deployment

For production deployments:

1. **Set environment variables before deployment**
2. **Never include `.env.local` in deployment packages**
3. **Use system environment variables for maximum security**
4. **Test key validation after deployment**

## 📋 Migration Guide

### From Database to Environment Variables

1. **Export current keys** (if needed for backup)
2. **Create `.env.local` file** with your keys
3. **Test the setup** using the validation button
4. **Remove keys from database** (optional, for extra security)

### Example Migration

```bash
# 1. Create .env.local
echo "OPENAI_API_KEY_8=sk-your-actual-key" > .env.local

# 2. Test in Contao backend
# Go to AI-TOOLS → OpenAI Dashboard → Edit Config → Check Key

# 3. Verify environment variable is used
# The extension will automatically use the environment variable
```

## 🔒 Security Checklist

- [ ] `.env.local` file created in project root
- [ ] `.env.local` added to `.gitignore`
- [ ] File permissions set to 600
- [ ] Environment variables tested and working
- [ ] API key validation successful
- [ ] Different keys for different environments
- [ ] Production keys set at system level

## 📚 Related Documentation

- [Encryption Details](encryption.md) - Technical encryption information
- [Installation Guide](../installation.md) - Complete setup instructions
- [Troubleshooting](../development/troubleshooting.md) - Common issues and solutions

---

**Note**: Environment variables provide the highest level of security for API key management. We strongly recommend using this method for production environments. 