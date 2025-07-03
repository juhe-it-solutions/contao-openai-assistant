<?php

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Psr\Log\LoggerInterface;

class EncryptionService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Generate encryption key (consistent across all services)
     */
    public function getEncryptionKey(): string
    {
        // Generate the same encryption key as in other services
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/';
        return hash('sha256', $serverName . $documentRoot, true);
    }
    
    /**
     * Encrypt API key for storage
     */
    public function encryptApiKey(string $apiKey): string
    {
        $key = $this->getEncryptionKey();
        $method = 'aes-256-cbc';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        
        $encrypted = openssl_encrypt($apiKey, $method, $key, 0, $iv);
        
        // Combine IV and encrypted data
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt API key from storage
     */
    public function decryptApiKey(string $encryptedData): ?string
    {
        try {
            $key = $this->getEncryptionKey();
            $method = 'aes-256-cbc';
            
            $data = base64_decode($encryptedData);
            $ivLength = openssl_cipher_iv_length($method);
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);
            
            $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);
            
            return $decrypted !== false ? $decrypted : null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt API key: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Process API key - decrypt if encrypted, decode if base64
     */
    public function processApiKey(string $storedApiKey): ?string
    {
        if (empty($storedApiKey)) {
            return null;
        }
        
        // Check if this is an encrypted key (longer than 100 chars) or legacy base64
        if (strlen($storedApiKey) > 100) {
            // This is an encrypted key
            $apiKey = $this->decryptApiKey($storedApiKey);
        } else {
            // This is a legacy base64 encoded key
            $apiKey = base64_decode($storedApiKey);
        }
        
        if (!$apiKey || !$this->isValidApiKeyFormat($apiKey)) {
            $this->logger->error('Invalid API key format detected', [
                'api_key_length' => strlen($storedApiKey),
                'api_key_prefix' => substr($storedApiKey, 0, 10)
            ]);
            return null;
        }
        
        return $apiKey;
    }

    /**
     * Validate API key format - supports all OpenAI key formats
     */
    public function isValidApiKeyFormat(string $apiKey): bool
    {
        if (empty($apiKey)) {
            return false;
        }
        
        // Support all current OpenAI API key formats
        $validPrefixes = ['sk-', 'sk-proj-', 'sk-None-', 'sk-svcacct-'];
        
        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($apiKey, $prefix)) {
                return true;
            }
        }
        
        return false;
    }
} 