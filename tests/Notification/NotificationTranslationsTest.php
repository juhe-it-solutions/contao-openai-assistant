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

use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotificationTestResult;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mail texts live in their own language file so they are only loaded when a mail is
 * composed; a key missing in one language would put the raw key into a customer's inbox.
 */
class NotificationTranslationsTest extends TestCase
{
    public function testTheMailTextsAreNotInTheDefaultLanguageFile(): void
    {
        foreach (['en', 'de'] as $locale) {
            $this->assertStringNotContainsString('oaa_notify', (string) file_get_contents($this->languageDir().'/'.$locale.'/default.xlf'));
        }
    }

    public function testEveryKeyTheComposerUsesExistsInBothLanguages(): void
    {
        $en = XlfTranslator::load('oaa_notify', 'en');
        $de = XlfTranslator::load('oaa_notify', 'de');

        foreach ($this->usedMailKeys() as $key) {
            $this->assertArrayHasKey($key, $en, $key.' is missing in en');
            $this->assertArrayHasKey($key, $de, $key.' is missing in de');
        }
    }

    public function testEveryBackendKeyExistsInBothLanguages(): void
    {
        $keys = ['admin_notify_legend', 'admin_notify_enabled.0', 'admin_notify_enabled.1', 'admin_notify_recipients.0', 'admin_notify_recipients.1', 'admin_notify_recipients_required', 'admin_notify_recipients_too_many', 'admin_notify_language.0', 'admin_notify_language.1', 'admin_notify_language_options.de', 'admin_notify_language_options.en', 'admin_notify_test_button', 'admin_notify_test_hint', 'admin_notify_test_sending'];

        foreach (AdminNotificationTestResult::cases() as $result) {
            $keys[] = substr($result->translationKey(), \strlen('tl_openai_config.'));
        }

        foreach (['en', 'de'] as $locale) {
            $messages = XlfTranslator::load('tl_openai_config', $locale);

            foreach ($keys as $key) {
                $this->assertArrayHasKey('tl_openai_config.'.$key, $messages, $key.' is missing in '.$locale);
            }
        }
    }

    #[DataProvider('provideLanguageFiles')]
    public function testBothLanguagesHaveTheSameKeysAndPlaceholders(string $file, string $prefix): void
    {
        $en = $this->filter(XlfTranslator::load($file, 'en'), $prefix);
        $de = $this->filter(XlfTranslator::load($file, 'de'), $prefix);

        $this->assertNotEmpty($en);
        $this->assertSame(array_keys($en), array_keys($de));

        foreach ($en as $key => $text) {
            $this->assertSame($this->placeholders($text), $this->placeholders($de[$key]), $key);
        }
    }

    #[DataProvider('provideLanguageFiles')]
    public function testNoEnOrEmDashes(string $file, string $prefix): void
    {
        foreach (['en', 'de'] as $locale) {
            foreach ($this->filter(XlfTranslator::load($file, $locale), $prefix) as $key => $text) {
                $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text, $locale.': '.$key);
            }
        }
    }

    public static function provideLanguageFiles(): iterable
    {
        yield 'mail texts' => ['oaa_notify', 'oaa_notify.'];
        yield 'backend texts' => ['tl_openai_config', 'tl_openai_config.admin_notify_'];
    }

    /**
     * @return list<string>
     */
    private function usedMailKeys(): array
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Notification/AdminNotificationComposer.php');
        // Literal keys only; the reason_/action_ keys are built from the enum below.
        preg_match_all("/\\\$this->t\\('([a-z_]+)',/", $source, $matches);

        $keys = $matches[1];

        foreach (ChatFailureReason::cases() as $reason) {
            $keys[] = 'reason_'.$reason->value;
            $keys[] = 'action_'.$reason->value;
        }

        $keys[] = 'language_de';
        $keys[] = 'language_en';

        $this->assertGreaterThan(20, \count($keys), 'The key scanner is broken, not the language files.');

        return array_map(static fn (string $key): string => 'oaa_notify.'.$key, array_values(array_unique($keys)));
    }

    /**
     * @param array<string, string> $messages
     *
     * @return array<string, string>
     */
    private function filter(array $messages, string $prefix): array
    {
        return array_filter($messages, static fn (string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\d+\$s/', $text, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }

    private function languageDir(): string
    {
        return \dirname(__DIR__, 2).'/contao/languages';
    }
}
