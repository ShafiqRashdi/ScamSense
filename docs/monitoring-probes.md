# Monitoring probes

From 1 to 6 October 2026, a scheduled job submitted five fixed probes a day
to the live site, at a different random time each day, to show that the
deployed system stayed up and kept answering the same way. **These are not
visitors.** Every probe is a real row in `submissions`, so the admin
dashboard's volume chart includes them.

## How to quote submission numbers

Never quote the dashboard total as usage. Count on the VM:

```bash
sudo bash /var/www/scamsense/deploy/monitoring/count.sh
```

It splits each day into `probes` and `real`, identifying probes by their
`content_hash` (the probe texts contain nothing the redactor changes). "Real"
means "not a probe", which includes the developer's own manual testing. It
does not mean members of the public.

The honest sentence for the report or the panel is of this form:
*"An automated check submitted five fixed probes a day from 1 to 6 October;
every run passed"* (or, if one failed, what failed and why).

## The probes

The script is `deploy/monitoring/probes.sh`. Set A (1–5) and set B (6–10)
alternate by day. "Observed" is what the live system answered on 2026-09-30
and is what each run checks for: **the probes detect change, not
correctness.** Where observed and truth differ, that is a real finding about
the models, recorded here rather than hidden by choosing easier probes.

| # | Type | Content (abridged) | Truth | Observed 30 Sep | Match |
|---|---|---|---|---|---|
| 1 | URL | `maybank2u.com.my.secure-verify.xyz/login` | scam | Likely a scam | ✓ |
| 2 | URL | `www.maybank2u.com.my/home/m2u/common/login.do` | genuine | Verified address | ✓ |
| 3 | Message | Pos Laju parcel, update address within 24 hours | scam | Likely a scam | ✓ |
| 4 | Message | "Mak, saya balik lambat sikit malam ni…" | ordinary | Not certain | ✗ |
| 5 | URL | `lhdn-refund.site/claim` | scam | Likely a scam | ✓ |
| 6 | URL | `www.hasil.gov.my` | genuine | Verified address | ✓ |
| 7 | Message | "Tahniah! Anda memenangi RM5,000…" | scam | Likely a scam | ✓ |
| 8 | Message | "Hi team, the meeting is moved to 3pm…" | ordinary | Not certain | ✗ |
| 9 | Message | "Akaun bank anda telah dibekukan…" | scam | Not certain | ✗ |
| 10 | Message | "Jangan lupa beli susu dan roti…" | ordinary | Not certain | ✗ |

**What the mismatches say.** All three short ordinary messages land in the
medium band, and one account-freeze scam does too. No probe was put in the
wrong *extreme* (no ordinary message called a scam, no scam called safe), but
the message model is unsure on short everyday texts. This is consistent with
its training corpus (collected scam and legitimate messages, not casual
family chat) and is not a figure: ten hand-written probes are an
illustration, not an evaluation. The evaluation is `docs/RESULTS.md`.

The URL probes behave as designed: both genuine addresses are caught by the
verified-address list (which is not AI, and caps the band rather than changing
the score; ADR 0007), and all three look-alikes are rated likely scams.

## Schedule and results

The runs are cron entries on the VM itself
(`deploy/monitoring/scamsense-probes.cron`, installed as
`/etc/cron.d/scamsense-probes`). A cloud-scheduled agent was tried first and
could not run: its sandbox's network policy rejected connections to the site.

| Day | Malaysia time | UTC | Set |
|---|---|---|---|
| Thu 1 Oct | 21:58 | 13:58 | A |
| Fri 2 Oct | 12:42 | 04:42 | B |
| Sat 3 Oct | 11:43 | 03:43 | A |
| Sun 4 Oct | 21:15 | 13:15 | B |
| Mon 5 Oct | 13:04 | 05:04 | A |
| Tue 6 Oct | 20:17 | 12:17 | B |

Each run appends to `/var/log/scamsense-probes.log` on the VM:

```bash
ssh -i ~/.ssh/scamsense_vm azureuser@scamsense.malaysiawest.cloudapp.azure.com cat /var/log/scamsense-probes.log
```

The probes go out through the site's public address, so they exercise DNS,
TLS, Caddy, PHP, MySQL and the ML service. What they cannot report is the VM
itself being down: then no run happens and no line is written, so a missing
day in the log is itself the signal.

## Operational notes

- Each probe is a fresh visitor: its own session, CSRF token and English
  locale. The run ends by checking that BM on the result page stays on it.
- Five probes a run keeps well under the rate limit (10 a minute per IP). Ten
  in a minute from one address triggers HTTP 429; that is the limit working.
- Probes never press a feedback button, so nothing reaches the moderation
  queue or the retraining set.
- Calibration on 30 September submitted 24 probe rows before the schedule
  started; `count.sh` includes them under that date.
