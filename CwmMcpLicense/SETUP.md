# Set up SolidWorks MCP licensing

The WordPress plugin on `www.cloudcadai.com` is the only license check the SolidWorks MCP host calls. Dodo Payments holds the customer, the card, and the invoices. The host never sees the API key.

The free trial starts in WordPress, with no Dodo customer and no browser. Dodo's own trial starts when the person adds a card. That checkout sends `subscription_data.trial_period_days` for the days still left. Dodo does not have a `trialing` status. While `next_billing_date` is still ahead and the only payment is a zero amount, or there is no card yet, the plugin reports `trialing`.

The public product site `www.dakshcwm.com` is not part of this check.

Ship a host that contains the license gate before this has any effect. Publishing that installer is in [CWM.SolidWorks.Mcp.Host.Setup/RELEASE.md](../../CWMServer/CWM.SolidWorks.Mcp.Host.Setup/RELEASE.md). Installers people already have keep running with no license check.

## Dodo Payments

Use test mode until the checks below pass. A test-mode trial stays in test mode. Switching the plugin to Live and a live API key lets the same PC start its live trial.

### Product

In the Dodo dashboard create a subscription product for SolidWorks MCP:

1. Pricing type **Subscription**. Tax category **SaaS**. Price at least the minimum for that currency (1.00 USD, or the equivalent).
2. **Repeat payment every** is the billing cadence, monthly or yearly.
3. **Subscription period** is 20 years. If the period equals the payment frequency, Dodo ends the subscription after one charge.
4. Leave **Trial Period (Days)** unchecked. Leave **Trial Amount** empty. Leave **Card-optional at $0 Price** unchecked. The plugin **Trial days** setting is the trial length.
5. Copy the product id. It starts with `pdt_`.

For an Indian card or UPI, turn on **Settings → Business → Adaptive Currency**. The product price can stay in USD. Checkout converts it to INR when the billing country is India. An Indian subscription mandate is INR-only, so a USD checkout will not take an Indian card.

Leave **Prevent trial misuse** off. The plugin passes `trial_period_days` explicitly, and Dodo skips that check when the field is present. The trial here is one per Windows machine, not one per email.

### API key

1. Open **Developer → API Keys** in the same mode as the plugin setting.
2. Copy the API key.

The key stays in WordPress. Do not put it in the host, in `appsettings.json`, or in the public `solidworks-mcp` repository.

### Customer portal

The host opens a Dodo customer portal session when a subscription already has a customer. Until then, the same button opens checkout so the person can add a card. Checkout collects the email and billing country. The host does not have those.

If the trial ends with no card, Dodo moves the subscription to `on_hold`. The plugin reports that as `past_due`. Adding a card in the portal reactivates it.

## WordPress

The site is `https://www.cloudcadai.com`. WordPress 5.8 or newer and PHP 7.4 or newer. The server must be able to call `https://test.dodopayments.com` or `https://live.dodopayments.com` (the plugin uses `wp_remote_request`, 20 second timeout).

### Permalinks

**Settings → Permalinks** must be any structure other than **Plain**, then save. The host calls `https://www.cloudcadai.com/wp-json/cwm-mcp/v1`. Plain permalinks do not serve that path.

### Install the plugin

From `WordpressPlugins/CwmMcpLicense/` run `package-plugin.bat`. It writes:

```text
WordpressPlugins/dist/cwm-mcp-license-<version>.zip
```

The version comes from the `Version:` line in `cwm-mcp-license.php`. The zip root is the folder `CwmMcpLicense`, which is the folder WordPress expects.

On cloudcadai.com:

1. **Plugins → Add New → Upload Plugin**, choose the zip, install, and activate.
2. Activation creates the table `wp_cwm_swx_mcp_usage` (the `wp_` prefix follows the site) and a daily job that deletes usage rows older than 90 days. It also creates `wp_cwm_swx_mcp_installs` for the machine id, the trial end, and the Dodo customer and subscription.

