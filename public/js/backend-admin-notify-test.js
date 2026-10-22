/*
 * "Send test email" in the administrator notification settings of the OpenAI configuration.
 *
 * Loaded on EVERY backend page (TL_JAVASCRIPT), so it does nothing unless the button is on
 * the page: no document-wide click handler, only a per-button listener. The Turbo
 * lifecycle hooks below exist because Contao's backend swaps pages without re-running head
 * scripts; they only look for the button and return when it is absent.
 */
(function () {
    "use strict";

    // Turbo morphing reuses element nodes, so a node that already has its listener must
    // not get a second one. A WeakSet forgets nodes once they leave the DOM.
    var boundButtons = typeof WeakSet === "function" ? new WeakSet() : null;

    function showResult(resultEl, text, success) {
        // textContent only: the message comes from the server and is never parsed as HTML.
        resultEl.textContent = text;
        resultEl.style.color = success ? "green" : "red";
        resultEl.style.marginLeft = "8px";
    }

    function sendTest(button) {
        var widget = button.closest(".oaa-admin-notify-test");
        var resultEl = widget ? widget.querySelector(".oaa-admin-notify-test-result") : null;
        var errorLabel = button.dataset.errorLabel || "The email could not be sent.";

        if (!resultEl || button.disabled || !button.dataset.url) {
            return;
        }

        var originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = button.dataset.sendingLabel || "Sending...";
        resultEl.textContent = "";

        var xhr = new XMLHttpRequest();
        xhr.open("POST", button.dataset.url, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            button.disabled = false;
            button.textContent = originalLabel;

            // Every outcome of the test itself is answered with 200. Anything else (offline,
            // expired backend token, missing permission, a login page instead of JSON) means
            // the test did not run, so only the generic error is shown.
            var result = null;

            if (200 === xhr.status) {
                try {
                    result = JSON.parse(xhr.responseText || "null");
                } catch (e) {
                    result = null;
                }
            }

            if (!result || "boolean" !== typeof result.success || "string" !== typeof result.message) {
                showResult(resultEl, errorLabel, false);
                return;
            }

            showResult(resultEl, result.message, result.success);
        };

        xhr.send(
            "REQUEST_TOKEN=" + encodeURIComponent(button.dataset.requestToken || "")
            + "&id=" + encodeURIComponent(button.dataset.configId || "")
        );
    }

    function init() {
        try {
            var buttons = document.querySelectorAll(".oaa-admin-notify-test-button");

            if (!buttons.length) {
                return;
            }

            buttons.forEach(function (button) {
                if (boundButtons ? boundButtons.has(button) : button.dataset.oaaBound === "1") {
                    return;
                }

                if (boundButtons) {
                    boundButtons.add(button);
                } else {
                    button.dataset.oaaBound = "1";
                }

                button.addEventListener("click", function (event) {
                    event.preventDefault();
                    sendTest(button);
                });
            });
        } catch (e) {
            // Never break the rest of the backend page.
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }

    document.addEventListener("turbo:load", init);
    document.addEventListener("turbo:render", init);
    document.addEventListener("turbo:frame-load", init);
})();
