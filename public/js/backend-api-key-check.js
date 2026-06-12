(function () {
    "use strict";

    function findInput(fieldName) {
        return document.getElementById("ctrl_" + fieldName)
            || document.getElementById(fieldName)
            || document.querySelector('[name="' + fieldName + '"]');
    }

    function placeWrapperBelowInput(wrapper, input) {
        if (!wrapper || !input) {
            return;
        }

        var widget = input.closest(".widget");
        if (!widget) {
            return;
        }

        var help = widget.querySelector("p.tl_help");
        if (help && help.parentNode === widget) {
            widget.insertBefore(wrapper, help);
            return;
        }

        var passwordContainer = input.closest('[data-controller="contao--password-visibility"]');
        if (passwordContainer && passwordContainer.parentNode) {
            passwordContainer.parentNode.insertBefore(wrapper, passwordContainer.nextSibling);
            return;
        }

        if (input.parentNode) {
            input.parentNode.insertBefore(wrapper, input.nextSibling);
        }
    }

    function setAutoUpdateFieldsEnabled(enabled) {
        document.querySelectorAll(".widget.auto-update-license-field").forEach(function (widget) {
            widget.classList.toggle("openai-auto-update-disabled", !enabled);

            widget.querySelectorAll("input, select, textarea, button, a.tl_submit").forEach(function (element) {
                if ("disabled" in element) {
                    element.disabled = !enabled;
                }

                if (!enabled) {
                    element.setAttribute("aria-disabled", "true");
                    element.setAttribute("tabindex", "-1");
                } else {
                    element.removeAttribute("aria-disabled");
                    element.removeAttribute("tabindex");
                }
            });
        });
    }

    function bindApiKeyButton(button) {
        if (!button || button.dataset.apiKeyCheckBound === "1") {
            return;
        }

        var fieldName = button.dataset.apiKeyField || button.id.replace(/^apiKeyCheck_/, "");
        var input = findInput(fieldName);
        var resultId = "apiKeyResult_" + fieldName;
        var resultSpan = document.getElementById(resultId);
        var wrapper = button.closest(".api-key-check-wrapper");

        if (!input || !resultSpan || !wrapper) {
            return;
        }

        placeWrapperBelowInput(wrapper, input);
        button.dataset.apiKeyCheckBound = "1";

        button.addEventListener("click", function () {
            var apiKey = input.value;
            if (!apiKey) {
                alert("Bitte geben Sie zuerst einen API-Schluessel ein.");
                return;
            }

            var url = button.dataset.validationUrl || (window.location.origin + "/contao/api-key-validate");
            var requestToken = button.dataset.requestToken || "";

            button.disabled = true;
            button.innerHTML = '<span class="processing-spinner"></span>Validiere...';
            resultSpan.innerHTML = "";

            var xhr = new XMLHttpRequest();
            xhr.open("POST", url, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) {
                    return;
                }

                button.disabled = false;
                button.textContent = "Key prüfen";

                try {
                    var result = JSON.parse(xhr.responseText || "{}");

                    if (result.valid) {
                        resultSpan.innerHTML = '<span style="color:green;">✓ API-Schluessel ist gueltig!</span>';
                        input.style.backgroundColor = "lightgreen";
                        input.style.color = "#121212";
                        return;
                    }

                    resultSpan.innerHTML = '<span style="color:red;">✗ API-Schluessel ist ungueltig. ' + (result.message || "") + '</span>';
                    input.style.backgroundColor = "lightcoral";
                    input.style.color = "#121212";
                } catch (e) {
                    resultSpan.innerHTML = '<span style="color:red;">✗ Fehler bei der Validierung</span>';
                }
            };

            xhr.send("action=validateApiKey&key=" + encodeURIComponent(apiKey) + "&REQUEST_TOKEN=" + encodeURIComponent(requestToken));
        });
    }

    function bindLicenseKeyButton(button) {
        if (!button || button.dataset.licenseKeyCheckBound === "1") {
            return;
        }

        var fieldName = button.dataset.licenseKeyField || "premium_license_key";
        var input = findInput(fieldName);
        var resultId = "licenseKeyResult_" + fieldName;
        var resultSpan = document.getElementById(resultId);
        var wrapper = button.closest(".license-key-check-wrapper");
        var globalLabels = (window.contaoOpenAiAutoUpdate && window.contaoOpenAiAutoUpdate.labels) || {};
        var labels = {
            noKey: button.dataset.noKeyLabel || globalLabels.noKey,
            valid: button.dataset.validLabel || globalLabels.valid,
            invalid: button.dataset.invalidLabel || globalLabels.invalid,
            error: button.dataset.errorLabel || globalLabels.error,
            check: button.dataset.checkLabel || globalLabels.check,
            validating: button.dataset.validatingLabel || globalLabels.validating
        };
        var configId = button.dataset.configId || (window.contaoOpenAiAutoUpdate && window.contaoOpenAiAutoUpdate.configId) || "";

        if (!input || !resultSpan || !wrapper) {
            return;
        }

        placeWrapperBelowInput(wrapper, input);
        button.dataset.licenseKeyCheckBound = "1";

        button.addEventListener("click", function () {
            var licenseKey = input.value;
            if (!licenseKey) {
                alert(labels.noKey || "Please enter a license key first.");
                return;
            }

            var url = button.dataset.validationUrl || (window.location.origin + "/contao/license-key-validate");
            var requestToken = button.dataset.requestToken || "";

            button.disabled = true;
            button.innerHTML = '<span class="processing-spinner"></span>' + (labels.validating || "Validating...");
            resultSpan.innerHTML = "";

            var xhr = new XMLHttpRequest();
            xhr.open("POST", url, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) {
                    return;
                }

                button.disabled = false;
                button.textContent = labels.check || button.dataset.checkLabel || "Check key";

                try {
                    var result = JSON.parse(xhr.responseText || "{}");

                    if (result.valid) {
                        resultSpan.innerHTML = '<span style="color:green;">✓ ' + (labels.valid || "License key is valid!") + '</span>';
                        input.style.backgroundColor = "lightgreen";
                        input.style.color = "#121212";
                        // UX only — fields unlock after a successful check. Server-side
                        // enforcement still requires saving the key and an active license in DB.
                        setAutoUpdateFieldsEnabled(true);
                        if (window.contaoOpenAiAutoUpdate) {
                            window.contaoOpenAiAutoUpdate.licenseActive = true;
                        }
                        return;
                    }

                    resultSpan.innerHTML = '<span style="color:red;">✗ ' + (labels.invalid || "License key is invalid!") + " " + (result.message || "") + '</span>';
                    input.style.backgroundColor = "lightcoral";
                    input.style.color = "#121212";
                    setAutoUpdateFieldsEnabled(false);
                } catch (e) {
                    resultSpan.innerHTML = '<span style="color:red;">✗ ' + (labels.error || "Validation failed.") + '</span>';
                }
            };

            xhr.send(
                "key=" + encodeURIComponent(licenseKey)
                + "&config_id=" + encodeURIComponent(configId)
                + "&REQUEST_TOKEN=" + encodeURIComponent(requestToken)
            );
        });
    }

    function init() {
        document.querySelectorAll('button[id^="apiKeyCheck_"]').forEach(bindApiKeyButton);
        document.querySelectorAll(".license-key-check-button").forEach(bindLicenseKeyButton);

        if (window.contaoOpenAiAutoUpdate) {
            setAutoUpdateFieldsEnabled(!!window.contaoOpenAiAutoUpdate.licenseActive);
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }

    var observer = new MutationObserver(function () {
        init();
    });

    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
