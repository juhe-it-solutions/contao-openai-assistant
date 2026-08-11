# HTML in the chat texts

Three fields of the frontend module **AI tools -> AI-Chatbot** accept HTML:

| Field (German backend label) | Rendered as | Markup allowed |
| --- | --- | --- |
| **Chat-Titel** | the widget header `<h3>` | text-level only |
| **Willkommensnachricht** | the header `<p>` under the title | text-level only |
| **Erste Bot-Nachricht** | the first chat bubble | text-level and blocks |

The **Disclaimer** field is a TinyMCE field and has always rendered HTML; see [disclaimer.md](disclaimer.md).

## What survives

Text-level: `a`, `abbr`, `b`, `br`, `code`, `em`, `i`, `mark`, `s`, `small`, `span`, `strong`, `sub`, `sup`, `u`.

Blocks (first bot message only): `blockquote`, `div`, `h1`-`h6`, `hr`, `ol`, `ul`, `li`, `p`.

A `class` attribute is kept on all of them, so the texts can be styled through the module's own stylesheet. Links keep `href`, `title` and `target`, may point at `http`, `https`, `mailto`, `tel` or a path on your own site, and always get `rel="noopener noreferrer"`.

Everything else is removed before the text reaches the browser: `script`, `iframe`, event handlers such as `onclick`, `javascript:` addresses and inline `style` attributes. In the two header fields a block element loses its tag but keeps its text, so a pasted `<p>Titel</p>` still shows "Titel" instead of breaking the header.

## Plain text keeps working as before

A first bot message without any tag is processed like a chat answer: web addresses, e-mail addresses and phone numbers become links, and the **"Links kürzen"** setting applies to them. Write the text with HTML and you are in charge of the links yourself.

## Answers from the AI model are not affected

Only these editor-entered fields are treated as HTML. Everything the model sends back is escaped in the browser, so markup in an answer is displayed as text and never executed. That escaping arrived in version 2.1.0 and is unchanged.

## Contao versions

The behaviour is identical on Contao 5.3, 5.7 and 6.0, and it is verified against the escaping of all three. The fields carry `allowHtml` in the DCA, which on Contao 5.3/5.7 stops the input encoder from turning `<` into `&#60;` and on Contao 6 switches on Contao's input sanitizing. Independently of that, the extension decodes and sanitizes the values again when rendering them.

Nothing has to be re-entered after the update: values that Contao 5.3/5.7 stored in their encoded form (`&#60;br&#62;`) are decoded before sanitizing, so an existing text formats itself right away.

If you added an own `allowHtml` snippet in `contao/dca/tl_module.php` to work around this, you can delete it.

## Custom chat templates

A copy of `ai_chat_module.html.twig` made before version 2.2.0 prints the title and the welcome line escaped (`{{ chat_title }}` instead of `{{ chat_title|raw }}`), so HTML in those two fields shows up as text until the copy is updated. The first bot message works in old copies too - it travels in the `data-initial-message` attribute, which has not changed. Values reaching a custom template are always sanitized already.
