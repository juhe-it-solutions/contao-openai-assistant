# Quick Start Guide

Get your Contao OpenAI Assistant Bundle up and running in minutes!

## 🚀 5-Minute Setup

### Step 1: Install the Bundle

```bash
composer require juhe-it-solutions/contao-openai-assistant
php bin/console contao:migrate
php bin/console cache:clear
```

### Step 2: Get Your OpenAI API Key

1. Go to [OpenAI Platform](https://platform.openai.com/)
2. Sign up or log in
3. Navigate to **API Keys**
4. Click **Create new secret key**
5. Copy the key (starts with `sk-`)

### Step 3: Configure in Contao Backend

1. Log into your Contao backend
2. Go to **System → KI-TOOLS → OpenAI Dashboard**
3. Click **New** to create configuration
4. Enter your API key and test connection
5. Save the configuration

### Step 4: Create Your First Assistant

1. In the OpenAI Dashboard, click **Assistants**
2. Click **New** to create assistant
3. Fill in:
   - **Name**: "My First Assistant"
   - **Instructions**: "You are a helpful assistant for my website."
   - **Model**: Choose GPT-4o or GPT-4o-mini
4. Save the assistant

### Step 5: Add Chatbot to Your Website

1. Go to **Layout → Modules**
2. Click **New** and select **KI-Chatbot**
3. Configure:
   - **Assistant**: Select your created assistant
   - **Position**: Bottom-right
   - **Theme**: Light or dark
4. Save the module
5. Add it to your page layout

## ✅ You're Done!

Your chatbot should now appear on your website! Users can:
- Click the chat icon to open the conversation
- Ask questions and get AI-powered responses
- Have conversations that persist during their session

## 🎯 Next Steps

### Add Knowledge Base (Optional)

1. In OpenAI Dashboard, go to **Files**
2. Upload PDF, DOCX, or TXT files
3. The assistant will use these for more accurate responses

### Customize Appearance

1. Edit your chatbot module
2. Adjust colors, position, and messages
3. Test different themes and settings

### Monitor Usage

1. Check OpenAI Dashboard for usage statistics
2. Monitor conversations in Contao backend
3. Adjust settings based on performance

## 🐛 Common Quick Issues

### Chatbot Not Appearing
- Check if module is added to page layout
- Clear browser cache
- Verify module is published

### API Key Error
- Ensure key starts with `sk-`
- Check for extra spaces
- Verify key is valid in OpenAI platform

### Assistant Not Responding
- Check assistant is set to "active"
- Verify API key has sufficient credits
- Test connection in backend

## 📚 Need More Help?

- **[Full Installation Guide](installation.md)** - Detailed setup instructions
- **[OpenAI Setup](configuration/openai-setup.md)** - Complete API configuration
- **[Troubleshooting](development/troubleshooting.md)** - Common issues and solutions
- **[Configuration Guide](configuration/assistants.md)** - Advanced assistant setup

## 🎉 Congratulations!

You've successfully set up an AI-powered chatbot for your Contao website! The bundle provides a solid foundation that you can build upon with customizations and additional features. 