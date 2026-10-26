"use strict";

// Run with: node --test scripts/check-backend-tooltip-fit.js
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..");
const handlers = new Map();
vm.runInNewContext(fs.readFileSync(path.join(root, "public/js/backend-tooltip-fit.js"), "utf8"), {
    document: {
        addEventListener(type, callback, capture) {
            assert.equal(capture, true);
            handlers.set(type, callback);
        }
    }
});

test("covers legacy events and Contao 6 delegated pointer events", () => {
    assert.deepEqual([...handlers.keys()], ["mouseenter", "touchend", "pointerover"]);
});

const fields = [
    "ctrl_auto_update_include_links",
    "ctrl_admin_notify_enabled",
    "ctrl_admin_notify_recipients",
    "ctrl_admin_notify_language"
];

for (const id of fields) {
    test(id + " keeps full help inline and suppresses hover/touch popups", () => {
        const css = fs.readFileSync(path.join(root, "public/css/backend.css"), "utf8");
        const selector = ".widget:has(#" + id + ") > p.tl_help.tl_tip";
        const selectorOffset = css.indexOf(selector);
        assert.notEqual(selectorOffset, -1);
        const rule = css.slice(css.indexOf("{", selectorOffset) + 1, css.indexOf("}", selectorOffset));
        assert.match(rule, /height:\s*auto;/);
        assert.match(rule, /overflow:\s*visible;/);
        assert.match(rule, /white-space:\s*normal;/);

        for (const handler of handlers.values()) {
            let stopped = false;
            handler({
                target: {
                    closest: () => ({
                        closest: () => ({ querySelector: (selector) => selector === "#" + id })
                    })
                },
                stopImmediatePropagation() { stopped = true; }
            });
            assert.equal(stopped, true);
        }
    });
}

test("unrelated field tooltips remain enabled", () => {
    for (const handler of handlers.values()) {
        handler({
            target: {
                closest: () => ({
                    closest: () => ({ querySelector: () => null })
                })
            },
            stopImmediatePropagation() { assert.fail("Unrelated tooltip was suppressed"); }
        });
    }
});
