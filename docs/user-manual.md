# ScamSense — user manual
# ScamSense — panduan pengguna

*English first, Bahasa Malaysia follows. Both cover the same ground.*

---

# Part 1 — For the public

## What ScamSense does

You paste a suspicious message or a link. ScamSense tells you how risky it looks
and explains why, in plain language, in English or Malay.

It is a second opinion, not a guarantee. It is meant for the moment when
something feels wrong and you want to check before you click.

## What ScamSense cannot do

Say this plainly, because the honest limits are part of using it well:

- **It cannot open the link.** ScamSense never visits the address you paste. It
  examines how the address is *written*, and asks two security services whether
  the link has been reported. It never sees the page.
- **It cannot be certain.** Sometimes it will say so directly. That answer is
  more useful than a confident guess.
- **It cannot get your money back.** If you have already paid or shared your
  details, stop reading and contact your bank now. See *If you have already
  lost money* below.

## Checking something

1. Open the website.
2. Choose **A message** or **A link**.
3. Paste what you received.
4. Press **Check it**.

Switch between English and Malay with the **EN** / **BM** buttons at the top.

## Understanding the answer

You get one of four answers. Each has a colour, an icon and words — the words
are what matters, and they are there so the answer works if you cannot easily
tell the colours apart.

### 🛑 Likely a scam

Several things about this match what scams look like.

**Do not open it.** Do not enter a password, banking detail or personal
information. If somebody sent it to you, do not reply.

### ⚠️ We are not certain

Parts of it look odd, but plenty of genuine websites look this way too.

This is a real answer, not a failure. Many legitimate links land here.

**What to do:** contact the company yourself, using a phone number or app you
already have. Not a number from the message. If you were not expecting the
message, that is the strongest warning sign there is.

### ✅ No warning signs

It does not have the features usually seen in scams.

This is not a guarantee of safety. If the message arrived unexpectedly, be
careful anyway.

### ✓ This is the real [company] website

The strongest reassurance available. The address belongs to a company whose
domain can be checked against an official register — a licensed bank, or a
`.gov.my` address only a Malaysian government agency can register.

**Read the part that says "We checked the address, not the page."** It means
what it says. The address is genuinely that company's. Whether the specific page
is safe is something ScamSense cannot see.

## The most useful habit: read the address

ScamSense shows you how, and it is worth learning because it works even when
ScamSense is not open.

Look at the part just before the first single `/`. That is the real address.

```
https://www.maybank2u.com.my/home/login     ← maybank2u.com.my — real
https://maybank2u.com.my.secure-verify.xyz  ← secure-verify.xyz — NOT Maybank
```

Scam links copy the *name* but never the *address*. Whatever appears before that
first slash is where you are actually going.

## What happens to what you paste

- Phone numbers, IC numbers, emails and account numbers are **removed before
  anything is saved**.
- The message is kept for a limited period — the form tells you exactly how long
  — so detection can be improved, and is then deleted automatically.
- The link you paste is **never opened**.
- You are never asked who you are. There is no account.

## Telling us we got it wrong

Under each result: **This looks right** or **I think this is wrong**.

Every report is read by a person. Reports do not change the checks by
themselves — that is deliberate, so nobody can teach ScamSense that a real scam
is safe by reporting it enough times.

## If you have already lost money

ScamSense cannot help with this. Act quickly:

1. **Call your bank immediately** and ask them to freeze the transaction. Use
   the number on your card, not one from any message.
2. **Report to the police** — the National Scam Response Centre (NSRC) is
   **997**, open 8am to 8pm.
3. **Check and report the account** at Semak Mule
   (`https://semakmule.rmp.gov.my`), the PDRM service for checking accounts and
   numbers used in scams.

Speed matters more than anything else. Money can sometimes be stopped in the
first hours.

---

# Part 2 — For administrators

## Signing in

Go to `/login`. There is no public registration and no password reset screen —
accounts are created by the seeder.

If you are locked out, someone with server access runs:

```bash
php artisan scamsense:admin-password
```

