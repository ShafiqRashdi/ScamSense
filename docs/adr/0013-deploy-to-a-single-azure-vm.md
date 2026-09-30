# ADR 0013 — Deploy to a single Azure VM, because Railway could not take payment

**Status:** Accepted
**Date:** 2026-09-30
**Changes the hosting target in `docs/deployment.md`. The architecture (AGENTS.md §2) is unchanged.**

---

## Context

The plan was Railway (`docs/deployment.md`): web, ML and MySQL as three services on a private
network, about $5 a month. On 30 September, a week before the presentation, Railway's Hobby
subscription failed with *"Your card was declined for an unknown reason"*, and still failed
after international and online payments were enabled on the card. The message comes from the
card issuer through Stripe and gives no reason that can be acted on.

Azure for Students, which needs no card, rejected the sign-up because it verifies by
institutional email domain. The standard Azure free account accepted the same card: $200 of
credit valid for 30 days, with a spending limit that stops resources rather than charging when
the credit runs out.

Shafiq ruled out a laptop-hosted demo behind a tunnel: the system must be online, not on a
machine in the room.

## Decision

Deploy everything to **one Ubuntu 24.04 virtual machine** (B2als_v2, 2 vCPU, 4 GB, Malaysia West,
`scamsense.malaysiawest.cloudapp.azure.com`),
set up by `deploy/azure/setup.sh` and updated by `deploy/azure/update.sh`:

- **Caddy** terminates TLS with a Let's Encrypt certificate for the VM's Azure DNS name and
  serves Laravel through PHP-FPM.
- **The ML service** runs under gunicorn as its own system user, bound to `127.0.0.1:5001`, one
  worker, in a hardened systemd unit.
- **MySQL 8** on the same machine, bound to loopback, with the two scoped users from
  `docs/deployment.md` §1: the running application cannot `DROP` or `ALTER`.
- Firewall: only 22, 80 and 443, in both Azure's network security group and `ufw`.
- The daily purge is an ordinary cron entry at 03:15 UTC.
- Secrets are generated on the VM and stay there, root-readable only.

## How each architectural rule holds

| Rule (AGENTS.md §2) | On Railway | On the VM |
|---|---|---|
| 1. ML never reachable from the internet | Private IPv6 network, *as long as nobody clicks Generate Domain* | Bound to loopback. Unreachable from outside by construction |
| 2. Laravel never runs Python; Flask never touches MySQL | Separate services | Separate processes and users; the ML user has no database credentials |
| 3. Never visit a submitted URL | Unchanged | Unchanged |
| 4. Degrade, never fail | Unchanged | Unchanged; the ML unit restarts on failure |

Rule 1 is stronger here than on Railway. The HMAC on the internal hop stays: it is defence in
depth, and it keeps the contract identical to local development.

## Alternatives rejected

- **Render.** Free services cannot be private, so the ML service would need a public URL,
  breaking rule 1. No managed MySQL, so switching would mean Postgres, which
  `docs/deployment.md` already rejected as the host dictating the design. Free web services
  also sleep after inactivity, which a live demo cannot absorb.
- **Azure App Service plus Azure Database for MySQL.** More managed, but keeping the ML service
  private needs VNet integration and private endpoints: more parts, more cost and more to go
  wrong in the week before a presentation, for a demo that one VM serves comfortably.
- **Fly.io, DigitalOcean, Oracle Cloud.** All require a card; the same decline was the likely
  outcome.
- **Laptop plus tunnel.** Works without a card, but only while the laptop is on. Ruled out.

## Consequences

- **The credit lasts 30 days** (to about 30 October 2026). Keeping the site after that means
  upgrading to pay-as-you-go, at about $31 a month for B2als_v2 ($0.0428 an hour).
- **We now own the operating system.** Ubuntu's unattended security upgrades are on by default;
  PHP, Node and Python come from `ondrej/php`, NodeSource and `deadsnakes`, which update with
  the rest of the system.
- **No backups and one instance.** Acceptable for a demonstration, not for real users; recorded
  in `docs/deployment-azure.md`.
- **No cold start.** The VM runs continuously and the model stays resident, which removes the
  warm-up routine Railway would have needed before the presentation.
- The Railway guide stays in the repository, since the only thing wrong with it was payment.
