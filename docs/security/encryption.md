# Encryption System

This document describes the encryption system used to secure OpenAI API keys in the Contao OpenAI Assistant Bundle.

## Overview

The bundle uses AES-256-CBC encryption to secure API keys stored in the database. This ensures that sensitive credentials are not stored in plain text.

## Encryption Method

### Key Generation

The encryption key is generated using a combination of server-specific information:

```php
$serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/';
$encryptionKey = hash('sha256', $serverName . $documentRoot, true);
```

This approach ensures that:
- Each server has a unique encryption key
- The key is deterministic (same server = same key)
- No external dependencies are required

### Encryption Process

1. **Generate IV**: A random 16-byte initialization vector
2. **Encrypt Data**: Use AES-256-CBC with the generated key and IV
3. **Combine**: IV + encrypted data
4. **Encode**: Base64 encode the combined data

```php
$iv = random_bytes(16);
$encrypted = openssl_encrypt($apiKey, 'AES-256-CBC', $encryptionKey, 0, $iv);
$combined = $iv . $encrypted;
$encoded = base64_encode($combined);
```

### Decryption Process

1. **Decode**: Base64 decode the stored data
2. **Extract IV**: First 16 bytes are the IV
3. **Decrypt**: Use AES-256-CBC with the key and IV
4. **Return**: Decrypted API key

```php
$data = base64_decode($encoded);
$iv = substr($data, 0, 16);
$encrypted = substr($data, 16);
$decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);
```

## Security Features

### Key Strengths

- **AES-256-CBC**: Industry-standard encryption algorithm
- **Unique IV**: Each encryption uses a random initialization vector
- **Server-specific keys**: Keys are tied to the server environment
- **No key storage**: Encryption keys are generated on-demand

### Limitations

- **Server dependency**: Encrypted data cannot be moved between servers
- **Backup considerations**: Encrypted data must be restored to the same server
- **Key regeneration**: Server changes require re-encryption

## Implementation

### Encryption Service

The bundle includes a centralized `EncryptionService` class:

```php
namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

class EncryptionService
{
    public function encrypt(string $data): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $encryptedData): ?string
    {
        $key = $this->getEncryptionKey();
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    private function getEncryptionKey(): string
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/';
        return hash('sha256', $serverName . $documentRoot, true);
    }
}
```

### Usage in Controllers

```php
// Encrypt API key before saving
$encryptedKey = $this->encryptionService->encrypt($apiKey);

// Decrypt API key when needed
$apiKey = $this->encryptionService->decrypt($encryptedKey);
```

## Migration from Legacy

The bundle supports migration from legacy base64 encoding:

```php
private function processApiKey(string $storedApiKey): ?string
{
    if (empty($storedApiKey)) {
        return null;
    }
    
    // Check if this is an encrypted key (longer than 100 chars)
    if (strlen($storedApiKey) > 100) {
        return $this->encryptionService->decrypt($storedApiKey);
    } else {
        // Legacy base64 encoded key
        return base64_decode($storedApiKey);
    }
}
```

## Best Practices

### For Developers

1. **Always use the EncryptionService**: Don't implement encryption manually
2. **Handle decryption failures**: API keys may be corrupted or from different servers
3. **Log encryption errors**: Monitor for encryption/decryption issues
4. **Test migration**: Verify legacy data migration works correctly

### For System Administrators

1. **Backup encrypted data**: Include encrypted API keys in backups
2. **Server consistency**: Maintain consistent server names and document roots
3. **Migration planning**: Plan for server changes that require re-encryption
4. **Security monitoring**: Monitor for encryption-related errors

## Troubleshooting

### Common Issues

1. **Decryption fails**: Usually indicates server environment changes
2. **Legacy data**: Old base64 encoded keys are automatically handled
3. **Key length**: Encrypted keys are significantly longer than plain text

### Debugging

Enable debug logging to monitor encryption operations:

```php
$this->logger->debug('Encryption operation', [
    'operation' => 'encrypt',
    'key_length' => strlen($apiKey),
    'encrypted_length' => strlen($encryptedKey)
]);
```

## Security Considerations

- **Key rotation**: Consider re-encrypting API keys periodically
- **Access control**: Limit access to encryption service
- **Audit logging**: Log encryption/decryption operations
- **Error handling**: Don't expose encryption details in error messages

This encryption system provides a robust foundation for securing sensitive API credentials while maintaining compatibility with existing installations. 