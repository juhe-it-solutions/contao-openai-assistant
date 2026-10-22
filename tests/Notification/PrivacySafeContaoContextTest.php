<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Notification;

use Contao\CoreBundle\Monolog\ContaoContext;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\PrivacySafeContaoContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class PrivacySafeContaoContextTest extends TestCase
{
    public function testOverridesEveryFieldContaoWouldFillFromTheVisitor(): void
    {
        $server = [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Visitor',
            'REMOTE_ADDR' => '203.0.113.7',
        ];

        $request = Request::create('https://example.com/ai-chat/send?x=1&email=jane@example.org', 'POST', [], [], [], $server);

        $context = PrivacySafeContaoContext::create('Foo::bar', ContaoContext::ERROR, $request);

        $this->assertSame('N/A', $context->getBrowser());
        $this->assertSame('N/A', $context->getUsername());
        $this->assertSame('/ai-chat/send', $context->getUri());
        $this->assertSame('Foo::bar', $context->getFunc());
        $this->assertSame(ContaoContext::ERROR, $context->getAction());
        $this->assertNull($context->getIp());
    }

    public function testKeepsTheBaseUrlButNeitherHostNorQueryString(): void
    {
        $server = [
            'SCRIPT_FILENAME' => '/var/www/public/app.php',
            'SCRIPT_NAME' => '/app.php',
            'PHP_SELF' => '/app.php/ai-chat/send',
        ];

        $request = Request::create('https://example.com/app.php/ai-chat/send?x=1', 'POST', [], [], [], $server);

        $uri = PrivacySafeContaoContext::create('Foo::bar', ContaoContext::GENERAL, $request)->getUri();

        $this->assertSame('/app.php/ai-chat/send', $uri);
        $this->assertStringNotContainsString('example.com', (string) $uri);
        $this->assertStringNotContainsString('x=1', (string) $uri);
    }

    public function testWithoutARequestTheUriIsNotAvailable(): void
    {
        $context = PrivacySafeContaoContext::create('Foo::bar', ContaoContext::GENERAL, null);

        $this->assertSame('N/A', $context->getUri());
        $this->assertSame('N/A', $context->getBrowser());
        $this->assertSame('N/A', $context->getUsername());
        $this->assertSame(ContaoContext::GENERAL, $context->getAction());
    }

    public function testAKeptPathIsUsedAsIs(): void
    {
        $context = PrivacySafeContaoContext::createForPath('Foo::bar', ContaoContext::ERROR, '/ai-chat/send');

        $this->assertSame('/ai-chat/send', $context->getUri());
        $this->assertSame('N/A', PrivacySafeContaoContext::createForPath('Foo::bar', ContaoContext::ERROR, null)->getUri());
    }
}
