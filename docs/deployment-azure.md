# Deployment guide — Azure

ScamSense on one Azure virtual machine: Laravel, MySQL 8 and the Flask ML
service on the same Ubuntu server, with only the website reachable from the
internet. Why Azure rather than Railway: **ADR 0013**. The Railway guide,
`docs/deployment.md`, stays valid if that route opens again.

---

## Topology

```
                    internet
                       │
                       ▼  443 (HTTPS), 80 (redirects to HTTPS)
   ┌─────────────────────────────────────────────────────┐
   │  Azure VM, Ubuntu 24.04                             │
   │                                                     │
   │  Caddy (TLS) ──► PHP-FPM ──► Laravel                │
   │                                │                    │
   │                127.0.0.1:5001  ├──► Flask ML service │
   │                127.0.0.1:3306  └──► MySQL 8          │
   └─────────────────────────────────────────────────────┘
```

The ML service and MySQL listen on **loopback only**. Nothing outside the VM
can connect to them, whatever the firewall says. That is a stronger form of
AGENTS.md §2 rule 1 than Railway's private network: there is no "Generate
Domain" button that could expose them by accident.

Everything is set up by two scripts in `deploy/azure/`:

| Script | When |
|---|---|
| `setup.sh` | Once, on a fresh VM |
| `update.sh` | After every push you want live, or after editing `.env` |

---

## 1. Create the VM (Azure portal)

**Create a resource → Virtual Machine**

| Setting | Value | Why |
|---|---|---|
| Resource group | new: `scamsense` | Deleting it later removes everything in one step |
| Name | `scamsense-vm` | |
| Region | Southeast Asia (Singapore) | Closest to Malaysia. If the size is unavailable, East Asia |
| Image | Ubuntu Server 24.04 LTS, x64 | The scripts are written for it |
| Size | **B2s** (2 vCPU, 4 GB) | MySQL, PHP and the model with room to spare during the demo |
| Authentication | **SSH public key**, username `azureuser` | No password to guess |
| Key pair | Generate new, name `scamsense-vm` | Download the `.pem` when asked. It is shown **once** |
| Inbound ports | SSH (22), HTTP (80), HTTPS (443) | Nothing else |
| OS disk | Standard SSD, default size | |
| Auto-shutdown (Management tab) | **Off** | Otherwise the site goes down at night |

Save the key as `C:\Users\Acer\.ssh\scamsense-vm.pem`. **Never** put it
inside the ScamSense folder.

**After it is created:**

1. **DNS name.** VM → Overview → *DNS name: Not configured* → set the label to
   `scamsense-demo` (or any free name) → Save. The site's address becomes
   `scamsense-demo.southeastasia.cloudapp.azure.com`. HTTPS needs this name;
   Let's Encrypt does not issue certificates for bare IP addresses.
2. **Limit SSH to your IP.** VM → Networking → the SSH rule → Source:
   *My IP address*. Port 22 open to the whole internet attracts constant
   password-guessing bots. If your home IP changes, update the rule.

## 2. Connect from your PC

Windows' SSH refuses a key file that other accounts can read. Once, in
PowerShell:

```powershell
icacls "$env:USERPROFILE\.ssh\scamsense-vm.pem" /inheritance:r /grant:r "$($env:USERNAME):R"
```

Then:

```powershell
ssh -i "$env:USERPROFILE\.ssh\scamsense-vm.pem" azureuser@scamsense-demo.southeastasia.cloudapp.azure.com
```

## 3. Run the setup

Copy the script up and run it (from `C:\dev\scamsense`):

```powershell
scp -i "$env:USERPROFILE\.ssh\scamsense-vm.pem" deploy/azure/setup.sh azureuser@<address>:
ssh -i "$env:USERPROFILE\.ssh\scamsense-vm.pem" azureuser@<address>
sudo bash setup.sh
```

It asks for the site address and the administrator's email.

**The first run stops halfway, on purpose.** It prints a public key and asks
you to add it to GitHub: repository → Settings → Deploy keys → Add deploy key,
with *Allow write access* **off**. That lets the server read the private
repository and never push to it. Run `sudo bash setup.sh` again and it carries
on from where it stopped.

