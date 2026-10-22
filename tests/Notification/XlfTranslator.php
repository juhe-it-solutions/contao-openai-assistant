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

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves contao_* domains from the bundle's own XLF files the way Contao's translator
 * does (trans-unit id as key, vsprintf() for parameters), so tests read real mail texts.
 */
final class XlfTranslator implements TranslatorInterface
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $catalogues = [];

    /**
     * @var list<array{id: string, domain: string|null, locale: string|null}>
     */
    public array $calls = [];

    public function trans(string $id, array $parameters = [], string|null $domain = null, string|null $locale = null): string
    {
        $this->calls[] = ['id' => $id, 'domain' => $domain, 'locale' => $locale];

        $file = substr((string) $domain, 7);
        $message = $this->catalogue($file, $locale ?? 'en')[$id] ?? $id;

        return $parameters ? vsprintf($message, $parameters) : $message;
    }

    public function getLocale(): string
    {
        return 'en';
    }

    /**
     * @return array<string, string>
     */
    public static function load(string $file, string $locale): array
    {
        $xml = simplexml_load_file(\dirname(__DIR__, 2).'/contao/languages/'.$locale.'/'.$file.'.xlf');
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $messages = [];

        foreach ($xml->xpath('//x:trans-unit') as $unit) {
            $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $target = $unit->xpath('x:target');
            $messages[(string) $unit['id']] = (string) ($target[0] ?? $unit->xpath('x:source')[0]);
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(string $file, string $locale): array
    {
        return $this->catalogues[$file.'.'.$locale] ??= self::load($file, $locale);
    }
}
