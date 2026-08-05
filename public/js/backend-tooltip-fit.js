(function () {
    "use strict";

    // Contao's backend tooltip (vendor contao/core-bundle assets/scripts/tips.js)
    // always opens 0-60px below its trigger and is positioned with
    // `position: absolute`, so a tall tooltip - e.g. the 3-paragraph help text
    // for "Links aus den Seiten übernehmen" on the OpenAI config - pushes the
    // document taller than the viewport whenever its trigger sits near the
    // current bottom of the page. The extra document height makes the browser
    // show a scrollbar, which narrows the viewport just enough to rewrap this
    // bundle's tight two-column fields, moving the trigger out from under the
    // pointer. That closes the tooltip, the page shrinks back, the scrollbar
    // disappears, the layout un-wraps, the pointer is over the trigger again -
    // and the cycle repeats as a flicker.
    //
    // Rather than special-case one field, this keeps ANY Contao tooltip fully
    // inside the *currently visible* viewport - flipping it above its trigger
    // when there is no room below. A tooltip that never extends past the
    // visible viewport can never grow the document's scroll height, so the
    // scrollbar - and the flicker it causes - never appears in the first
    // place. Paired with the max-height cap on .tip in backend.css, which
    // guarantees the tooltip's own box is never taller than the viewport to
    // begin with.
    if (!document.body || !document.body.classList.contains("be_main")) {
        return;
    }

    var GAP = 16;

    function clampToViewport(tip) {
        if (tip.style.display !== "block") {
            return;
        }

        var top = parseFloat(tip.style.top) || 0;
        var height = tip.offsetHeight;
        var viewportDocTop = window.scrollY;
        var viewportDocBottom = window.scrollY + window.innerHeight;

        if (top + height <= viewportDocBottom) {
            return; // fits below already - leave Contao's own placement alone
        }

        var flippedTop = top - height - GAP;

        tip.style.top = Math.max(viewportDocTop + GAP, flippedTop) + "px";
    }

    function watch(tip) {
        var adjusting = false;

        new MutationObserver(function () {
            if (adjusting) {
                return;
            }

            adjusting = true;
            clampToViewport(tip);
            adjusting = false;
        }).observe(tip, { attributes: true, attributeFilter: ["style"] });
    }

    // The tip <div> is created once by tips.js and reused for every hover, but
    // only appended to <body> lazily on the first tooltip shown - so watch for
    // it to appear rather than looking it up immediately.
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 1 && node.classList && node.classList.contains("tip")) {
                    watch(node);
                }
            });
        });
    }).observe(document.body, { childList: true });
})();
