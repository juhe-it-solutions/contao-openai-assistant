# Model Selection

This document describes the model selection functionality in the Contao OpenAI Assistant Bundle, including dynamic model retrieval and compatibility validation.

## Overview

The bundle provides intelligent model selection that automatically fetches all available models from your OpenAI account and validates their compatibility with the **Responses API** during the save process.

## Dynamic Model Retrieval

### How It Works

1. **API Fetch**: When creating/editing a prompt, the system fetches all models from OpenAI's `/v1/models` endpoint
2. **Fast Loading**: All models are displayed immediately without validation to ensure fast UI response
3. **Save Validation**: Model compatibility is checked only when the user saves the prompt
4. **Fallback System**: If API is unavailable, falls back to known compatible models
5. **User Selection**: Users can choose from all available models or enter custom model names

### Implementation

```php
public function getAvailableModels(DataContainer $dc = null): array
{
    $models = [];

    // Try to get models from API if we have a DataContainer context
    if ($dc && $dc->activeRecord && $dc->activeRecord->pid) {
        try {
            $apiKey = $this->getApiKeyFromEnvironment($dc->activeRecord->pid);

            if ($apiKey) {
                $models = $this->fetchAllModelsFromApi($apiKey);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch models from API, using fallback');
        }
    }

    // If no models from API, use fallback
    if (empty($models)) {
        $models = $this->getFallbackModels();
    }

    // Add manual override option
    $models['manual'] = '-- Enter Custom Model --';

    return $models;
}
```

## Model Compatibility

### Save-Time Validation

The system validates model compatibility only when the user saves the prompt. Validation is performed by sending a minimal "ping" request to `POST /v1/responses` with the candidate model. If the API rejects the model, the error message surfaces in the backend:

```php
// Pseudocode from OpenAiPromptsListener::validateModelViaApi()
$response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
    'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type'  => 'application/json',
    ],
    'json' => [
        'model'             => $selectedModel,
        'input'             => 'ping',
        'max_output_tokens' => 16,
        'store'             => false,
    ],
    'timeout' => 30,
]);

$statusCode = $response->getStatusCode();
if ($statusCode >= 200 && $statusCode < 300) {
    return true; // model accepted
}

return false; // surface the error message to the backend user
```

This replaces the legacy approach of creating a temporary Assistant (`POST /v1/assistants`) that was used in v1.x. The new validation is cheaper (1 request instead of 2), does not require the `OpenAI-Beta: assistants=v2` header, and works for any model that is accepted by the Responses API.

### Known Incompatible Models

The system blocks models known to be incompatible with chat-style Responses (embedding, audio, image generation models):

```php
private function isResponsesCompatibleModel(string $modelId): bool
{
    $incompatibleModels = [
        'dall-e-2',
        'dall-e-3',
        'whisper-1',
        'tts-1',
        'tts-1-hd',
        'text-embedding-ada-002',
        'text-embedding-3-small',
        'text-embedding-3-large',
    ];

    if (in_array($modelId, $incompatibleModels, true)) {
        return false;
    }

    return true;
}
```

## Fallback Models

When the API is unavailable, the system provides these reliable fallback models:

```php
private function getFallbackModels(): array
{
    return [
        'gpt-4o' => 'gpt-4o',
        'gpt-4o-mini' => 'gpt-4o-mini',
        'gpt-3.5-turbo' => 'gpt-3.5-turbo'
    ];
}
```

## Model Display Formatting

Models are displayed with their technical names for clarity:

```php
private function formatModelName(string $modelId): string
{
    // Return the technical model name directly
    return $modelId;
}
```

## User Interface Features

### Model Selection Dropdown

- **All Models**: Fetched from OpenAI API without filtering
- **Technical Names**: Displayed as-is for clarity
- **Fast Loading**: No validation during dropdown population
- **Manual Option**: Allows custom model entry

### Manual Model Entry

When "Enter Custom Model" is selected:
- A text field appears for manual model entry
- The entered model is validated during save
- Supports any OpenAI model name

### Save-Time Validation

- Model compatibility is checked only when saving
- Clear error messages for incompatible models
- Prevents creation of prompts with invalid models

## Performance Benefits

### Before (Slow)
- 30+ second loading time for prompt creation screen
- Individual API calls to validate each model
- Only compatible models shown in dropdown

### After (Fast)
- < 5 second loading time for prompt creation screen
- Single API call to fetch all models
- All models shown in dropdown
- Validation only when needed (during save) via a single `/v1/responses` ping

## Error Handling

### Model Validation Errors

If a user selects an incompatible model:
1. Clear error message is displayed
2. Prompt creation is prevented
3. User can select a different model
4. Manual model entry is validated the same way

### API Errors

If the OpenAI API is unavailable:
1. Fallback models are used
2. User is informed via info message
3. Manual model entry is still available

## Benefits

### For End Users

- **Future-Proof**: New models work automatically
- **Clarity**: Clear model names and descriptions
- **Guidance**: Helpful information and links
- **Reliability**: Fallback options when API unavailable

### For Developers

- **Maintainability**: Clean, modular code structure
- **Extensibility**: Easy to add new models or features
- **Debugging**: Comprehensive logging
- **Testing**: Well-defined methods for unit testing

## Troubleshooting

### Common Issues

1. **No Models Showing**: Check API key validity and network connectivity
2. **Model Validation Fails**: Verify model compatibility with the Responses API (`POST /v1/responses`)
3. **Slow Loading**: API calls may take time; consider caching

### Debugging

Enable debug logging to monitor model operations:

```php
$this->logger->debug('Model validation', [
    'model_id' => $modelId,
    'compatible' => $isCompatible,
    'api_response' => $response
]);
```

## Future Enhancements

### Potential Improvements

1. **Model Caching**: Cache API results to reduce API calls
2. **Model Categories**: Group models by type (GPT-4, GPT-3.5, etc.)
3. **Model Recommendations**: Suggest optimal models based on use case
4. **Cost Information**: Display model pricing information
5. **Performance Metrics**: Show model performance characteristics

### Configuration Options

1. **Custom Model Lists**: Allow admins to define custom model sets
2. **Model Restrictions**: Restrict users to specific model subsets
3. **API Rate Limiting**: Implement rate limiting for API calls
4. **Offline Mode**: Enhanced offline functionality

This model selection system ensures users always have access to the latest compatible models while providing a robust fallback system for reliability.
