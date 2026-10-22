<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Controller\AdminNotificationTestController;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotificationTestResult;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AdminNotificationTestControllerTest extends TestCase
{
    public function testAnInvalidTokenIsRejected(): void
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->never())->method('sendTestNotification');

        $response = $this->createController(notifier: $notifier, csrfValid: false)($this->createRequest());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['success' => false, 'message' => 'Invalid request token'], json_decode((string) $response->getContent(), true));
    }

    public function testUsersWithoutModuleAccessAreRejected(): void
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->never())->method('sendTestNotification');

        $response = $this->createController(notifier: $notifier, granted: false)($this->createRequest());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnUnknownConfigurationIs404(): void
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->never())->method('sendTestNotification');

        $response = $this->createController(notifier: $notifier, row: false)($this->createRequest());

        $this->assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('provideResults')]
    public function testAnswersWithTheTranslatedResult(AdminNotificationTestResult $result, bool $success, string $key): void
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('sendTestNotification')->willReturn($result);

        $response = $this->createController(notifier: $notifier)($this->createRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => $success, 'message' => 'translated:'.$key], json_decode((string) $response->getContent(), true));
    }

    public static function provideResults(): iterable
    {
        yield 'sent' => [AdminNotificationTestResult::Sent, true, 'tl_openai_config.admin_notify_test_sent'];
        yield 'no recipients' => [AdminNotificationTestResult::NoRecipients, false, 'tl_openai_config.admin_notify_test_no_recipients'];
        yield 'no sender' => [AdminNotificationTestResult::NoSender, false, 'tl_openai_config.admin_notify_test_no_sender'];
        yield 'too frequent' => [AdminNotificationTestResult::TooFrequent, false, 'tl_openai_config.admin_notify_test_too_frequent'];
        yield 'failed' => [AdminNotificationTestResult::Failed, false, 'tl_openai_config.admin_notify_test_failed'];
    }

    /**
     * Recipients posted by the browser must never reach the notifier: otherwise the
     * endpoint would be a mail relay for anyone with backend access.
     */
    public function testRecipientsInTheRequestAreIgnored(): void
    {
        $row = ['id' => 3, 'admin_notify_recipients' => serialize(['saved@example.com'])];

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with('SELECT * FROM tl_openai_config WHERE id = ?', [3])
            ->willReturn($row)
        ;

        $notifier = $this->createMock(AdminNotifier::class);
        $notifier
            ->expects($this->once())
            ->method('sendTestNotification')
            ->with($row, $this->isInstanceOf(Request::class))
            ->willReturn(AdminNotificationTestResult::Sent)
        ;

        $request = $this->createRequest(['id' => '3', 'admin_notify_recipients' => 'attacker@example.org', 'recipients' => ['attacker@example.org']]);

        $this->createController(notifier: $notifier, connection: $connection)($request);
    }

    private function createRequest(array $post = ['id' => '1']): Request
    {
        return Request::create('/contao/openai-admin-notify-test', 'POST', ['REQUEST_TOKEN' => 'token'] + $post);
    }

    private function createController(AdminNotifier|null $notifier = null, bool $csrfValid = true, bool $granted = true, array|false $row = ['id' => 1], Connection|null $connection = null): AdminNotificationTestController
    {
        $csrf = $this->createMock(ContaoCsrfTokenManager::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker
            ->method('isGranted')
            ->willReturnCallback(static fn (string $attribute, mixed $subject): bool => $granted && ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'openai_dashboard' === $subject)
        ;

        if (null === $connection) {
            $connection = $this->createMock(Connection::class);
            $connection->method('fetchAssociative')->willReturn($row);
        }

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = [], string|null $domain = null): string => 'contao_tl_openai_config' === $domain ? 'translated:'.$id : $id)
        ;

        return new AdminNotificationTestController($csrf, $authorizationChecker, $connection, $notifier ?? $this->createMock(AdminNotifier::class), $translator);
    }
}
