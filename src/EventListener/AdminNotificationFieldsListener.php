<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Contao\StringUtil;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotifier;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * DCA callbacks of the "Administrator notifications" section of tl_openai_config.
 *
 * Referenced by class name from the DCA, so the service must stay public (see
 * DcaCallbackServicesArePublicTest).
 */
class AdminNotificationFieldsListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly TranslatorInterface $translator,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName = 'contao_csrf_token',
    ) {
    }

    /**
     * save_callback of admin_notify_recipients: trim, drop empty rows, de-duplicate
     * case-insensitively (the first spelling wins) and enforce 1 to 10 valid addresses.
     *
     * Only runs while the field is in the form, which is only while notifications are
     * enabled, so "no address" is always an error here. Returns the type it was given.
     */
    public function normaliseRecipients(mixed $value, DataContainer $dc): mixed
    {
        $recipients = [];

        foreach (StringUtil::deserialize($value, true) as $address) {
            $address = trim((string) $address);

            if ('' !== $address) {
                $recipients[strtolower($address)] ??= $address;
            }
        }

        $recipients = array_values($recipients);

        if ([] === $recipients) {
            throw new \RuntimeException($this->trans('tl_openai_config.admin_notify_recipients_required'));
        }

        if (\count($recipients) > AdminNotifier::MAX_RECIPIENTS) {
            throw new \RuntimeException($this->trans('tl_openai_config.admin_notify_recipients_too_many'));
        }

        // Defence in depth: the widget's rgxp already checked every row, and this is the
        // same rule the notifier applies before sending.
        foreach ($recipients as $address) {
            if (!AdminNotifier::isValidEmail($address)) {
                throw new \RuntimeException($this->translator->trans('ERR.email', [], 'contao_default'));
            }
        }

        return \is_array($value) ? $recipients : serialize($recipients);
    }

    /**
     * Require an explicit supported language; never replace an empty saved value
     * in a load callback, because DC_Table compares submitted values against it.
     */
    public function validateLanguage(mixed $value, DataContainer $dc): string
    {
        if ('de' !== $value && 'en' !== $value) {
            throw new \RuntimeException($this->trans('tl_openai_config.admin_notify_language_required'));
        }

        return $value;
    }

    /**
     * input_field_callback of admin_notify_test: the "Send test email" button.
     *
     * The endpoint uses the SAVED settings (recipients never come from the browser), so the
     * hint tells the user to save first. Behaviour: public/js/backend-admin-notify-test.js.
     */
    public function testButtonField(DataContainer $dc): string
    {
        return \sprintf(
            '<div class="widget clr oaa-admin-notify-test">
    <button type="button" class="tl_submit oaa-admin-notify-test-button"
        data-url="%1$s"
        data-request-token="%2$s"
        data-config-id="%3$s"
        data-sending-label="%4$s"
        data-error-label="%5$s">%6$s</button>
    <span class="oaa-admin-notify-test-result" aria-live="polite"></span>
    <p class="tl_help">%7$s</p>
</div>',
            htmlspecialchars($this->router->generate('contao_openai_admin_notify_test'), ENT_QUOTES),
            htmlspecialchars($this->csrfTokenManager->getToken($this->csrfTokenName)->getValue(), ENT_QUOTES),
            htmlspecialchars((string) $dc->id, ENT_QUOTES),
            htmlspecialchars($this->trans('tl_openai_config.admin_notify_test_sending'), ENT_QUOTES),
            htmlspecialchars($this->trans('tl_openai_config.admin_notify_test_failed'), ENT_QUOTES),
            htmlspecialchars($this->trans('tl_openai_config.admin_notify_test_button'), ENT_QUOTES),
            nl2br(htmlspecialchars($this->trans('tl_openai_config.admin_notify_test_hint'), ENT_QUOTES), false),
        );
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], 'contao_tl_openai_config');
    }
}
