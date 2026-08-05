(function () {
    "use strict";

    // Every backend field help text is wrapped by Contao core in
    // <p class="tl_help tl_tip">, clipped to one line via CSS, and the full
    // text is only shown in a floating popup on hover (see
    // vendor/contao/core-bundle/assets/scripts/tips.js and the .tl_tip /
    // .tip rules in the flexible backend theme). That works fine for a short
    // sentence. It does not work for a multi-paragraph text such as "Links
    // aus den Seiteninhalten übernehmen" on the OpenAI config: the popup is
    // taller than most viewports, opens with position:absolute below the
    // trigger, and - critically - Contao's core .tip rule sets
    // pointer-events:none on it, so it can never be scrolled by the user. A
    // reader trying to scroll down to see the rest of the text has their
    // wheel input fall straight through the popup to the page underneath,
    // which scrolls instead, moving the trigger out from under the pointer.
    // That closes the popup, the page jumps back, the pointer lands on the
    // trigger again, and the cycle repeats - a flickering scroll loop that
    // no amount of repositioning the popup can fix, because the popup
    // itself is never interactive.
    //
    // The only reliable fix is to not use the hover popup at all for a
    // field whose help text is known to be this long. For the field IDs
    // listed below, this suppresses the popup - via a capturing listener
    // that runs (and stops the event) before Contao's own listener sees it,
    // so it works regardless of script load order or DOM re-renders - and
    // the paired CSS rule in backend.css shows the full help text inline
    // and permanently instead, the way Contao's help texts worked before
    // the hover-tooltip UI existed.
    var LONG_HELP_FIELD_IDS = ["ctrl_auto_update_include_links"];

    function isSuppressedTip(el) {
        if (!el || typeof el.matches !== "function" || !el.matches("p.tl_help.tl_tip")) {
            return false;
        }

        var widget = el.closest(".widget");
        if (!widget) {
            return false;
        }

        return LONG_HELP_FIELD_IDS.some(function (id) {
            return !!widget.querySelector("#" + id);
        });
    }

    // Checked live against the DOM on every event rather than caching the
    // element, so this keeps working across Turbo re-renders without its
    // own re-init hook.
    ["mouseenter", "touchend"].forEach(function (type) {
        document.addEventListener(type, function (e) {
            if (isSuppressedTip(e.target)) {
                e.stopImmediatePropagation();
            }
        }, true);
    });
})();
