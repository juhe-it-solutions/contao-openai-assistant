(function () {
    "use strict";

    function findInput(fieldName) {
        return document.getElementById("ctrl_" + fieldName)
            || document.getElementById(fieldName)
            || document.querySelector('input[name="' + fieldName + '"]');
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

    function bindButton(button) {
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
                        resultSpan.innerHTML = '<span style="color:green;">API-Schluessel ist gueltig.</span>';
                        input.style.backgroundColor = "lightgreen";
                        input.style.color = "#121212";
                        return;
                    }

                    resultSpan.innerHTML = '<span style="color:red;">API-Schluessel ist ungueltig. ' + (result.message || "") + '</span>';
                    input.style.backgroundColor = "lightcoral";
                    input.style.color = "#121212";
                } catch (e) {
                    resultSpan.innerHTML = '<span style="color:red;">Fehler bei der Validierung</span>';
                }
            };

            xhr.send("action=validateApiKey&key=" + encodeURIComponent(apiKey) + "&REQUEST_TOKEN=" + encodeURIComponent(requestToken));
        });
    }

    function init() {
        var buttons = document.querySelectorAll('button[id^="apiKeyCheck_"]');
        for (var i = 0; i < buttons.length; i++) {
            bindButton(buttons[i]);
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