It asks for the new password twice and does not echo what you type. Changing
`ADMIN_PASSWORD` in the environment on its own does **not** work — the seeder
only ever creates an account, it never modifies one — so after changing that
value, run the command with `--from-env` to apply it.

Signing in lands you on the dashboard.

## The dashboard

| Panel | What it tells you |
|---|---|
| Submissions over time | Daily volume by type. Empty days show as zero, so a quiet week looks quiet |
| Verdict distribution | How results split across the risk bands |
| Model performance | Read from the committed `metrics.json` files, so it always matches the report |
| Most frequent indicators | Which signals fire most, split by source |
| Threat-intel cache | Hit rate, and how close you are to exhausting the free API tier |
| Retraining set | How much came from users versus the original corpus |
| Degraded verdicts | How often a check could not complete — **watch this one** |
| Verdicts by model version | Which model produced which results |

**A rising degraded-verdict rate is the number worth watching.** It means a
dependency is failing quietly: the ML service is down, or a threat-intel
provider is unreachable. Users still get answers, so nothing looks broken from
the outside.

Under **Model performance**, each figure carries the sentence it must be quoted
with. Quote the whole sentence. *"F1 on a 528-message collected Malaysian
corpus"* means something different from *"97% accurate"*, and only the first is
true.

## The moderation queue

At `/admin/moderation`. Reports from the public wait here until you decide.

**Approving a report adds it to the retraining set.** You choose the correct
label — not the one the reporter asserted. There is no default selection and no
bulk approve, both deliberately: a button that approves fifty items at once is a
button pressed without reading fifty items.

For each item you see the verdict given, the submitted content (defanged and not
clickable), and any comment.

**Ask: is the reporter right?** Some are not. A report saying *"I think this is
correct because the link has Maybank in it"* is exactly the mistake ScamSense
exists to prevent — reject it.

- **Approve** — choose the correct label. The item enters the retraining set,
  tagged with your name and the time.
- **Reject** — nothing is added. Use this for mistaken or empty reports.

Approving does **not** change the model. Approved items sit in a set until
somebody deliberately exports a dataset version and trains on it. Three separate
steps between a stranger's opinion and the system's behaviour.

## Routine tasks

**Check the queue** — weekly is enough at low volume.

**Watch the degraded-verdict rate** — if it climbs, check the ML service is
running and the threat-intel keys are still valid.

**Verify the purge is running.** Old submissions should disappear on schedule.
To see what would be deleted without deleting it:

```bash
php artisan scamsense:purge --dry-run
```

**Keep the verified-address list current.** It lives in
`backend/config/verified_institutions.php`. Every entry cites the register that
verifies it. Adding a domain you cannot verify is the one genuinely dangerous
mistake available in that file — it would tell users an address is a bank's when
it is not.

## When something looks wrong

**Every verdict says "could not check".** Run this first:

```bash
php artisan scamsense:doctor
```

It sends a real signed request for both a link and a message and tells you which
link in the chain is broken. There are three causes and they look identical from
the outside:

- **The ML service is not running.** Start it — `start.bat` does both services.
- **It is running with the wrong shared secret.** This is the one that wastes
  time, because the service is genuinely healthy: it is listening, both models
  are loaded, and `/health` reports `models_loaded: true`. Only the signed
  request fails, with a 401. Restart the ML service with `ML_SERVICE_SECRET`
  taken from `backend/.env`. **Do not trust `/health` here** — it is
  unauthenticated by design, so it never exercises the signature at all.
- **An artefact failed to load.** Usually a scikit-learn version difference
  between the machine that trained the model and the one serving it. The ML
  service prints the reason at startup.

Users still get answers marked incomplete throughout, so nothing looks broken
from the outside — which is exactly why this is worth checking before a demo
rather than during one.

**A genuine site is flagged as a scam.** Known behaviour with known limits, set
out in `docs/adr/0006`. If it is a Malaysian institution, adding it to the
verified list is the correct fix — with its register citation.

**Nothing in the moderation queue, ever.** Either nobody is reporting, or the
feedback form is broken. Submit something yourself and use the form to check.