To replace a plugin that is already installed, upload again or replace `wp-content/plugins/CwmMcpLicense/` and keep the plugin active. **Deactivate** keeps the settings and the usage table. **Delete** runs `uninstall.php`, which removes the option `cwm_mcp_license_settings`, drops the usage and install tables, and clears the prune job.

Leave the routes public. A security plugin that requires a logged-in user for `/wp-json/` blocks the host.

### Plugin settings

**Settings → SolidWorks MCP** (an administrator account):

| Field | What to enter |
|---|---|
| Mode | Test until the checks below pass. Live uses the live API host and a live key. |
| Dodo Payments API key | The key for that mode. After one is saved, leave the box blank to keep it. A Stripe `sk_` or `rk_` key is rejected. |
| Product id | The `pdt_...` id. The page rejects any other prefix. |
| Trial days | 1–365. The default is 30. This is the only trial length for a new machine. |

Save. A bad key or a product id that does not start with `pdt_` is rejected and the previous value stays.

**Settings → SolidWorks MCP usage** should open. An empty table is expected until a trialing or active install sends events.

## Check the license service

Use Dodo test mode. No money moves. A real bank card fails in test mode, and a test card fails in live mode. Stay in the Dodo test dashboard, set the plugin **Mode** to **Test**, and use the test API key with the test `pdt_` id.

Use a made-up GUID. Do not use a PC’s `MachineGuid` unless you intend to consume that machine’s trial. SolidWorks does not need to be open.

In PowerShell:

```powershell
$guid = "11111111-1111-1111-1111-111111111111"
$base = "https://www.cloudcadai.com/wp-json/cwm-mcp/v1"
Invoke-RestMethod "$base/status?install_id=$guid"
```

Before any trial, the body is:

```json
{"status":"none","trial_end":null}
```

Start the trial. This writes WordPress only. Dodo has no customer yet.

```powershell
Invoke-RestMethod "$base/trial" -Method Post -ContentType "application/json" -Body (@{ install_id = $guid } | ConvertTo-Json)
```

A healthy body looks like:

```json
{"status":"trialing","trial_end":"2026-11-23T00:00:00+00:00"}
```

`trial_end` is the UTC end of the plugin’s trial days. Call `/trial` again with the same GUID. The status stays `trialing` and `trial_end` does not move.

Open checkout:

```powershell
$pay = Invoke-RestMethod "$base/portal" -Method Post -ContentType "application/json" -Body (@{ install_id = $guid } | ConvertTo-Json)
$pay.url
```

That URL should start with `https://test.checkout.dodopayments.com/`. A `live.checkout.dodopayments.com` host means the plugin is in Live mode. Open it once. It expires in 24 hours. Pay with a test card:

| Buyer | Card | Number | Expiry | CVV |
|---|---|---|---|---|
| India | Visa | `4576238912771450` | `06/32` | `123` |
| Outside India | Visa | `4242424242424242` | `06/32` | `123` |

Any name and email are fine. During the trial the $35 is not charged. Dodo saves the card and the first real charge is at `trial_end`.

For the Indian card, set the checkout billing country to **India**. Adaptive Currency must be on, so the charge is in INR. The US test number is not an Indian card and will not start an INR mandate. UPI in test mode is `success@upi`, and it also requires billing country India and INR.

A decline card is only for a failed-payment test: India Visa `4706131211212123`, or US Visa `4000000000000002`.

After the browser returns to cloudcadai.com, call status again:

```powershell
Invoke-RestMethod "$base/status?install_id=$guid"
```

Status stays `trialing`. In the Dodo test dashboard there is one customer, one subscription, one payment of amount 0, and `metadata.install_id` equals the GUID. The subscription next bill matches `trial_end`.

Call `/portal` again. The URL is now the customer portal, not a new checkout. `POST /checkout` does the same once a customer exists. After the trial, checkout is sent with `trial_period_days` 0.

`install_id` must be a GUID. Anything else returns HTTP 400. A missing key or product id returns HTTP 503. Dodo rejecting the key, the mode, or the product returns HTTP 502.

After the test, delete that customer in the Dodo test dashboard if you do not want it left behind.

