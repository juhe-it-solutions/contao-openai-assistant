# Disclaimer Feature

The frontend AI chatbot can show a disclaimer dialog from an information button in the chat header.

## Configure

Edit the frontend module of type **AI tools -> AI-Chatbot** and set **Disclaimer**. The field supports TinyMCE content.

If the field is empty, the frontend uses the default disclaimer from the chat language files. German and English defaults are available and follow the visitor's `Accept-Language` header.

## Frontend Behavior

- The information button opens a native modal `<dialog>` via `showModal()`, so the platform owns the focus trap, Escape to close, inert background and top-layer stacking.
- The dialog follows the selected light or dark chat theme.
- Visitors can close it with the close button, a tap or click on the backdrop, or Escape. The close control stays on screen while the text scrolls.
- Long disclaimer text is a named, keyboard-scrollable region. Initial focus enters that region so screen readers can navigate links, lists and paragraphs with their structure intact; the close button remains one backward tab away.
- On phones the dialog fills the visible viewport and respects notches, the home indicator and the software keyboard. Short landscape viewports grow the card instead of clipping it. The page behind it cannot scroll, including on iOS.
- Custom module templates that still use a `div` overlay are handled by the same script as a fallback.

## Notes

Review disclaimer content for your jurisdiction and use case. The bundled default is generic and not legal advice.
