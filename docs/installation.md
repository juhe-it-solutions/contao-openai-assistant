# Installation Guide

## Prerequisites

- Contao 5.0 or higher
- PHP 8.2 or higher
- OpenAI API key
- Composer

## Installation

### 1. Install via Composer

```bash
composer require juhe-it-solutions/contao-openai-assistant
```

### 2. Update Database

Run the Contao install tool or use the command line:

```bash
php bin/console contao:migrate
```

### 3. Configure Bundle

1. Go to **System → Modules** in the Contao backend
2. Find **KI-TOOLS** in the left navigation
3. Click on **OpenAI Dashboard**
4. Create your first OpenAI configuration

### 4. Set Up OpenAI

1. **Create OpenAI Configuration**
   - Enter your OpenAI API key
   - Test the connection
   - Save the configuration

   **💡 Security Tip**: For production environments, consider using environment variables for API key storage. See [API Key Management](../security/api-key-management.md) for details.

2. **Upload Files** (Optional)
   - Upload documents for vector search
   - Files will be processed and added to OpenAI's vector store

3. **Create Assistant**
   - Configure your AI assistant
   - Set system instructions
   - Choose AI model

### 5. Add Chatbot to Frontend

1. **Create Module**
   - Go to **Layout → Modules**
   - Create new module
   - Select **KI-Chatbot** type
   - Configure appearance and behavior

2. **Add to Page**
   - Go to **Layout → Pages**
   - Edit your page
   - Add the chatbot module to desired position

## Verification

After installation, you should see:
- ✅ **KI-TOOLS** menu in backend navigation
- ✅ **OpenAI Dashboard** accessible
- ✅ Chatbot module available in module creation
- ✅ No errors in Contao system log

## Next Steps

- [Quick Start Guide](quick-start.md) - Get your first assistant running
- [Configuration Guide](configuration/openai-setup.md) - Detailed setup instructions
- [Troubleshooting](development/troubleshooting.md) - If something goes wrong 