What it does, in order: 2 GB swap; PHP 8.4, MySQL 8, Python 3.11, Node 22,
Caddy; firewall (22/80/443); database with two scoped users; clone; `.env`
with generated secrets; the ML service as a locked-down system service; HTTPS;
the daily purge; build, migrate, start; the administrator account.

**Secrets are generated on the server and never leave it.** They live in
`/etc/scamsense/secrets.env`, readable by root only. Nothing to paste into
chat, a document or the repository.

Why PHP 8.4 from `ondrej/php`: `composer.lock` pins Symfony 8.1, which requires
PHP 8.4.1 or newer, and Ubuntu 24.04 ships 8.3.

## 4. Set the administrator password

```bash
cd /var/www/scamsense/backend
sudo -u www-data php artisan scamsense:admin-password
```

It reads the password without echoing it and never prints it. The seeder gave
the account a random password nobody saw, so until you run this, nobody can
sign in.

**Always run artisan as `www-data`** (`sudo -u www-data php artisan …`).
Running it as yourself creates log and cache files the web server then
cannot write to.

## 5. Optional: threat-intelligence keys

```bash
sudo nano /var/www/scamsense/backend/.env     # GOOGLE_SAFE_BROWSING_KEY, VIRUSTOTAL_KEY
sudo bash /var/www/scamsense/deploy/azure/update.sh --no-pull
```

VirusTotal's free tier is 4 lookups a minute and 500 a day, shared with any
local copy using the same key. In a demo, more than 4 new links in a minute
gives partial verdicts; repeated links are cached for 24 hours.

## 6. Verify

`update.sh` ends by printing the listening ports and running the doctor.
Check both:

- Ports **5001** and **3306** show `127.0.0.1` only.
- The doctor passes. The URL probe (`https://www.utm.my/about/`) is expected
  to come back as a scam at 0.3179 against a threshold of 0.3105. That is the
  ADR 0006 false-positive limit, not a deployment fault. The doctor proves the
  chain answers, not that this verdict is right.

From your PC:

```powershell
$site = "https://scamsense-demo.southeastasia.cloudapp.azure.com"
curl.exe -sI $site | Select-String "HTTP/|strict-transport|content-security|x-content-type"
curl.exe -s "$site/no-such-page" | Select-String "vendor/laravel"     # must print nothing
curl.exe -s --max-time 5 "http://scamsense-demo.southeastasia.cloudapp.azure.com:5001/health"   # must fail
```

Then the browser checks from `docs/deployment.md` §6: the Maybank address
shows the verified panel, the look-alike shows *Likely a scam*, a Malay scam
message gets a verdict, BM switches the interface, `/admin` signed out
redirects to login.

## 7. Updating

Push to `master`, then on the VM:

```bash
sudo bash /var/www/scamsense/deploy/azure/update.sh
```

## Cost and the credit

- Free account: **$200 credit for 30 days**. B2s costs roughly $1–1.30 a day.
  Check the exact figure under Cost Management; prices change.
- While on the free credit Azure **stops** resources when the credit is used
  up rather than charging the card.
- **The credit expires 30 days after sign-up** (about 30 October 2026). To
  keep the site online after that, upgrade to pay-as-you-go **before** then,
  and set a budget alert under Cost Management → Budgets.
- Stopping the VM from the portal (*Stop*, which deallocates it) stops the
  compute charge; the disk still costs a little. The site is down while it is
  stopped, and it keeps its DNS name.
- Unlike Railway there is no cold start: the VM runs continuously and the model
  stays loaded, so the warm-up routine in `docs/deployment.md` is not needed.

## What this machine does not have

- **Backups.** The database lives on the VM's disk only. For a demo that is
  acceptable; before real users it is not. Azure Backup or a nightly
  `mysqldump` to storage would close it.
- **A second instance.** One VM is a single point of failure. Keep the local
  fallback in `docs/deployment.md` ready for the presentation day.
