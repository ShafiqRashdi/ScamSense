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

/*
 | A textarea that grows with what is in it.
 |
 | The box was a fixed six rows, which is a lot of empty space for a page whose
 | first job is to look like it is paying attention. It now starts at three and
 | grows to fit, which reads as the page responding rather than waiting.
 |
 | Capped, because an unbounded textarea pushes the submit button off the screen
 | and a 5,000-character message would do exactly that. Past the cap it scrolls.
 */
function growToFit(field) {
    const cap = window.innerHeight * 0.5;

    // Reset first: without this the box can only ever get taller, because
    // scrollHeight is measured against the height already set.
    field.style.height = "auto";
    field.style.height = `${Math.min(field.scrollHeight, cap)}px`;
    field.style.overflowY = field.scrollHeight > cap ? "auto" : "hidden";
}

document.querySelectorAll("textarea[data-autogrow]").forEach((field) => {
    field.addEventListener("input", () => growToFit(field));

    // Also on load, so a form redisplayed after a validation error comes back
    // sized to the content the user already typed rather than collapsed.
    if (field.value !== "") {
        growToFit(field);
    }
});

/*
 | Tap-to-fill examples.
 |
 | A first-time visitor has nothing to paste — they came to look, not because
 | something happened to them — and the largest element on the page was an empty
 | box. These fill it, and they make a demonstration one tap rather than a paste
 | from a notes file.
 |
 | Each example also sets the message/link toggle, because an example that fills
 | the box and leaves the wrong type selected teaches the user that the toggle
 | does not matter, which is the opposite of true.
 */
document.querySelectorAll("[data-example-text]").forEach((chip) => {
    chip.addEventListener("click", () => {
        const field = document.querySelector("textarea[data-autogrow]");
        const type = document.querySelector(
            `input[name="type"][value="${chip.dataset.exampleType}"]`,
        );

        if (!field) {
            return;
        }

        if (type) {
            type.checked = true;
        }

        field.value = chip.dataset.exampleText;
        growToFit(field);

        // Focus the box rather than the button. The next thing the user does is
        // read what landed there or press Check it, and leaving focus on a chip
        // means a keyboard user has to travel back past every other chip.
        field.focus();
        field.setSelectionRange(field.value.length, field.value.length);
    });
});
