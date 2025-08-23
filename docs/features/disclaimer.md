# Disclaimer Feature

The AI Chatbot module now includes a configurable disclaimer feature that allows you to display important legal or informational text to users.

## Overview

The disclaimer feature adds an information icon (ℹ️) to the chat header, positioned between the title and the theme toggle button. When clicked, it opens a modal dialog displaying your configured disclaimer text.

## Configuration

### Backend Configuration

1. **Navigate to the AI Chatbot Module**: Go to **Layout → Modules** and edit your AI Chatbot module
2. **Find the Disclaimer Field**: In the "Chat Settings" section, you'll find the "Haftungsausschluss" (Disclaimer) field
3. **Configure the Text**: 
   - The field supports rich text editing (TinyMCE)
   - Default text is provided in German
   - You can customize the content as needed

### Default Disclaimer Text

The default disclaimer texts are stored in the translation files and automatically adapt to the current language:

**German** (`contao/languages/de/tl_module.xlf`):
```
Unser Chatbot ist ein Serviceangebot unseres Unternehmens und soll die Kommunikation sowie den Informationszugang erleichtern. Die Antworten werden automatisch generiert und dienen ausschließlich allgemeinen Informations- und Unterstützungszwecken. Trotz sorgfältiger Entwicklung können Inhalte unvollständig, missverständlich oder fehlerhaft sein. Wir übernehmen daher keine Gewähr für die inhaltliche Richtigkeit oder Vollständigkeit der Antworten. Verbindliche Auskünfte, individuelle Beratung oder rechtliche Empfehlungen werden durch den Chatbot nicht erteilt. Bitte nutze die bereitgestellten Informationen als Orientierung und wende dich für wichtige Anliegen direkt an unser Team oder an eine entsprechend qualifizierte Fachperson.
```

**English** (`contao/languages/en/tl_module.xlf`):
```
Our chatbot is a service provided by our company to facilitate communication and provide easier access to information. The responses are automatically generated and are intended solely for general informational and support purposes. Despite careful development, the content may be incomplete, unclear, or contain errors. We therefore cannot guarantee the accuracy or completeness of the information provided. The chatbot does not offer binding statements, individual advice, or legal recommendations. Please consider the responses as general guidance and contact our team or a qualified professional for important matters.
```

The system automatically uses the appropriate language based on the current Contao language setting.

## Frontend Behavior

### Visual Elements

- **Information Icon**: Appears in the chat header next to the theme toggle
- **Modal Dialog**: Opens when the icon is clicked
- **Theme Support**: The dialog respects the current light/dark theme
- **Responsive Design**: Adapts to mobile and desktop screen sizes

### User Interaction

- **Click to Open**: Click the information icon to view the disclaimer
- **Close Options**:
  - Click the X button in the top-right corner
  - Click outside the dialog
  - Press the Escape key
- **Accessibility**: Full keyboard navigation and screen reader support

### Auto-Focus Feature

The chatbot input field automatically receives focus in two scenarios:

1. **When Opening the Chat**: When users click the chatbot button to start a conversation, the input field automatically receives focus, allowing immediate typing
2. **After Bot Response**: When the bot finishes its answer, the input field automatically receives focus, enabling seamless continuation of the conversation

This feature works on both desktop and mobile devices, providing a smooth user experience.

## Technical Implementation

### Database Changes

The feature adds a new `disclaimer_text` column to the `tl_module` table:
- **Type**: TEXT (nullable)
- **Migration**: Automatically applied when the extension is updated

### CSS Classes

- `.ai-chat-disclaimer-toggle`: The information icon button
- `.ai-chat-disclaimer-dialog`: The modal dialog container
- `.ai-chat-disclaimer-content`: The dialog content wrapper
- `.ai-chat-disclaimer-header`: The dialog header with title and close button
- `.ai-chat-disclaimer-body`: The dialog body containing the disclaimer text

### JavaScript Functionality

The disclaimer feature includes:
- Modal dialog management
- Keyboard event handling (Escape key)
- Focus management for accessibility
- Body scroll prevention when dialog is open
- Auto-focus functionality for improved user experience

## Customization

### Styling

You can customize the appearance by overriding the CSS classes in your theme:

```css
/* Custom disclaimer button styling */
.ai-chat-disclaimer-toggle {
    /* Your custom styles */
}

/* Custom dialog styling */
.ai-chat-disclaimer-dialog {
    /* Your custom styles */
}
```

### Content

The disclaimer text supports:
- HTML formatting (via TinyMCE)
- Links and basic formatting
- Multi-language content
- Dynamic content based on your needs

## Best Practices

1. **Legal Compliance**: Ensure your disclaimer text meets legal requirements for your jurisdiction
2. **Clarity**: Use clear, understandable language
3. **Relevance**: Make the disclaimer specific to your use case
4. **Accessibility**: The feature is designed with accessibility in mind, but review your content for clarity
5. **Localization**: Consider providing disclaimer text in multiple languages if your site supports it

## Troubleshooting

### Common Issues

1. **Disclaimer not appearing**: Check that the module is properly configured and the disclaimer text is not empty
2. **Styling issues**: Ensure your theme CSS doesn't conflict with the disclaimer styles
3. **JavaScript errors**: Check browser console for any JavaScript errors that might prevent the dialog from opening
4. **Disclaimer field empty when creating new module**: This issue has been fixed in version 2025.08.23. The system now automatically loads the default disclaimer text when creating new modules. If you have existing modules with empty disclaimer fields, run the database migration to update them.
5. **Database migration error "Data truncated for column 'disclaimer_text'"**: This issue has been fixed in version 2025.01.27. The system now properly handles TEXT columns without database default values (which are not supported in MySQL) and uses application-level defaults instead.

### Support

If you encounter issues with the disclaimer feature, please:
1. Check the browser console for errors
2. Verify the module configuration
3. Test with the default disclaimer text
4. Create an issue in the project repository with details about the problem

## Recent Fixes

### Version 2025.01.27
- **Fixed**: Database migration issue causing "Data truncated for column 'disclaimer_text'" error in MySQL
- **Updated**: DCA configuration to use proper Doctrine schema representation for Contao 5.3+
- **Removed**: Database default values for TEXT columns (not supported in MySQL)
- **Enhanced**: Application-level default value handling for better MySQL compatibility
- **Improved**: Migration system to properly handle TEXT columns without database defaults

### Version 2025.08.23
- **Fixed**: Disclaimer field now properly loads default text when creating new modules (same behavior as "Willkommensnachricht" field)
- **Added**: Comprehensive database migration to update existing modules and add default value to disclaimer_text column
- **Improved**: Backend form now shows default disclaimer text for new modules
- **Enhanced**: Database column now has proper default value like other fields
- **Added**: Auto-focus functionality for input field when opening chat and after bot responses