### End the trial on an installed PC

Use this when SolidWorks MCP is already installed and you want the payment dialog without waiting for the trial days. The made-up GUID above does not affect an installed PC. The installed host uses that PC’s `MachineGuid`.

On the PC:

```powershell
(Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name MachineGuid).MachineGuid
```

In the cloudcadai.com database, set that id’s trial end to a time in the past. Replace the id below, and replace `wp_` if the site uses another table prefix.

```sql
UPDATE wp_cwm_swx_mcp_installs
SET trial_end_gmt = '2026-10-23 00:00:00'
WHERE install_id = '4945d427-aca6-45f7-a3f3-8399510e8ae0';
```

Confirm the license service:

```powershell
Invoke-RestMethod "https://www.cloudcadai.com/wp-json/cwm-mcp/v1/status?install_id=4945d427-aca6-45f7-a3f3-8399510e8ae0"
```

The body is `"status": "canceled"`. Reload the MCP server. **Yes** opens checkout with no trial days left, so the $35 is due now. Use the same test cards as above. **No** opens checkout as well when this machine has no Dodo customer yet. **Cancel** exits.

This does not start a second trial on that PC. To put the trial back, set `trial_end_gmt` to a future UTC time before any Dodo subscription exists. After a card is saved, Dodo’s `next_billing_date` controls the trial, and changing `trial_end_gmt` does not end it.

### What each route does

| Route | When the host calls it | Result |
|---|---|---|
| `GET /status?install_id=` | Every startup | `status` and `trial_end`. `none` means this machine has no trial and no subscription. |
| `POST /trial` with `{"install_id":"..."}` | Startup when status is `none` | Records a no-card trial, or returns the subscription that already exists. |
| `POST /checkout` | The user chooses **Yes** after the trial has ended | Checkout page that collects a card. Trial days are 0. An existing trialing, active, or past-due subscription that already has a customer returns the portal instead. |
| `POST /portal` | The user chooses **No** on the payment dialog, or **Yes** on the “add a card” reminder | Customer portal when a Dodo customer exists. Checkout for the days still left when it does not. |
| `POST /events` | While the tools are running | Daily usage totals. A machine that is not `trialing` or `active` gets `{"accepted":0}` and nothing is stored. |

Checkout success and cancel land on the WordPress home page with `?cwm_swx_mcp=success` or `?cwm_swx_mcp=cancel`. Those addresses only bring the person back to the site. The host does not watch the browser. The plugin reads `install_id` from the return address and `subscription_id` when Dodo appends it, then confirms `metadata.install_id` on the payment or the subscription.

Status names the host understands:

| Dodo state | Value the plugin returns | Host |
|---|---|---|
| No Dodo subscription, trial end still ahead | `trialing` | Tools start |
| `active`, no card, or one payment of amount 0, next bill still ahead | `trialing` | Tools start |
| `active` after the trial | `active` | Tools start |
| `pending`, `on_hold`, `past_due`, `failed` | `past_due` | Payment dialog |
| `cancelled`, `paused`, `expired`, trial ended with no subscription | `canceled` | Payment dialog |
| no trial and no subscription | `none` | Host calls `/trial` |

When a Dodo trial ends with no card, Dodo sets `on_hold`. The next startup gets `past_due` and opens the payment dialog. A WordPress-only trial that ends with no checkout returns `canceled`.

## MCP host

The installed host always calls `https://www.cloudcadai.com/wp-json/cwm-mcp/v1`. `appsettings.json` cannot change that address.

`CWM_SOLIDWORKS_MCP_LICENSE_URL` is a private test override. Leave it unset on any machine that should use cloudcadai.com. A value there sends the check somewhere else.

### What runs without a subscription

These commands finish before the license check:

- `--register` and `--unregister`
- `--install-skill` and `--uninstall-skill`

Starting the host with no arguments (the way Cursor, Claude, and Codex start it) runs the check before stdio starts.

### First launch

