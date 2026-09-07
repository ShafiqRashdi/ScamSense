// ScamSense — application JavaScript
//
// Bootstrap's bundle includes Popper, which the dropdown and tooltip components
// need. AGENTS.md rules out jQuery; the small amount of interactivity this
// project needs is handled by Bootstrap's own data-attribute API plus vanilla
// JS, so nothing else is imported here.

import * as bootstrap from "bootstrap";

window.bootstrap = bootstrap;

/*
 | Submit feedback while a verdict is being fetched.
 |
 | Objective O2 allows three seconds end to end, and on a mid-range Android over
 | mobile data it uses most of them. Until now the button did nothing visible for
 | that whole time. For a user who is already anxious — the premise of the whole
 | interface, see ADR 0012 — silence reads as failure, and the reasonable
 | response to a button that did nothing is to press it again. That submits the
 | message twice and the second verdict overwrites the first in the UI.
 |
 | Progressive enhancement: none of this is required for the form to work. The
 | markup carries the replacement label in a data attribute, so if this file
 | fails to load the button is an ordinary submit button and nothing looks wrong.
 | The CSP is `script-src 'self'`, so the label cannot be passed via an inline
 | script and a data attribute is the correct channel rather than a workaround.
 */
function markBusyOnSubmit(form) {
    const button = form.querySelector("[data-busy-label]");

    if (!button) {
        return;
    }

    const idleChildren = Array.from(button.childNodes);
    const panel = form.querySelector("[data-busy-panel]");
    const status = form.querySelector("[data-busy-status]");
    const steps = Array.from(
        form.querySelectorAll("[data-busy-steps] li"),
        (item) => item.textContent.trim(),
    );

    let submitted = false;
    let timer = null;

    const reset = () => {
        submitted = false;
        window.clearInterval(timer);
        button.replaceChildren(...idleChildren);
        button.removeAttribute("aria-busy");

        if (panel) {
            panel.hidden = true;
        }

        if (status) {
            status.textContent = "";
        }
    };

    /*
     | Rotate the status line while the browser waits for the POST to come back.
     |
     | These are TIME-BASED, not server-reported. The page has no way to know
     | which stage the request is in — it is one form submission — so the lines
     | describe what ScamSense does, in the order it does it, and stop on the
     | last one rather than looping. The bar beside them is indeterminate for
     | the same reason: a percentage would be a number nothing measures.
     |
     | Stopping on the last line matters. A carousel that keeps cycling after
     | five seconds tells the user the page is still working when in fact
     | something is wrong, which is the failure mode this project treats most
     | seriously everywhere else.
     */
    const runSteps = () => {
        if (!panel || !status || steps.length === 0) {
            return;
        }

        let index = 0;
        panel.hidden = false;
        status.textContent = steps[0];

        timer = window.setInterval(() => {
            index += 1;

            if (index >= steps.length) {
                window.clearInterval(timer);

                return;
            }

            status.textContent = steps[index];
        }, 900);
    };

    form.addEventListener("submit", (event) => {
        // A second press while the first is in flight. `pointer-events: none`
        // covers the mouse; this covers the keyboard and anything that
        // dispatches submit directly.
        if (submitted) {
            event.preventDefault();

            return;
        }

        submitted = true;
        button.setAttribute("aria-busy", "true");

        // Built as nodes rather than an HTML string. The label is a
        // translation and therefore trusted, but this is a project whose whole
        // subject is untrusted strings reaching a page, and leaving an
        // innerHTML sink here for a reviewer to find would undercut that.
        const spinner = document.createElement("span");
        spinner.className = "spinner-border spinner-border-sm me-2";
        spinner.setAttribute("aria-hidden", "true");

        // The spinner is `aria-hidden`; the label change is what a screen
        // reader announces, which is the more useful signal of the two.
        button.replaceChildren(
            spinner,
            document.createTextNode(button.dataset.busyLabel),
        );

        runSteps();
    });

    /*
     | Restore the button when the page comes back from the back/forward cache.
     |
     | Without this the sequence "check something, read the verdict, press back"
     | returns a page whose only button is permanently stuck in its busy state,
     | because bfcache restores the live DOM rather than re-parsing the markup.
     | That is a worse bug than the one this whole function exists to fix.
     */
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) {
            reset();
        }
    });
}

document.querySelectorAll("form[data-busy-form]").forEach(markBusyOnSubmit);
