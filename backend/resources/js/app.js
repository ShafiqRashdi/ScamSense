// ScamSense — application JavaScript
//
// Bootstrap's bundle includes Popper, which the dropdown and tooltip components
// need. AGENTS.md rules out jQuery; the small amount of interactivity this
// project needs is handled by Bootstrap's own data-attribute API plus vanilla
// JS, so nothing else is imported here.

import * as bootstrap from "bootstrap";

window.bootstrap = bootstrap;

const prefersReducedMotion = () =>
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

/*
 | Links made safe, in the browser, by the same rule as DefangService.
 |
 | The same pattern as DefangService::defangUrlsIn: a URL starts with a scheme
 | or `www.`. The scheme's "tt" becomes "xx" and every dot in the host becomes
 | "[.]"; the path is left alone. Returned as parts so the page can show each
 | change happening rather than the text simply being different.
 |
 | Built as DOM text nodes by the caller, never as HTML: this is the user's own
 | pasted text and it must not become markup on the way back to the screen.
 */
function defangParts(text) {
    const parts = [];
    const url = /(?:https?:\/\/|www\.)[^\s<>"']+/gi;
    let last = 0;

    for (const match of text.matchAll(url)) {
        if (match.index > last) {
            parts.push({ text: text.slice(last, match.index) });
        }

        const whole = match[0];
        const scheme = (whole.match(/^https?:\/\//i) || [""])[0];
        const rest = whole.slice(scheme.length);
        const slash = rest.indexOf("/");
        const host = slash === -1 ? rest : rest.slice(0, slash);

        if (scheme) {
            parts.push({ text: scheme.slice(0, 1) });
            parts.push({ text: scheme.slice(1, 3), to: "xx" });
            parts.push({ text: scheme.slice(3) });
        }

        host.split(".").forEach((label, i) => {
            if (i > 0) {
                parts.push({ text: ".", to: "[.]" });
            }
            parts.push({ text: label });
        });

        if (slash !== -1) {
            parts.push({ text: rest.slice(slash) });
        }

        last = match.index + whole.length;
    }

    if (last < text.length) {
        parts.push({ text: text.slice(last) });
    }

    return parts;
}

/*
 | Submit feedback while a verdict is being fetched.
 |
 | Objective O2 allows three seconds end to end, and on a mid-range Android over
 | mobile data it uses most of them. For a user who is already anxious — the
 | premise of the whole interface, see ADR 0012 — silence reads as failure, and
 | the reasonable response to a button that did nothing is to press it again.
 | That submits the message twice and the second verdict overwrites the first.
 |
 | So the wait is SHOWN: the box gives way to a reading view of what was pasted,
 | its links are made safe in front of the reader, a neutral line reads down the
 | text, and a short checklist says what ScamSense is doing. Nothing is marked
 | during the wait — no finding exists yet, and inventing one would be the fake
 | progress ADR 0012 rules out. The marks arrive on the result page.
 |
 | Progressive enhancement: none of this is required for the form to work. With
 | no JavaScript the button is an ordinary submit button and nothing looks wrong.
 | The CSP is `script-src 'self'`, so labels travel in data attributes.
 */
function markBusyOnSubmit(form) {
    const button = form.querySelector("[data-busy-label]");

    if (!button) {
        return;
    }

    const idleLabel = button.querySelector(".sc-cta__label")?.textContent ?? "";
    const panel = form.querySelector("[data-busy-panel]");
    const status = form.querySelector("[data-busy-status]");
    const items = Array.from(form.querySelectorAll("[data-busy-steps] li"));
    const hideable = Array.from(form.querySelectorAll("[data-busy-hide]"));
    const readingPanel = form.querySelector("[data-reading-panel]");
    const reading = form.querySelector("[data-reading]");
    const readingLabel = form.querySelector("[data-reading-label]");
    const field = form.querySelector("textarea[name='content']");

    let submitted = false;
    let timers = [];

    const later = (fn, ms) => timers.push(window.setTimeout(fn, ms));

    const setStep = (index) => {
        items.forEach((item, i) => {
            item.classList.toggle("is-now", i === index);
            item.classList.toggle("is-done", i < index);
        });

        if (status && items[index]) {
            status.textContent = items[index].textContent.trim();
        }
    };

    const reset = () => {
        submitted = false;
        timers.forEach((id) => window.clearTimeout(id));
        timers = [];

        const label = button.querySelector(".sc-cta__label");
        if (label) {
            label.textContent = idleLabel;
            label.classList.remove("is-swapping");
        }

        button.removeAttribute("aria-busy");
        hideable.forEach((el) => {
            el.hidden = false;
        });
        items.forEach((item) => item.classList.remove("is-now", "is-done"));

        if (panel) {
            panel.hidden = true;
        }
        if (readingPanel) {
            readingPanel.hidden = true;
        }
        if (reading) {
            reading.replaceChildren();
        }
        if (status) {
            status.textContent = "";
        }
    };

    /*
     | The reading view. Skipped entirely, leaving the form as it was, if any
     | piece of it is missing — a half-built wait is worse than the plain one.
     */
    const showReading = () => {
        if (!readingPanel || !reading || !field || field.value.trim() === "") {
            return;
        }

        const isUrl = form.querySelector("input[name='type']:checked")?.value === "url";
        if (readingLabel) {
            readingLabel.textContent = isUrl
                ? readingLabel.dataset.labelUrl
                : readingLabel.dataset.labelMessage;
        }

        const flips = [];
        const fragment = document.createDocumentFragment();

        defangParts(field.value.trim()).forEach((part) => {
            if (!part.to) {
                fragment.append(document.createTextNode(part.text));
                return;
            }
            const span = document.createElement("span");
            span.className = "sc-df";
            span.textContent = part.text;
            span.dataset.to = part.to;
            flips.push(span);
            fragment.append(span);
        });

        const scan = document.createElement("span");
        scan.className = "sc-scan";
        fragment.append(scan);

        reading.replaceChildren(fragment);
        hideable.forEach((el) => {
            el.hidden = true;
        });
        readingPanel.hidden = false;

        const moving = !prefersReducedMotion();

        later(() => {
            flips.forEach((span, i) => {
                const swap = () => {
                    span.textContent = span.dataset.to;
                    if (moving) {
                        span.classList.add("is-flipped");
                    }
                };
                moving ? later(swap, i * 70) : swap();
            });
        }, moving ? 650 : 0);

        if (moving) {
            later(() => {
                scan.style.setProperty("--sc-scan-to", `${reading.clientHeight + 8}px`);
                scan.classList.add("is-running");
            }, 1100);
        }
    };

    /*
     | The checklist. TIME-BASED, not server-reported: the page cannot know
     | which stage a single form submission is in, so the lines describe what
     | ScamSense does, in order, and stop on the last one rather than looping.
     | A list that keeps cycling after five seconds says "still working" when
     | something may be wrong.
     */
    const runSteps = () => {
        if (!panel || items.length === 0) {
            return;
        }

        panel.hidden = false;
        setStep(0);
        items.forEach((_, i) => {
            if (i > 0) {
                later(() => setStep(i), i * 1000);
            }
        });
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

        // "Check it" -> "Checking..." as a crossfade: out, swap while it is
        // invisible, back in. Instant under reduced motion.
        const label = button.querySelector(".sc-cta__label");
        if (label) {
            if (prefersReducedMotion()) {
                label.textContent = button.dataset.busyLabel;
            } else {
                label.classList.add("is-swapping");
                later(() => {
                    label.textContent = button.dataset.busyLabel;
                    label.classList.remove("is-swapping");
                }, 150);
            }
        }

        showReading();
        runSteps();
    });

    /*
     | Restore the form when the page comes back from the back/forward cache.
     |
     | Without this the sequence "check something, read the verdict, press back"
     | returns a page whose box is hidden behind a reading view and whose only
     | button is stuck busy, because bfcache restores the live DOM rather than
     | re-parsing the markup.
     */
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) {
            reset();
        }
    });
}

