# Contao OpenAI Assistant Extension

[![License: LGPL-3.0-or-later](https://img.shields.io/badge/License-LGPL%203.0--or--later-blue.svg)](LICENSE)
[![Contao](https://img.shields.io/badge/Contao-5.3+-green.svg)](https://contao.org)
[![PHP](https://img.shields.io/badge/PHP-8.2+-purple.svg)](https://php.net)
[![Production Ready](https://img.shields.io/badge/Production-Ready-brightgreen.svg)](https://github.com/juhe-it-solutions/contao-openai-assistant)
[![Packagist](https://img.shields.io/packagist/v/juhe-it-solutions/contao-openai-assistant.svg)](https://packagist.org/packages/juhe-it-solutions/contao-openai-assistant)

## About

**juhe-it-solutions/contao-openai-assistant** is a comprehensive OpenAI Assistant integration for Contao 5.3+ providing backend management and a modern frontend chatbot. Developed and maintained by [JUHE IT-solutions](https://github.com/juhe-it-solutions), Austria.

This extension allows you to create, configure, and deploy AI assistants directly within your Contao CMS, with secure API key management, file uploads, and a customizable frontend chat widget.

## 🎉 A Quick Note from the Developer

Hey folks! 👋 This whole project is my **first attempt** at completely vibing with Contao extension development (crafted in countless nights fueled by coffee ☕ and determination 💪). 

While I'm super proud of what we've built here, I gotta be honest - I don't have the bandwidth to actively maintain this project.

**Feel free to:**
- 🐛 Create issues (bugs, feature requests, whatever!)
- 🔧 Submit pull requests 
- 💡 Suggest improvements
- ⭐ Give it a star if you find it useful
- ☕ [Buy me a coffee](https://buymeacoffee.com/juliuscaesar1) if this extension helps you out!

**No guarantees** that everything will be addressed immediately, but I'll take a look when time allows! Since this is open source, feel free to fork and enhance it! 🎯

## 🚀 Features

### Backend Management
- **OpenAI Configuration Management**: Secure API key storage and validation
- **Assistant Creation & Management**: Create and configure AI assistants with custom instructions
- **File Upload & Management**: Upload and manage files for assistant knowledge base
- **Vector Store Integration**: Automatic creation and management of OpenAI vector stores
- **Model Selection**: Fast loading of all available OpenAI models with save-time validation
- **Parameter Configuration**: Fine-tune temperature, top_p, and other assistant parameters

### Frontend Chatbot
- **Responsive Chat Interface**: Modern, accessible chat widget
- **Theme Support**: Light and dark theme with customizable colors
- **Positioning Options**: Multiple chat widget positions (bottom-right, bottom-left, etc.)
- **Session Management**: Persistent conversation threads
- **CSRF Protection**: Built-in security with CSRF token validation
- **Accessibility**: ARIA labels and keyboard navigation support
- **Disclaimer Feature**: Configurable disclaimer text with information icon in chat header

### Security & Performance
- **API Key Encryption**: Secure storage of OpenAI API keys
- **Rate Limiting**: Built-in protection against abuse
- **Error Handling**: Comprehensive error logging and user feedback
- **Session Management**: Thread-based conversation persistence

## 📋 Requirements

- **Contao**: 5.3 or higher (tested with Contao 5.5)
- **PHP**: 8.2 or higher
- **OpenAI API Key**: Valid OpenAI API key with Assistants API access
- **Composer**: For installation and dependency management

## 🚀 Quick Start

### 🛠️ Installation using Contao Manager

The extension can easily be installed using the Contao Manager.
Just search for the extension with keywords 'openai', 'openai-assistant', 'chatbot',... 

### 🛠️ Installation using composer

For detailed installation instructions, see [docs/installation.md](docs/installation.md).

1. **Install the extension**:
```bash
composer require juhe-it-solutions/contao-openai-assistant
```

2. **Run migrations and clear cache**:
```bash
php bin/console contao:migrate
php bin/console cache:clear
```

3. **Configure in Contao backend**:
   - Go to **AI-TOOLS → OpenAI Dashboard**
   - Create OpenAI configuration with your API key
   - Upload knowledge base files
   - Create your first assistant

4. **Add to frontend**:
   - Create AI-Chatbot module in **Layout → Modules**
   - Add module to your page layout

📚 **For detailed documentation, see the [docs/](docs/) directory.**

### Manual Installation (Alternative)

If you prefer manual installation:

1. **Download the extension**:
```bash
git clone https://github.com/juhe-it-solutions/contao-openai-assistant.git
```

2. **Copy to your Contao installation**:
```bash
cp -r contao-openai-assistant vendor/juhe-it-solutions/contao-openai-assistant/
```

3. **Add to composer.json** (if not using Composer):
```json
{
  "autoload": {
    "psr-4": {
      "JuheItSolutions\\ContaoOpenaiAssistant\\": "vendor/juhe-it-solutions/contao-openai-assistant/src/"
    }
  }
}
```

4. **Run migrations and clear cache**:
```bash
php bin/console contao:migrate
php bin/console cache:clear
```

## 🎯 How to Setup

### Prerequisites

Before setting up the extension, you need:

1. **OpenAI Account**: Create an account at [platform.openai.com](https://platform.openai.com)
2. **Create Project**: e.g. Chatbot for website xyz
2. **API Key**: Generate a secret key in your OpenAI project
3. **Assistants API Access**: Ensure your account has access to the Assistants API
4. **Contao 5.3+**: Ensure you're running Contao 5.3 or higher (recommended: Contao 5.5)

### Step-by-Step Setup Guide

#### 1. Backend Configuration

After installing the extension via Contao Manager, you will see a new entry in your Contao backend main menu: **AI-TOOLS → OpenAI Dashboard**. Click on this link to access the configuration area.

##### 1a. Create OpenAI Configuration
- Click **"New configuration"**
- Enter your OpenAI API key/Secret key and press button "Validate key"
- Save the configuration
- The system will automatically validate your API key

##### 1b. Upload Knowledge Base Files
- Click the **3rd icon "File Upload"**
- Click **"New file"**
- Choose files containing website-/company information or other data on which the OpenAI assistant should rely
- Supported formats: PDF, TXT, MD, DOCX, PPTX, JSON

##### 1c. Create OpenAI Assistant
- In the overview page, click the **4th icon "Assistants"**
- Click **"Create OpenAI Assistant"**
- Configure your assistant:
  - **Name and Description**: Give your assistant a meaningful name
  - **System Instructions**: Define how the assistant should behave and respond
  - **Model Selection**: Choose from all available OpenAI models (fast loading, validation on save)
  - **Parameters**: Adjust temperature, top_p, and other settings as needed

#### 2. Frontend Module Configuration

##### 2a. Create AI Chat Module
- Navigate to **Themes → Frontend Modules**
- Click **"New module"**
- Select module type **"AI tools → AI-Chatbot"**

##### 2b. Configure Chatbot Settings
- **Chat Position**: Choose widget position (bottom-right, bottom-left, etc.)
- **Initial State**: Collapsed or expanded
- **Theme**: Light or dark mode
- **Colors**: Customize all theme colors (background, text, buttons)
- **Font Size**: Adjustable base font size (12px to 20px)
- **Messages**: Set custom titles and welcome messages for the frontend chatbot

#### 3. Integration

##### 3a. Page Layout Integration
- Go to **Layout → Page Layout**
- Select your desired page layout
- Add the newly created AI-Chatbot module to the layout

##### 3b. Content Element Integration (Alternative)
- Create a new content element
- Choose "Module" as the element type
- Select your AI-Chatbot module

## 🔄 How It Works (Real-World Process)

### Behind the Scenes

When you configure the extension in Contao, the following happens automatically on the OpenAI platform:

#### 1. OpenAI Project Setup
- **Vector Store Creation**: A vector store is automatically created in your OpenAI project under "Storage"
- **File Processing**: Uploaded files are processed and stored in the vector store
- **Assistant Creation**: An OpenAI assistant is created in the "Assistants" section with your specified configuration

#### 2. Synchronization Process
- **One-Way Sync**: All creations, edits, and deletions flow **from Contao backend to OpenAI platform**
- **Real-Time Updates**: Changes made in Contao are immediately reflected on platform.openai.com
- **Automatic Management**: The extension handles all API calls and platform interactions

#### 3. File Management
- **Upload Processing**: Files uploaded in Contao are automatically sent to OpenAI
- **Vector Store Integration**: Files are indexed and made available to your assistant
- **Knowledge Base**: The assistant can reference uploaded files when responding to users

#### 4. Assistant Configuration
- **System Instructions**: Your defined instructions are applied to the OpenAI assistant
- **Model Selection**: The chosen model is validated and configured on the platform
- **Parameters**: Temperature, top_p, and other settings are applied

### Important Notes

⚠️ **One-Way Synchronization**: 
- Changes made in Contao backend → Automatically sync to OpenAI platform
- Changes made directly on platform.openai.com → **NOT** synced back to Contao
- Always make changes through the Contao backend for consistency

🔒 **Security**: 
🌐 **Web Root Detection**:
- The bundle resolves file system paths using the configured Contao web directory parameter `%contao.web_dir%`.
- If `%contao.web_dir%` is absolute (e.g., `/var/www/project/public`), it is used directly. If it is relative (e.g., `public`), it will be prefixed with `%kernel.project_dir%`.
- This prevents "File not found" errors on instances with custom document roots.

- API keys are securely stored and encrypted
- All communications with OpenAI use HTTPS
- No sensitive data is logged or exposed

## ⚙️ Configuration

### API Key Management

The extension supports multiple methods for storing OpenAI API keys:

- **Environment Variables** (Recommended): Store keys in `.env.local` or system environment
- **Encrypted Database**: Fallback with AES-256-CBC encryption
- **Real-time Validation**: Keys validated with OpenAI on save

📖 **For detailed API key management, see [docs/security/api-key-management.md](docs/security/api-key-management.md)**

### Color Customization

The extension supports full color customization for both light and dark themes:

- **Background Colors**: Primary and secondary backgrounds
- **Text Colors**: Primary and secondary text colors
- **Toggle Icon Colors**: Custom colors for the theme toggle button
- **Font Size**: Adjustable base font size (12px to 20px)

## 🎨 Frontend Features

### Chat Widget

The frontend chat widget provides:

- **Responsive Design**: Works on all device sizes
- **Theme Toggle**: Switch between light and dark themes
- **Minimize/Maximize**: Collapsible chat interface
- **Real-time Responses**: Live AI assistant responses
- **Message History**: Persistent conversation threads
- **Loading States**: Visual feedback during processing

### Accessibility

- **ARIA Labels**: Screen reader support
- **Keyboard Navigation**: Full keyboard accessibility
- **Focus Management**: Proper focus handling
- **High Contrast**: Support for high contrast themes

## 🔧 API Endpoints

The extension provides several API endpoints:

- `POST /ai-chat/send` - Send a message to the assistant
- `GET /ai-chat/history` - Retrieve conversation history
- `GET /ai-chat/token` - Get CSRF token for requests
- `POST /contao/api-key-validate` - Validate OpenAI API key

## 🛡️ Security Features

- **CSRF Protection**: All requests protected with CSRF tokens
- **API Key Encryption**: Secure storage of sensitive data
- **Environment Variables**: Support for secure API key storage via environment variables
- **Input Validation**: Comprehensive input sanitization
- **Rate Limiting**: Protection against abuse
- **Session Security**: Secure session management
- **Template Security**: Twig templates with automatic escaping
- **Asset Security**: Static asset loading with proper paths

### 🔐 API Key Security

The extension supports multiple levels of API key security:

1. **Environment Variables** (Recommended) - Store keys in `.env.local` or system environment
2. **Encrypted Database Storage** - Fallback with AES-256-CBC encryption
3. **Real-time Validation** - API keys validated with OpenAI on save

📖 **For detailed security documentation, see [docs/security/api-key-management.md](docs/security/api-key-management.md)**

## 🗄️ Database Tables

The extension creates three main database tables:

- **`tl_openai_config`**: Stores OpenAI API configurations
- **`tl_openai_assistants`**: Stores assistant configurations
- **`tl_openai_files`**: Stores uploaded file metadata

## 📚 Documentation

Comprehensive documentation is available in the [docs/](docs/) directory:

- **[Installation Guide](docs/installation.md)** - Complete setup instructions
- **[OpenAI Setup](docs/configuration/openai-setup.md)** - API configuration guide
- **[Troubleshooting](docs/development/troubleshooting.md)** - Common issues and solutions
- **[Security](docs/security/encryption.md)** - Security features and encryption
- **[Model Selection](docs/technical/model-selection.md)** - AI model compatibility
- **[API Reference](docs/development/api-reference.md)** - Backend API endpoints
- **[Frontend Guide](docs/frontend/chatbot-module.md)** - Chatbot customization

## 🐛 Troubleshooting

For detailed troubleshooting information, see [docs/development/troubleshooting.md](docs/development/troubleshooting.md).

### Quick Fixes

1. **API Key Issues**: Check [OpenAI Setup Guide](docs/configuration/openai-setup.md)
2. **Model Problems**: See [Model Selection Guide](docs/technical/model-selection.md)
3. **Security Concerns**: Review [Security Documentation](docs/security/encryption.md)
4. **Frontend Issues**: Check [Frontend Guide](docs/frontend/chatbot-module.md)

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

### Quick Start for Contributors

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Add tests if applicable
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Submit a pull request

### Development Setup

For local development:

```bash
# Clone the repository
git clone https://github.com/juhe-it-solutions/contao-openai-assistant.git

# Install dependencies
composer install

# Set up your Contao development environment
# Ensure you have a valid OpenAI API key for testing
```

## 📄 License

This extension is licensed under the LGPL-3.0-or-later license. See [LICENSE](LICENSE) for details.

## Security

If you discover a security vulnerability, please report it privately via email to [office@juhe-it-solutions.at]. Do **not** open public issues for security problems.

## Maintainers

- [JUHE IT-solutions](https://github.com/juhe-it-solutions) – office@juhe-it-solutions.at

## 🙏 Acknowledgments

- **Contao Team**: For the excellent CMS framework
- **OpenAI**: For the powerful Assistants API
- **Community**: For feedback and contributions

---

**Note**: This extension requires a valid OpenAI API key with Assistants API access. Please ensure you comply with OpenAI's terms of service and usage policies.