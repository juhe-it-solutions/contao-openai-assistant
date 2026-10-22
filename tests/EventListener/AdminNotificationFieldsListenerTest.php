<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use JuheItSolutions\ContaoOpenaiAssistant\EventListener\AdminNotificationFieldsListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AdminNotificationFieldsListenerTest extends TestCase
{
    public function testNormalisingKeepsAnArrayAnArray(): void
    {
        $value = [' admin@example.com ', '', 'Admin@Example.com', 'agency@example.org', '   '];

        $this->assertSame(
            ['admin@example.com', 'agency@example.org'],
            $this->createListener()->normaliseRecipients($value, $this->createMock(DataContainer::class)),
        );
    }

    public function testNormalisingKeepsASerializedStringASerializedString(): void
    {
        $value = serialize(['Office@Juhe-IT-Solutions.at', 'office@juhe-it-solutions.at', 'a@b.co']);

        $this->assertSame(
            serialize(['Office@Juhe-IT-Solutions.at', 'a@b.co']),
            $this->createListener()->normaliseRecipients($value, $this->createMock(DataContainer::class)),
        );
    }

    #[DataProvider('provideInvalidRecipientLists')]
    public function testRejectsInvalidRecipientLists(array $value, string $expectedMessage): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->createListener()->normaliseRecipients($value, $this->createMock(DataContainer::class));
    }

    public static function provideInvalidRecipientLists(): iterable
    {
        yield 'no address' => [['', '  '], 'tl_openai_config.admin_notify_recipients_required'];
        yield 'eleven addresses' => [array_map(static fn (int $i): string => 'r'.$i.'@example.com', range(1, 11)), 'tl_openai_config.admin_notify_recipients_too_many'];
        yield 'localhost' => [['admin@localhost'], 'ERR.email'];
        yield 'domain without a dot' => [['admin@example'], 'ERR.email'];
        yield 'no @ sign' => [['no-at-sign'], 'ERR.email'];
    }

    public function testElevenRowsWithDuplicatesAreFine(): void
    {
        $value = [...array_map(static fn (int $i): string => 'r'.$i.'@example.com', range(1, 10)), 'R1@example.com'];

        $this->assertCount(10, $this->createListener()->normaliseRecipients($value, $this->createMock(DataContainer::class)));
    }

    #[DataProvider('provideValidAddresses')]
    public function testAcceptsValidAddresses(string $address): void
    {
        $this->assertSame([$address], $this->createListener()->normaliseRecipients([$address], $this->createMock(DataContainer::class)));
    }

    public static function provideValidAddresses(): iterable
    {
        yield 'short' => ['a@b.co'];
        yield 'company' => ['office@juhe-it-solutions.at'];
    }

    #[DataProvider('provideLanguageDefaults')]
    public function testTheLanguageDefaultsToTheBackendUser(mixed $stored, string|null $userLanguage, bool $backendUser, string $expected): void
    {
        $user = null;

        if (null !== $userLanguage) {
            if ($backendUser) {
                $user = $this->createMock(BackendUser::class);
                $user->method('__get')->willReturnCallback(static fn (string $key): string|null => 'language' === $key ? $userLanguage : null);
            } else {
                $user = $this->createMock(UserInterface::class);
            }
        }

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $this->assertSame($expected, $this->createListener($security)->defaultLanguage($stored, $this->createMock(DataContainer::class)));
    }

    public static function provideLanguageDefaults(): iterable
    {
        yield 'German user' => ['', 'de', true, 'de'];
        yield 'Austrian German user' => ['', 'de_AT', true, 'de'];
        yield 'English user' => ['', 'en', true, 'en'];
        yield 'French user' => ['', 'fr', true, 'en'];
        yield 'no user' => ['', null, true, 'en'];
        yield 'not a backend user' => ['', 'de', false, 'en'];
        yield 'stored German is kept' => ['de', 'en', true, 'de'];
        yield 'stored English is kept' => ['en', 'de', true, 'en'];
        yield 'stored garbage is replaced' => ['xx', 'de', true, 'de'];
    }

    public function testTheTestButtonEscapesItsAttributesAndUsesTheRoute(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->expects($this->once())
            ->method('generate')
            ->with('contao_openai_admin_notify_test')
            ->willReturn('/contao/openai-admin-notify-test?a=1&b="2"')
        ;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => '<b>'.$id.'</b>"');

        $csrf = $this->createMock(ContaoCsrfTokenManager::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('contao_csrf_token', 'tok"<en>'));

        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): string|null => 'id' === $key ? '5"' : null);

        $html = (new AdminNotificationFieldsListener($this->createMock(Security::class), $router, $translator, $csrf))->testButtonField($dc);

        $this->assertStringContainsString('class="widget clr oaa-admin-notify-test"', $html);
        $this->assertStringContainsString('data-url="/contao/openai-admin-notify-test?a=1&amp;b=&quot;2&quot;"', $html);
        $this->assertStringContainsString('data-request-token="tok&quot;&lt;en&gt;"', $html);
        $this->assertStringContainsString('data-config-id="5&quot;"', $html);
        $this->assertStringContainsString('data-sending-label="&lt;b&gt;tl_openai_config.admin_notify_test_sending&lt;/b&gt;&quot;"', $html);
        $this->assertStringContainsString('>&lt;b&gt;tl_openai_config.admin_notify_test_button&lt;/b&gt;&quot;</button>', $html);
        $this->assertStringContainsString('&lt;b&gt;tl_openai_config.admin_notify_test_hint&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    private function createListener(Security|null $security = null): AdminNotificationFieldsListener
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new AdminNotificationFieldsListener(
            $security ?? $this->createMock(Security::class),
            $this->createMock(RouterInterface::class),
            $translator,
            $this->createMock(ContaoCsrfTokenManager::class),
        );
    }
}