document.querySelectorAll("form[data-busy-form]").forEach(markBusyOnSubmit);

/*
 | Reason n and mark n light up together on hover or focus, so a reader can
 | find where in their own text each reason came from.
 */
document.querySelectorAll("[data-evidence-links]").forEach((scope) => {
    const light = (target, on) => {
        const n = target.closest("[data-n]")?.dataset.n;
        if (!n) {
            return;
        }
        scope.querySelectorAll(`[data-n="${n}"]`).forEach((el) => el.classList.toggle("is-lit", on));
    };

    ["mouseover", "focusin"].forEach((type) => scope.addEventListener(type, (e) => light(e.target, true)));
    ["mouseout", "focusout"].forEach((type) => scope.addEventListener(type, (e) => light(e.target, false)));
});

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

/*
 | Scroll reveal for the sections below the fold ("How it works", the privacy
 | panel). Fires once per section and then stops watching.
 |
 | Armed only for a section that starts OFF screen, and only when motion is
 | allowed. Content already visible at load is never hidden and re-shown, and
 | with no JavaScript (or no IntersectionObserver) nothing is ever hidden at
 | all: the CSS hides only `[data-reveal="armed"]`, which only this sets.
 */
if (!prefersReducedMotion() && "IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.dataset.reveal = "shown";
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: "0px 0px -80px 0px" },
    );

    document.querySelectorAll(".sc-reveal").forEach((section) => {
        if (section.getBoundingClientRect().top > window.innerHeight) {
            section.dataset.reveal = "armed";
            observer.observe(section);
        }
    });
}
