<?php

declare(strict_types=1);

// OpenAI Configuration - Load this before any OpenAI service usage
if (!defined('OPENAI_CONFIG_LOADED')) {
    define('OPENAI_CONFIG_LOADED', true);
    
    // Load environment variables from .env.local or system environment
    $dotenv = \Symfony\Component\Dotenv\Dotenv::createImmutable(\System::getContainer()->getParameter('kernel.project_dir'));
    if (file_exists(\System::getContainer()->getParameter('kernel.project_dir') . '/.env.local')) {
        $dotenv->load();
    }
}