1. The host reads `HKLM\SOFTWARE\Microsoft\Cryptography\MachineGuid` (64-bit view). That GUID is `install_id`. It is the same for every Windows account on the PC.
2. It calls `GET /status`. For a new machine the status is `none`, so it calls `POST /trial`.
3. On `trialing` or `active` the tools start. No browser opens and no card is asked for.
4. It writes `%USERPROFILE%\CWM\SolidWorksMcp\license.dat` (DPAPI, current Windows user). The file stores the machine id, the last successful check, the last status, and the trial end. `CWM_SOLIDWORKS_MCP_HOME` moves that folder. It does not change the machine id.

If Windows has no `MachineGuid`, the host exits. If `/trial` fails, a dialog says the trial could not start, stderr prints `SolidWorks MCP needs an active trial or subscription.`, and the process exits 1. The MCP client then shows the server as failed. Reload it after the network or the plugin is fixed.

Deleting `license.dat`, or signing in as another Windows user on the same PC, does not start another trial. WordPress already has that machine id. A new PC, a new virtual machine, a Windows reinstall, or an administrator edit of `MachineGuid` looks like a new machine and can start a trial.

### Near the end of the trial, and after it

In the last 3 days, while status is still `trialing`, a Yes/No dialog offers to add a card. **Yes** opens the portal, or checkout when no Dodo customer exists yet. **No** continues. Either way the tools start.

To see that dialog before the trial days run out, follow [End the trial on an installed PC](#end-the-trial-on-an-installed-pc).

After the trial, or when status is `past_due` or `canceled`:

- **Yes** opens Checkout (card required, `trial_period_days` 0).
- **No** opens the billing page, or checkout when no customer exists yet.
- **Cancel** exits.

The host does not see the card. After the person finishes on Dodo’s page, they reload the MCP client so startup calls `/status` again. While a dialog is open, stdio has not started, so the client can show the server as failed until the dialog is answered and the client is reloaded.

If cloudcadai.com cannot be reached:

- A paid `active` subscription keeps working for 72 hours after the last successful check.
- A `trialing` subscription keeps working only until the stored trial end, and only inside those 72 hours.
- A check time in the future, or no earlier success, asks for payment. There is no offline grace on the first run.

### Usage

The host batches tool results and posts them to `/events` about every 20 results and again when the process exits. Rows show up under **Settings → SolidWorks MCP usage** only while that machine is `trialing` or `active`. The page summarizes the last 30 days. The daily job drops rows older than 90 days.

The payload is tool name, outcome, duration, exception type name, API member name for `invoke` and `describe_api`, client name, host version, Windows version, and SolidWorks year. It does not include prompts, tool arguments, file paths, document names, or model data. The field list for customers is in [solidworks-mcp/PRIVACY.md](../../solidworks-mcp/PRIVACY.md).

## Go-live list

1. The live product is a subscription with a 20-year period, **Trial Period** unchecked, and **Trial Amount** empty. Adaptive Currency is on if Indian cards must work.
2. Plugin on cloudcadai.com is version 1.1.0 or newer (`cwm-mcp-license.php`), active, mode Live, with the live API key, the live `pdt_` id, and the trial days you want.
3. Permalinks are not Plain. `GET /wp-json/cwm-mcp/v1/status?install_id=` with a GUID returns JSON.
4. A test-mode `/trial` returned `trialing` and a second call did not move `trial_end`. For live, do this once with a GUID you can delete, or with your own PC.
5. `POST /portal` for that id returns an `https://` URL and the page opens.
6. A new host installer is published with [RELEASE.md](../../CWMServer/CWM.SolidWorks.Mcp.Host.Setup/RELEASE.md). Customer machines do not have `CWM_SOLIDWORKS_MCP_LICENSE_URL` set.
7. On a PC with that installer, the first MCP start shows no payment page, WordPress has one trial row for that PC’s `MachineGuid`, and **SolidWorks MCP usage** gains rows after tools run.
8. Reloading the MCP client after adding a card or paying shows the tools starting with no payment dialog. Dodo shows one subscription for that machine, and `metadata.install_id` is the machine id.
