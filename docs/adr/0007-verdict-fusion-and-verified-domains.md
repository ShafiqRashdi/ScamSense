# ADR 0007 — Verdict fusion, and the verified institutional domain gate

- **Status:** Accepted
- **Date:** 2026-08-11
- **Phase:** 5 (Laravel integration)
- **Relates to:** `adr/0004` (threshold), `adr/0006` (real-site false positives)

---

## Context

Laravel must combine up to three signals into one verdict:

1. the ML service's probability and indicators,
2. Google Safe Browsing and VirusTotal reputation lookups,
3. lexical facts about the URL that need no model.

The combination rule was going to be written implicitly by whoever wrote `DetectionService`
first. It is instead decided here, because one question inside it has a non-obvious answer and a
large blast radius: **may clean threat intelligence lower a high model score?**

`https://www.maybank2u.com.my/home/m2u/common/login.do` is the test case. The model gives it
0.9337 — high band, a genuine bank login page presented to the user as dangerous (`adr/0006`).

---

## Decision 1 — Threat intelligence is asymmetric. It may escalate, never de-escalate.

**A threat-intel hit raises the verdict. A clean threat-intel result changes nothing.**

The temptation is obvious: Google Safe Browsing returns clean for `maybank2u.com.my`, so use that
to pull the verdict down and the headline false positive disappears.

That reasoning is wrong, and the reason it is wrong is the whole point of this section.

**A clean lookup is absence of evidence, not evidence of absence.** Safe Browsing returning "no
match" means "we have not flagged this", which is the default state of every URL on the internet
— including every phishing domain registered in the last few hours. Blocklists are inherently
reactive: a domain is added *after* it has been reported, analysed and confirmed, which typically
takes hours to days.

So if a clean lookup could downgrade a verdict, it would downgrade most reliably on **freshly
registered phishing domains** — exactly the URLs where the lexical model is the only defence and
where a missed scam costs a user money. The mitigation would be strongest precisely where it is
most harmful. It would also hand attackers a trivial evasion: register a new domain, use it
within the blocklist's reporting lag, and ScamSense's own threat-intel integration argues on your
behalf.

A hit is different in kind. When VirusTotal reports engines flagging a URL, that is a positive
assertion backed by observed behaviour. Positive assertions can raise a verdict; the lack of one
cannot lower it.

**Rule:** threat intelligence contributes only in the escalating direction. A provider being
unreachable, rate-limited, or returning clean are all treated identically — as no information —
and the verdict is marked partial when a provider was unavailable, so the user knows a signal was
missing rather than negative.

## Decision 2 — Registry facts may gate the verdict, and they are a different kind of evidence

The verified-domain gate is **not** the same move as Decision 1, and the distinction is the
substance of this ADR.

| | Clean threat-intel lookup | Verified institutional domain |
|---|---|---|
| Claim | "Not currently on a blocklist" | "This registrable domain is operated by a licensed institution" |
| Type | Absence of a report | Positive, externally verifiable fact |
| Stability | Changes hourly | Changes when a licence or registry entry changes |
| Attacker control | Trivial — use a new domain | Requires compromising a registry or a licensed institution |
| Verifiable by a third party | Only as "we didn't find it" | Yes — BNM's licensed-institution register, MYNIC's `.gov.my` policy |

`hasil.gov.my` is not merely un-blocklisted; it is under a suffix that **only an accredited
Malaysian government body can register**. `maybank2u.com.my` is the published domain of an
institution on Bank Negara Malaysia's register of licensed banks. These are facts about the world
that an examiner can check independently, not inferences from silence.

That is why one may gate a verdict and the other may not.

### What the gate does, precisely

When a submitted URL's **registrable domain** is on the verified list, and no threat-intel hit
exists, the verdict's risk level is **capped at Low** and an indicator is recorded:

```
code:     verified_institutional_domain
source:   registry
evidence: maybank2u.com.my
weight:   0.0
```

Four constraints make this safe:

1. **Exact registrable-domain match only.** `maybank2u.com.my` matches; `maybank2u.com.my.evil.xyz`
   does not, because its registrable domain is `evil.xyz`. Substring or suffix matching against
   arbitrary domains would turn the mitigation into a bypass — an attacker would simply append a
   verified name to their own domain. A test asserts this explicitly.
2. **A threat-intel hit overrides the gate.** Verified domains do get compromised: open redirects,
   subdomain takeovers, hosted content. Positive evidence of active malice outranks a registry
   fact about ownership.
3. **The model score is not altered.** The gate changes the *verdict band*, not the probability.
   The raw score stays in the response, stays in the database, and stays reportable, so nothing
   downstream is measuring a doctored number.
4. **The reason is recorded and shown.** The user is told the verdict was lowered because the
   domain is a verified institution. A silent override would be worse than the false positive it
   fixes — it would make the system unexplainable at exactly the moment it disagrees with itself.

### Capped at Low rather than forced to "legitimate"

The gate lowers the risk band; it does not assert the URL is safe. A verified bank's domain can
still host a compromised page, and ScamSense never fetches the URL so it cannot know. "We
recognise this as Maybank's real domain" is a claim the registry supports. "This page is safe" is
not.

## Decision 3 — This is a safety mitigation, and must never be reported as model accuracy

**The gate does not improve the classifier. It compensates for a known corpus defect.**

