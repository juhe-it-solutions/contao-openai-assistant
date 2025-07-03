# Model Selection

This document describes the model selection functionality in the Contao OpenAI Assistant Bundle, including dynamic model retrieval and compatibility validation.

## Overview

The bundle provides intelligent model selection that automatically fetches all available models from your OpenAI account and validates their compatibility with the Assistants API during the save process.

## Dynamic Model Retrieval

### How It Works

1. **API Fetch**: When creating/editing an assistant, the system fetches all models from OpenAI's `/v1/models` endpoint
2. **Fast Loading**: All models are displayed immediately without validation to ensure fast UI response
3. **Save Validation**: Model compatibility is checked only when the user saves the assistant
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

The system validates model compatibility only when the user saves the assistant:

```php
// Validate that the selected model is compatible with Assistants API
$selectedModel = $dc->activeRecord->model;
if (!empty($selectedModel)) {
    if ($selectedModel === 'manual') {
        // For manual models, get the manual model value
        $manualModel = $dc->activeRecord->model_manual ?? '';
        if (empty($manualModel)) {
            $errorMessage = 'Please enter a custom model name when selecting manual override.';
            throw new \InvalidArgumentException($errorMessage);
        }
        $selectedModel = $manualModel;
    }
    
    // Validate the model against Assistants API
    if (!$this->validateModelForAssistants($selectedModel, $apiKey)) {
        $errorMessage = sprintf('The selected model "%s" is not compatible with the Assistants API. Please choose a different model.', $selectedModel);
        throw new \InvalidArgumentException($errorMessage);
    }
}
```

### Compatibility Testing

The system tests each model by attempting to create a temporary assistant:

```php
private function validateModelForAssistants(string $modelId, string $apiKey): bool
{
    try {
        // Try to create a minimal assistant with the model
        $testData = [
            'name' => 'test_assistant_' . time(),
            'instructions' => 'Test assistant for model validation',
            'model' => $modelId,
            'temperature' => 0.25
        ];
        
        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/assistants', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2'
            ],
            'json' => $testData,
            'timeout' => 30
        ]);
        
        $result = $response->toArray();
        
        // If successful, immediately delete the test assistant
        if (isset($result['id'])) {
            $this->httpClient->request('DELETE', 'https://api.openai.com/v1/assistants/' . $result['id'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'OpenAI-Beta' => 'assistants=v2'
                ],
                'timeout' => 10
            ]);
            return true;
        }
        
        return false;
        
    } catch (\Exception $e) {
        return false;
    }
}
```

### Known Incompatible Models

The system blocks models known to be incompatible:

```php
private function isAssistantCompatibleModel(string $modelId): bool
{
    // List of models known to be incompatible with Assistants API
    $incompatibleModels = [
        'chatgpt-4o-latest',  // Chat completion model, not assistants
        'chatgpt-4o-mini-latest',
        'chatgpt-3.5-turbo-latest',
        'dall-e-2',
        'dall-e-3',
        'whisper-1',
        'tts-1',
        'tts-1-hd',
        'text-embedding-ada-002',
        'text-embedding-3-small',
        'text-embedding-3-large'
    ];
    
    // If it's in the incompatible list, reject it
    if (in_array($modelId, $incompatibleModels)) {
        return false;
    }
    
    // For other models, be permissive and let the API handle validation
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
- Prevents creation of assistants with invalid models

## Performance Benefits

### Before (Slow)
- 30+ second loading time for assistant creation screen
- Individual API calls to validate each model
- Only compatible models shown in dropdown

### After (Fast)
- < 5 second loading time for assistant creation screen
- Single API call to fetch all models
- All models shown in dropdown
- Validation only when needed (during save)

## Error Handling

### Model Validation Errors

If a user selects an incompatible model:
1. Clear error message is displayed
2. Assistant creation is prevented
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
2. **Model Validation Fails**: Verify model compatibility with Assistants API
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