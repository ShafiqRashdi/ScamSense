// Generates public/images/guilloche.svg, the security-print ribbon behind the
// top of every public page (ADR 0012, amendment of 2026-09-30).
//
//     node resources/svg/guilloche.mjs
//
// Two families of phase-shifted sine lines; their interference is what makes
// the woven, banknote-style pattern. Static by design. Points are rounded to
// whole pixels and sampled every 10px, which keeps the file at ~42 KB raw and
// ~14 KB gzipped without a visible change at 1440px wide.

import { writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const W = 1440;
const H = 360;
const LINES = 18;

const path = (f) => {
    let d = `M0 ${Math.round(f(0))}`;
    for (let x = 10; x <= W; x += 10) {
        d += `L${x} ${Math.round(f(x))}`;
    }
    return d;
};

const paths = [];
for (let i = 0; i < LINES; i++) {
    const phase = (i * 2 * Math.PI) / LINES;
    paths.push(path((x) => H / 2 + (70 + 28 * Math.sin((2 * Math.PI * x * 2) / W)) * Math.sin((2 * Math.PI * x) / 240 + phase)));
    paths.push(path((x) => H / 2 + (46 + 18 * Math.cos((2 * Math.PI * x * 3) / W)) * Math.sin((2 * Math.PI * x) / 160 - phase)));
}

// Brand green ($sc-brand). The page sets the overall strength with opacity.
const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">` +
    `<g fill="none" stroke="#2a6152" stroke-width=".7" stroke-opacity=".5" stroke-linejoin="round">` +
    paths.map((d) => `<path d="${d}"/>`).join("") +
    `</g></svg>`;

const out = fileURLToPath(new URL("../../public/images/guilloche.svg", import.meta.url));
writeFileSync(out, svg);
console.log(`wrote ${out} (${(svg.length / 1024).toFixed(1)} KB)`);