Any evaluation of the URL model — every figure in `docs/RESULTS.md`, every number in Chapter 4 —
is measured on the **raw model output with the gate disabled**. Adding the allowlist and then
reporting a higher F1 would be measuring the allowlist, not the model, and on a test set whose
domains were chosen without reference to it.

Three enforcement points:

- The gate lives in Laravel, in `App\Services\Detection`. The ML service does not know it exists,
  so no training or evaluation path can see it.
- `metrics.json` and the training scripts are untouched by this ADR.
- A test asserts that the gate's indicator carries `source: registry`, distinct from `model`, so
  the two can never be conflated in an analysis of indicator provenance.

The honest description for the write-up: *a defence-in-depth control that prevents the system
telling a user their own bank's website is dangerous, applied because the underlying corpus
under-represents legitimate authenticated pages.* It buys time for the corpus fix; it is not the
fix.

## Decision 4 — The fusion order

```
1. Model unavailable?            -> partial verdict, label unknown, is_partial = true
2. Threat-intel HIT?             -> risk = High, escalation indicator recorded
3. Verified institutional domain -> risk capped at Low, registry indicator recorded
4. Otherwise                     -> band the model probability: <0.20 low, <=0.60 medium, else high
5. Any provider unavailable?     -> is_partial = true, reason recorded
```

Steps 2 and 3 are ordered deliberately: escalation is evaluated before the gate, so a compromised
verified domain still reaches the user as high risk.

Step 5 is independent of the outcome. A verdict can be both usable and partial, and the UI says
so — AGENTS.md §2 rule 4 requires the user to know when a signal was missing.

## The allowlist contents

Held in `config/scamsense.php` as data, not code, so it is reviewable in one place and changeable
without touching logic.

**Institutional suffixes** — registration restricted by policy, so no per-domain list is needed
and future government services are covered automatically:

`gov.my`, `edu.my`, `mil.my`

**Licensed financial institutions** — verifiable against Bank Negara Malaysia's published
register of licensed banks: Maybank, CIMB, Public Bank, RHB, Hong Leong, AmBank, Bank Islam, BSN,
Affin, OCBC, UOB, HSBC, Standard Chartered, and the DuitNow/PayNet operators.

**Licensed utilities, telcos and major platforms** — TNB, Syabas, TM/Unifi, Maxis, Celcom, Digi,
U Mobile, Astro, Pos Malaysia, and the e-wallet operators licensed by BNM.

Each entry is a registrable domain, not a pattern.

### Residual risk: the gate caps every path under a verified domain

Matching is on the registrable domain, so a match caps the verdict for **any** URL on that
domain — every path, every subdomain, every query string. That is a deliberate simplification
with a real cost, and it is stated here rather than discovered later.

Three shapes are not addressed by the gate itself:

- **Open redirects.** `bank.com.my/redirect?to=evil.xyz` is served by the verified domain and
  will be capped at Low, even though following it lands the user somewhere hostile.
- **Compromised subpages.** A verified institution can be breached and serve phishing content
  from its own domain.
- **Subdomain takeover.** An abandoned DNS record under a verified domain, pointed at
  infrastructure an attacker now controls, inherits the cap.

What covers them, partially: **threat-intel escalation runs before the gate** (step 2 before
step 3), so once Safe Browsing or VirusTotal reports a specific URL, the verdict is High
regardless of who owns the domain. That is the mitigation, and it is genuinely useful because
these three shapes are exactly the kind that get reported and blocklisted — they are attacks on
well-known domains, which attract attention quickly.

But it is only partial, and the reasons matter:

- It inherits the reporting lag from Decision 1. A freshly abused open redirect on a real bank
  domain is clean everywhere for hours, and the gate will cap it during precisely that window.
- Threat intel is per-URL, whereas the gate is per-domain, so an escalation on one path does not
  raise the verdict for a sibling path.
- Threat intel may be absent entirely — no API key configured is the default state of this
  project, and both providers can be unreachable or rate-limited.

**The honest summary:** the gate trades a narrow, low-frequency false-negative risk on verified
domains for the removal of a broad, high-frequency false-positive problem affecting every user
who checks their own bank. Given that the false positives are certain and constant while the
open-redirect case is occasional and partly covered, that trade is worth making — but it is a
trade, not a free win, and the user-facing copy reflects it by scoping the claim to the address
rather than the page ("we checked the address, not the page").

A future improvement, out of scope here: refuse to gate a URL whose query string contains
another URL, which is the observable signature of an open redirect and needs no fetching to
detect.

### Maintenance is a real cost, and it is accepted

The list needs review when an institution changes domain, merges, or is licensed. A stale list
does not create a security hole — a missing entry means a genuine site is not gated, which is the
status quo, not a bypass. The failure mode is the safe direction.

The risk to watch is the opposite: an entry added carelessly for a domain the project does not
control. Additions should cite the register that verifies them.

## Consequences

- The Maybank login case is resolved for the user without touching the model or the threshold.
- The seven medium-band cases (`adr/0006`) are also gated, but they were already the honest
  answer; the gate makes them low rather than uncertain, which for a verified institution is
  correct.
- Chapter 4 must present the model figures and the mitigation separately. Conflating them would
  be the single easiest way to lose credibility in the viva.
- The corpus work in `adr/0006` remains outstanding. This ADR reduces user harm; it does not
  close that finding.
