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

    const idleLabel = button.querySelector(".sc-cta__label")?.textContent ?? "";
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

        const label = button.querySelector(".sc-cta__label");
        if (label) {
            label.textContent = idleLabel;
        }

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

        /*
         | The rail lives in the button's own markup and is revealed by CSS on
         | `aria-busy`, so there is nothing to build here -- only the label to
         | swap. Replacing the button's children the way an earlier version did
         | would have destroyed the rail along with the label.
         */
        const label = button.querySelector(".sc-cta__label");

        if (label) {
            label.textContent = button.dataset.busyLabel;
        }

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

/*
 | Length, shown only once it starts to matter — and paste confirmation.
 |
 | Two small things attached to the same field, kept together because both
 | answer the same question: did what I just did register?
 |
 | The cap is per submission type and comes from the markup, which reads it from
 | the same config VerifySubmissionRequest validates against. A counter that
 | disagrees with the validator is worse than no counter at all: it tells the
 | user they have room and then the server refuses the message.
 */
function attachLengthFeedback(field) {
    const counter = document.querySelector("[data-counter]");
    const announce = document.querySelector("[data-counter-announce]");

    if (!counter) {
        return;
    }

    // Below this share of the cap the counter stays hidden. A character count
    // sitting there from the first keystroke is a warning about a problem
    // nobody has yet.
    const QUIET_BELOW = 0.9;

    let wasOver = false;

    const cap = () => {
        const url = document.querySelector('input[name="type"][value="url"]');

        return Number(
            url?.checked ? field.dataset.maxUrl : field.dataset.maxMessage,
        );
    };

    const update = () => {
        const limit = cap();
        const used = field.value.length;
        const left = limit - used;
        const over = left < 0;

        counter.hidden = used < limit * QUIET_BELOW;
        counter.classList.toggle("text-danger", over);
        counter.classList.toggle("fw-semibold", over);

        counter.textContent = over
            ? counter.dataset.counterOver.replace(":count", String(-left))
            : counter.dataset.counterLeft.replace(":count", String(left));

        /*
         | Announce the boundary, not the count.
         |
         | A live region that fires on every keystroke is unusable with a screen
         | reader — it would read a new number for every letter typed. What
         | actually changes what the user has to do is crossing into "too long"
         | and back out, so only that is spoken.
         */
        if (announce && over !== wasOver) {
            announce.textContent = over ? counter.textContent : "";
            wasOver = over;
        }
    };

    field.addEventListener("input", update);

    // The cap changes with the toggle, so a message of 3,000 characters becomes
    // far too long the moment "A link" is selected. The counter has to follow.
    document
        .querySelectorAll('input[name="type"]')
        .forEach((radio) => radio.addEventListener("change", update));

    /*
     | Paste confirmation.
     |
     | Most of these users will paste rather than type — the whole premise is
     | that something arrived and they want it checked — and until now nothing
     | acknowledged it. On a phone the pasted text can land below the visible
     | part of a box that has not finished growing, so "did that work?" is a
     | real question rather than a hypothetical one.
     |
     | The class is removed on animationend rather than a timer, so it cannot
     | get out of step with the stylesheet if the duration changes there.
     */
    field.addEventListener("paste", () => {
        field.classList.remove("sc-pasted");

        // Force a reflow, or removing and re-adding the class within one frame
        // is a no-op and a second paste flashes nothing.
        void field.offsetWidth;
        field.classList.add("sc-pasted");
    });

    field.addEventListener("animationend", () => field.classList.remove("sc-pasted"));

    update();
}

document.querySelectorAll("textarea[data-autogrow]").forEach(attachLengthFeedback);
