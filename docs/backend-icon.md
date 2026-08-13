# Backend Icon

The AI tools backend group uses the bundled SVG icon and backend CSS.

Current files:

- `public/icons/ai-tools.svg`
- `public/css/backend.css`
- `contao/config/config.php`
- `src/EventListener/BackendMenuListener.php`

`contao/config/config.php` registers the backend modules and loads the backend CSS/JavaScript assets. `BackendMenuListener` adds the premium vector-store auto-update navigation entry because it points to a custom backend route.
