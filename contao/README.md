# OpenAI Assistant for Contao

A comprehensive OpenAI Assistant integration for Contao CMS that provides backend management and a modern frontend chatbot interface.

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

### Security & Performance
- **API Key Encryption**: Secure storage of OpenAI API keys
- **Rate Limiting**: Built-in protection against abuse
- **Error Handling**: Comprehensive error logging and user feedback
- **Session Management**: Thread-based conversation persistence

## 📋 Requirements

- **Contao**: 5.3 or higher (tested with Contao 5.5)
- **PHP**: 8.1 or higher
- **OpenAI API Key**: Valid OpenAI API key with Assistants API access
- **Composer**: For installation and dependency management

## 🛠️ Installation

### Using Contao Manager
1. Open Contao Manager
2. Search for "OpenAI Assistant" or "juhe-it-solutions/contao-openai-assistant"
3. Install the extension
4. Run migrations and clear cache

### Using Composer
```bash
composer require juhe-it-solutions/contao-openai-assistant
php bin/console contao:migrate
php bin/console cache:clear
```

## 🎯 Quick Setup

1. **Configure OpenAI**:
   - Go to **AI-TOOLS → OpenAI Dashboard**
   - Create OpenAI configuration with your API key
   - Validate your API key

2. **Upload Knowledge Base**:
   - Upload files containing your website/company information
   - Supported formats: PDF, TXT, MD, DOCX, XLSX, PPTX, JSON, CSV

3. **Create Assistant**:
   - Configure your AI assistant with custom instructions
   - Select from available OpenAI models
   - Adjust parameters as needed

4. **Add to Frontend**:
   - Create AI-Chatbot module in **Layout → Modules**
   - Add module to your page layout
   - Customize appearance and behavior

## 🔧 Configuration

### Backend Configuration
- **API Key Management**: Secure storage with encryption
- **File Upload**: Drag-and-drop interface for knowledge base files
- **Assistant Settings**: Custom instructions, model selection, parameters
- **Vector Store**: Automatic OpenAI vector store management

### Frontend Configuration
- **Chat Position**: Choose widget position (bottom-right, bottom-left, etc.)
- **Theme**: Light or dark mode with custom colors
- **Messages**: Customizable titles and welcome messages
- **Font Size**: Adjustable from 12px to 20px

## 🛡️ Security Features

- **API Key Encryption**: All OpenAI API keys are encrypted before storage
- **CSRF Protection**: Built-in protection against cross-site request forgery
- **Rate Limiting**: Protection against abuse and excessive API calls
- **Session Management**: Secure conversation thread management
- **Input Validation**: Comprehensive validation of all user inputs

## 📚 Documentation

For detailed documentation, visit our [GitHub repository](https://github.com/juhe-it-solutions/contao-openai-assistant/tree/main/docs).

## 🤝 Support

- **Issues**: [GitHub Issues](https://github.com/juhe-it-solutions/contao-openai-assistant/issues)
- **Documentation**: [GitHub Docs](https://github.com/juhe-it-solutions/contao-openai-assistant/tree/main/docs)
- **Source Code**: [GitHub Repository](https://github.com/juhe-it-solutions/contao-openai-assistant)

## 📄 License

This extension is licensed under the LGPL-3.0-or-later license.

## 👨‍💻 Developer

Developed and maintained by [JUHE IT-solutions](https://github.com/juhe-it-solutions), Austria.

---

**Note**: This is the developer's first Contao extension. While thoroughly tested, please report any issues you encounter. Contributions and feedback are welcome! 