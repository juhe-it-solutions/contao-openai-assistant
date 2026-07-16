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

    function setAutoUpdateBlockVisible(visible) {
        var firstField = document.querySelector(".widget.auto-update-license-field");
        var fieldset = firstField ? firstField.closest("fieldset") : null;
        if (fieldset) {
            // Use an explicit "block" rather than "" so the inline style overrides
            // the #pal_auto_update_legend{display:none} rule injected from PHP.
            fieldset.style.display = visible ? "block" : "none";
        }
    }

    function setAutoUpdateFieldsEnabled(enabled) {
        // Hide the whole "Vector store sync" block until the license is validated.
        setAutoUpdateBlockVisible(enabled);

        document.querySelectorAll(".widget.auto-update-license-field").forEach(function (widget) {
            // Fields carrying oaa-mode-locked were disabled server-side for a
            // functional reason (e.g. prompt template in faithful mode) and must
            // stay disabled even when the license check re-enables the block.
            var widgetEnabled = enabled && !widget.classList.contains("oaa-mode-locked");

            widget.classList.toggle("openai-auto-update-disabled", !widgetEnabled);

            widget.querySelectorAll("input, select, textarea, button, a.tl_submit").forEach(function (element) {
                if ("disabled" in element) {
                    element.disabled = !widgetEnabled;
                }

                if (!enabled && element.type === "checkbox" && element.name === "auto_update_enabled") {
                    element.checked = false;
                }

                if (!widgetEnabled) {
                    element.setAttribute("aria-disabled", "true");
                    element.setAttribute("tabindex", "-1");
                } else {
                    element.removeAttribute("aria-disabled");
                    element.removeAttribute("tabindex");
                }
            });

            widget.querySelectorAll('[data-controller="contao--choices"]').forEach(function (choicesWrapper) {
                choicesWrapper.classList.toggle("openai-auto-update-disabled", !enabled);
            });
        });
    }

    // Runtime override: set when a key is validated (or removed) in the open form,
    // before the server state is re-rendered. Keyed to the state element instance so
    // a fresh server render (Turbo/AJAX) resets it to the authoritative server state.
    var licenseOverride = null;
    var licenseOverrideStateEl = null;

    function setLicenseOverride(active) {
        licenseOverride = active;
        licenseOverrideStateEl = document.querySelector(".oaa-auto-update-state");
    }

    function syncAutoUpdateLicenseState() {
        if (!document.querySelector(".widget.auto-update-license-field")) {
            return;
        }

        var stateEl = document.querySelector(".oaa-auto-update-state");

        if (licenseOverride !== null && stateEl === licenseOverrideStateEl) {
            setAutoUpdateFieldsEnabled(licenseOverride);
            return;
        }

        licenseOverride = null;
        licenseOverrideStateEl = null;

        // Fail safe: no state element means we cannot prove an active license,
        // so the premium block stays hidden.
        setAutoUpdateFieldsEnabled(!!stateEl && stateEl.dataset.licenseActive === "1");
    }

    // Reposition all API-key check wrappers below their input fields. Called on every
    // init() tick; safe to run multiple times (placeWrapperBelowInput is idempotent).
    function placeApiKeyWrappers() {
        document.querySelectorAll(".api-key-check-button").forEach(function (button) {
            var fieldName = button.dataset.apiKeyField || button.id.replace(/^apiKeyCheck_/, "");
            var input = findInput(fieldName);
            var wrapper = button.closest(".api-key-check-wrapper");
            placeWrapperBelowInput(wrapper, input);
        });
    }

    // Event delegation for the API-key check button. Bound once to the document so it
    // survives Turbo morphdom patching (which reuses element nodes and preserves dataset,
    // making per-element dataset guards unreliable).
    var apiKeyDelegateSetup = false;

    function setupApiKeyDelegate() {
        if (apiKeyDelegateSetup) {
            return;
        }
        apiKeyDelegateSetup = true;

        document.addEventListener("click", function (e) {
            var button = e.target.closest && e.target.closest(".api-key-check-button");
            if (!button) {
                return;
            }

            var fieldName = button.dataset.apiKeyField || button.id.replace(/^apiKeyCheck_/, "");
            var input = findInput(fieldName);
            var resultSpan = document.getElementById("apiKeyResult_" + fieldName);

            // Labels are rendered by the PHP wizard from the language files so the
            // button follows the backend locale; the literals are fallbacks only.
            var labels = {
                noKey: button.dataset.noKeyLabel || "Please enter an API key first.",
                valid: button.dataset.validLabel || "API key is valid!",
                invalid: button.dataset.invalidLabel || "API key is invalid!",
                error: button.dataset.errorLabel || "Validation failed.",
                check: button.dataset.checkLabel || "Check key",
                validating: button.dataset.validatingLabel || "Validating..."
            };

            if (!input || !resultSpan) {
                return;
            }

            var apiKey = input.value;
            if (!apiKey) {
                resultSpan.innerHTML = '<span style="color:orange;">&#9888; ' + labels.noKey + '</span>';
                return;
            }

            var url = button.dataset.validationUrl || (window.location.origin + "/contao/api-key-validate");
            var requestToken = button.dataset.requestToken || "";

            button.disabled = true;
            button.innerHTML = '<span class="processing-spinner"></span>' + labels.validating;
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
                button.textContent = labels.check;

                try {
                    var result = JSON.parse(xhr.responseText || "{}");

                    if (result.valid) {
                        resultSpan.innerHTML = '<span style="color:green;">✓ ' + labels.valid + '</span>';
                        input.style.backgroundColor = "lightgreen";
                        input.style.color = "#121212";
                        return;
                    }

                    resultSpan.innerHTML = '<span style="color:red;">✗ ' + labels.invalid + ' ' + (result.message || "") + '</span>';
                    input.style.backgroundColor = "lightcoral";
                    input.style.color = "#121212";
                } catch (e) {
                    resultSpan.innerHTML = '<span style="color:red;">✗ ' + labels.error + '</span>';
                }
            };

            xhr.send("action=validateApiKey&key=" + encodeURIComponent(apiKey) + "&REQUEST_TOKEN=" + encodeURIComponent(requestToken));
        });
    }

    // Event delegation for the license-key check button. Bound once to the
    // document so it survives Turbo morphdom patching (which reuses element
    // nodes and preserves dataset, making per-element guards unreliable).
    var licenseKeyDelegateSetup = false;

    function setupLicenseKeyDelegate() {
        if (licenseKeyDelegateSetup) {
            return;
        }
        licenseKeyDelegateSetup = true;

        document.addEventListener("click", function (e) {
            var button = e.target.closest && e.target.closest(".license-key-check-button");
            if (!button) {
                return;
            }

            // Read everything fresh at click time — no stale closure references.
            var fieldName = button.dataset.licenseKeyField || "premium_license_key";
            var input = findInput(fieldName);
            var resultSpan = document.getElementById("licenseKeyResult_" + fieldName);
            var labels = {
                noKey: button.dataset.noKeyLabel,
                valid: button.dataset.validLabel,
                invalid: button.dataset.invalidLabel,
                error: button.dataset.errorLabel,
                check: button.dataset.checkLabel,
                validating: button.dataset.validatingLabel
            };
            var stateEl = document.querySelector(".oaa-auto-update-state");
            var configId = button.dataset.configId || (stateEl && stateEl.dataset.configId) || "";

            if (!input || !resultSpan) {
                return;
            }

            var licenseKey = input.value;
            if (!licenseKey) {
                resultSpan.innerHTML = '<span style="color:orange;">&#9888; ' + (labels.noKey || "Please enter a license key first.") + '</span>';
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
                        setLicenseOverride(true);
                        setAutoUpdateFieldsEnabled(true);
                        return;
                    }

                    resultSpan.innerHTML = '<span style="color:red;">✗ ' + (labels.invalid || "License key is invalid!") + '</span>';
                    input.style.backgroundColor = "lightcoral";
                    input.style.color = "#121212";
                    setLicenseOverride(false);
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

    // Event delegation for the license-key remove button. Clears the key field,
    // hides the auto-update block, and shows a "Save to confirm" hint.
    var licenseKeyRemoveDelegateSetup = false;

    function setupLicenseKeyRemoveDelegate() {
        if (licenseKeyRemoveDelegateSetup) {
            return;
        }
        licenseKeyRemoveDelegateSetup = true;

        document.addEventListener("click", function (e) {
            var button = e.target.closest && e.target.closest(".license-key-remove-button");
            if (!button) {
                return;
            }

            var fieldName = button.dataset.licenseKeyField || "premium_license_key";
            var input = findInput(fieldName);
            var resultId = button.dataset.resultId || ("licenseKeyResult_" + fieldName);
            var resultSpan = document.getElementById(resultId);

            if (!input) {
                return;
            }

            input.value = "";
            input.style.backgroundColor = "";
            input.style.color = "";

            setLicenseOverride(false);
            setAutoUpdateFieldsEnabled(false);

            if (resultSpan) {
                var confirmLabel = button.dataset.confirmLabel || "License key cleared. Save to remove the license.";
                resultSpan.innerHTML = '<span style="color: #f59e0b;">&#9888; ' + confirmLabel + "</span>";
            }
        });
    }

    // Collapse the "pages to keep updated" page-tree picker into a count so configs
    // with hundreds of selected pages do not blow up the form. Scoped to the
    // auto_update_site_root field only; re-applies after the picker AJAX re-renders.
    var PICKER_LABELS = {
        de: { selected: "Seiten ausgewählt", selectedOne: "Seite ausgewählt", show: "anzeigen", hide: "verbergen" },
        en: { selected: "pages selected", selectedOne: "page selected", show: "show", hide: "hide" }
    };

    function pickerLabels() {
        var lang = (document.documentElement.getAttribute("lang") || "en").slice(0, 2).toLowerCase();
        return PICKER_LABELS[lang] || PICKER_LABELS.en;
    }

    function setPickerToggle(summary, list) {
        var toggle = summary.querySelector(".oaa-picker-toggle");
        if (!toggle) {
            return;
        }
        var collapsed = list.classList.contains("oaa-picker-collapsed");
        toggle.textContent = "(" + (collapsed ? pickerLabels().show : pickerLabels().hide) + ")";
    }

    function summaryText(count) {
        var labels = pickerLabels();
        return count === 1 ? "1 " + labels.selectedOne : count + " " + labels.selected;
    }

    // Collapse the page list into its count. Idempotent and observer-safe: a summary
    // already sitting in the container means this exact list instance is handled, so
    // we only refresh the count and DO NOT touch the collapsed state (a manual "show"
    // stays open). When the picker "apply" replaces the whole .selector_container it
    // takes our summary with it, so the next call sees a fresh, expanded list with no
    // summary and re-collapses it. That keyed-on-summary check replaces the old
    // node-marker, which failed because we relied on the body-wide MutationObserver
    // catching the swap rather than the deterministic post-apply signal below.
    function ensurePagePickerCollapsed() {
        var list = document.getElementById("sort_auto_update_site_root");
        if (!list) {
            return;
        }

        var container = list.closest(".selector_container");
        if (!container) {
            return;
        }

        var count = list.querySelectorAll(":scope > li").length;
        var summary = container.querySelector(".oaa-picker-summary");

        if (summary) {
            var countEl = summary.querySelector(".oaa-picker-count");
            if (countEl) {
                countEl.textContent = summaryText(count);
            }
            return;
        }

        summary = document.createElement("p");
        summary.className = "oaa-picker-summary";
        container.insertBefore(summary, list);
        summary.innerHTML = '<span class="oaa-picker-count">' + summaryText(count) + "</span>"
            + (count > 0 ? ' <a href="#" class="oaa-picker-toggle"></a>' : "");

        list.classList.add("oaa-picker-collapsed");
        setPickerToggle(summary, list);
    }

    // Prompt edit form: show/hide the custom model field when "Enter Custom Model" is selected.
    var promptModelDelegateSetup = false;

    function toggleManualModelField(select) {
        if (!select) {
            return;
        }

        var manualField = findInput("model_manual");
        var manualWidget = manualField ? manualField.closest(".widget") : null;

        if (select.value === "manual") {
            if (manualWidget) {
                manualWidget.style.display = "";
            }
            if (manualField) {
                manualField.focus();
                manualField.style.borderColor = "#007cba";
                manualField.style.backgroundColor = "#f8f9fa";
            }
            return;
        }

        if (manualWidget) {
            manualWidget.style.display = "none";
        }
        if (manualField) {
            manualField.value = "";
            manualField.style.borderColor = "";
            manualField.style.backgroundColor = "";
        }
    }

    function setupPromptModelDelegate() {
        if (promptModelDelegateSetup) {
            return;
        }
        promptModelDelegateSetup = true;

        document.addEventListener("change", function (e) {
            if (!e.target || e.target.name !== "model") {
                return;
            }
            toggleManualModelField(e.target);
        });
    }

    function initPromptModelToggle() {
        var modelSelect = document.querySelector('select[name="model"]');
        if (!modelSelect) {
            return;
        }
        toggleManualModelField(modelSelect);
    }

    // Bind the picker's document-level listeners exactly once. init() runs on every
    // MutationObserver tick, so per-instance binding here would stack up handlers.
    var pickerDelegatesBound = false;

    function bindPagePickerDelegates() {
        if (pickerDelegatesBound) {
            return;
        }
        pickerDelegatesBound = true;

        // Toggle show/hide. The summary and list are re-created on every apply, so we
        // delegate on document and resolve both nodes fresh at click time.
        document.addEventListener("click", function (e) {
            var toggle = e.target.closest && e.target.closest(".oaa-picker-toggle");
            if (!toggle) {
                return;
            }
            e.preventDefault();
            var list = document.getElementById("sort_auto_update_site_root");
            var summary = toggle.closest(".oaa-picker-summary");
            if (!list || !summary) {
                return;
            }
            list.classList.toggle("oaa-picker-collapsed");
            setPickerToggle(summary, list);
        });

        // Deterministic post-apply hook: core's PageTree picker callback replaces the
        // selector_container HTML and then dispatches a bubbling "change" on the hidden
        // control (see core PageTree.php). At that point the new list is fully in the
        // DOM, so collapsing here avoids any flash and the body-wide observer race.
        document.addEventListener("change", function (e) {
            if (e.target && e.target.id === "ctrl_auto_update_site_root") {
                ensurePagePickerCollapsed();
            }
        });
    }

    var observer = new MutationObserver(function () {
        init();
        syncAutoUpdateLicenseState();
    });

    function startObserver() {
        observer.observe(document.body, { childList: true, subtree: true });
    }

    // Disconnect before any DOM manipulation so that insertBefore / innerHTML
    // calls inside init() do not re-trigger the observer (infinite loop).
    // Reconnect unconditionally at the end so subsequent Turbo navigations and
    // AJAX re-renders are still caught.
    function init() {
        observer.disconnect();

        placeApiKeyWrappers();
        setupApiKeyDelegate();
        setupLicenseKeyDelegate();
        setupLicenseKeyRemoveDelegate();
        setupPromptModelDelegate();
        bindPagePickerDelegates();
        ensurePagePickerCollapsed();
        initPromptModelToggle();
        syncAutoUpdateLicenseState();

        startObserver();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            init();
            syncAutoUpdateLicenseState();
        });
    } else {
        init();
        syncAutoUpdateLicenseState();
    }
})();
