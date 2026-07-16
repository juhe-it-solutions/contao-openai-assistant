# Disclaimer Feature

The frontend AI chatbot can show a disclaimer dialog from an information button in the chat header.

## Configure

Edit the frontend module of type **AI tools -> AI-Chatbot** and set **Disclaimer**. The field supports TinyMCE content.

If the field is empty, the frontend uses the default disclaimer from the chat language files. German and English defaults are available and follow the visitor's `Accept-Language` header.

## Frontend Behavior

- The information button opens a modal dialog.
- The dialog follows the selected light or dark chat theme.
- Users can close it with the close button, outside click or Escape key.
- The implementation includes keyboard and focus handling for accessibility.

## Notes

Review disclaimer content for your jurisdiction and use case. The bundled default is generic and not legal advice.
