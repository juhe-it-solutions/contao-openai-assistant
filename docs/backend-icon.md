# Backend Menu Icon Implementation

This document explains how the AI Tools backend menu icon is implemented in this Contao 5 extension.

## Overview

The extension adds a custom SVG icon to the "AI TOOLS" menu group in the Contao 5 backend. This follows the official Contao 5 way of implementing backend menu icons.

## Files Involved

### 1. SVG Icon
- **Location**: `public/icons/ai-tools.svg`
- **Purpose**: Defines the visual icon for the AI Tools menu group
- **Design**: Robot/AI-themed icon that represents artificial intelligence tools

### 2. CSS Styling
- **Location**: `public/css/backend.css`
- **Purpose**: Applies the icon to the backend menu using CSS selectors
- **Target**: `#tl_navigation .group-ai_tools` elements

### 3. Event Listener
- **Location**: `src/EventListener/UserNavigationListener.php`
- **Purpose**: Injects the CSS file into the backend navigation
- **Event**: `NavigationEvent` from Contao Core Bundle

### 4. Backend Configuration
- **Location**: `contao/backend/modules.php`
- **Purpose**: Defines the backend modules and their group icon
- **Configuration**: Sets up the `ai_tools` menu group with its icon

### 5. Service Registration
- **Location**: `config/services.yaml`
- **Purpose**: Registers the UserNavigationListener as a service
- **Dependencies**: Injects the RequestStack service

## Implementation Details

### CSS Approach
The CSS uses multiple selectors to ensure compatibility across different Contao versions:

```css
/* Primary approach */
#tl_navigation .group-ai_tools .group-icon {
    background-image: url('../icons/ai-tools.svg') !important;
    /* ... other styles */
}

/* Fallback approach */
#tl_navigation .group-ai_tools::before {
    content: '';
    background-image: url('../icons/ai-tools.svg');
    /* ... other styles */
}
```

### Event Listener Pattern
The UserNavigationListener follows the same pattern used in the contao-leads extension:

1. Listens to the `NavigationEvent`
2. Checks if the request is a backend request
3. Adds the CSS file to the navigation node

### Bundle Configuration
The bundle automatically loads the CSS file through the public directory configuration in `ContaoOpenaiAssistantBundle.php`.

## Usage

After installation, the AI Tools menu group will automatically display the custom icon in the Contao 5 backend navigation.

## Customization

To customize the icon:

1. Replace `public/icons/ai-tools.svg` with your own SVG
2. Adjust the CSS in `public/css/backend.css` if needed
3. Clear the Contao cache

## References

This implementation follows the pattern used in the [contao-leads extension](https://github.com/terminal42/contao-leads) and adheres to Contao 5 best practices for backend customization. 