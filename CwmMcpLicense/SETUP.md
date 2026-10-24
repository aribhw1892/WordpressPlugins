# Set up SolidWorks MCP licensing

The WordPress plugin on `www.cloudcadai.com` is the only license check the SolidWorks MCP host calls. Stripe holds the customer, the trial, the card, and the invoices. The host never sees the Stripe secret.

The public product site `www.dakshcwm.com` is not part of this check.

Ship a host that contains the license gate before this has any effect. Publishing that installer is in [CWM.SolidWorks.Mcp.Host.Setup/RELEASE.md](../../CWMServer/CWM.SolidWorks.Mcp.Host.Setup/RELEASE.md). Installers people already have keep running with no license check.

## Stripe

Use test mode until the checks below pass. A test-mode trial stays in test mode. Switching the plugin to the live secret and a live price lets the same PC start its live trial.

### Price

1. In the Stripe Dashboard open **Product catalog** and add a product for SolidWorks MCP.
2. Add a **recurring** price (monthly or yearly). Copy the price id. It starts with `price_`.
3. Leave the price trial blank. Trial length lives only in the plugin setting **Trial days**. A trial saved on the price would start again the next time someone pays.

Use a test-mode price with `sk_test_` or `rk_test_`. Use a live-mode price with `sk_live_` or `rk_live_`. A test price and a live secret reject each other.

### Secret key

1. Open [API keys](https://dashboard.stripe.com/apikeys).
2. Copy the secret key (`sk_test_...` or, when you go live, `sk_live_...`).

A restricted key (`rk_test_` or `rk_live_`) is accepted when it can create customers, subscriptions, Checkout sessions, and Billing Portal sessions, and can search customers and subscriptions. The secret stays in WordPress. Do not put it in the host, in `appsettings.json`, or in the public `solidworks-mcp` repository.

### Customer Portal

The host opens the Customer Portal when someone adds a card during the trial, and when a subscription already exists.

1. Open [Customer portal settings](https://dashboard.stripe.com/settings/billing/portal).
2. Activate the portal.
3. Allow customers to update their payment method.
4. Save. The return address is the WordPress home page. The plugin sends that when it creates the portal session.

If the portal is off, Stripe rejects the billing-page request and the host tells the user it could not open the payment page.

## WordPress

The site is `https://www.cloudcadai.com`. WordPress 5.8 or newer and PHP 7.4 or newer. The server must be able to call `https://api.stripe.com` (the plugin uses `wp_remote_request`, 20 second timeout).

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
2. Activation creates the table `wp_cwm_swx_mcp_usage` (the `wp_` prefix follows the site) and a daily job that deletes usage rows older than 90 days.

To replace a plugin that is already installed, upload again or replace `wp-content/plugins/CwmMcpLicense/` and keep the plugin active. **Deactivate** keeps the Stripe settings and the usage table. **Delete** runs `uninstall.php`, which removes the option `cwm_mcp_license_settings`, drops the usage table, and clears the prune job.

Leave the routes public. A security plugin that requires a logged-in user for `/wp-json/` blocks the host.

### Plugin settings

**Settings → SolidWorks MCP** (an administrator account):

| Field | What to enter |
|---|---|
| Stripe secret key | The secret from the matching Stripe mode. After one is saved, leave the box blank to keep it. |
| Subscription price id | The `price_...` id. The page rejects any other prefix. |
| Trial days | 1–365. The default is 30. This is the only trial length. |

Save. A bad secret prefix or a price id that does not start with `price_` is rejected and the previous value stays.

**Settings → SolidWorks MCP usage** should open. An empty table is expected until a trialing or active install sends events.

## Check the license service

Use a made-up GUID in test mode. A `POST` to `/trial` creates a real Stripe customer and subscription for that id. Repeat the same id and Stripe returns the subscription it already created. It does not start a second trial.

In PowerShell, against the site:

```powershell
$guid = "11111111-1111-1111-1111-111111111111"
Invoke-RestMethod "https://www.cloudcadai.com/wp-json/cwm-mcp/v1/status?install_id=$guid"
```

Before any trial, the body is:

```json
{"status":"none","trial_end":null}
```

Start the trial:

```powershell
Invoke-RestMethod "https://www.cloudcadai.com/wp-json/cwm-mcp/v1/trial" -Method Post -ContentType "application/json" -Body (@{ install_id = $guid } | ConvertTo-Json)
```

A healthy body looks like:

```json
{"status":"trialing","trial_end":"2026-11-23T00:00:00+00:00"}
```

`trial_end` is the UTC end of the plugin’s trial days. In Stripe, that customer has metadata `install_id` set to the GUID, the subscription is `trialing`, there is no card, and the trial end behavior for a missing payment method is **cancel**.

Call `/trial` a second time with the same GUID. The status stays `trialing` and Stripe still has one subscription for that id.

Then:

```powershell
Invoke-RestMethod "https://www.cloudcadai.com/wp-json/cwm-mcp/v1/portal" -Method Post -ContentType "application/json" -Body (@{ install_id = $guid } | ConvertTo-Json)
```

The body is `{"url":"https://billing.stripe.com/..."}`. Open it and confirm the portal loads. During a trial, `POST /checkout` also returns a portal URL, because a second subscription is not created while the status is `trialing`, `active`, or `past_due`.

`install_id` must be a GUID. Anything else returns HTTP 400. A missing secret or price returns HTTP 503. Stripe rejecting the call returns HTTP 502.

After the test, delete that customer in the Stripe test dashboard if you do not want it left behind.

### What each route does

| Route | When the host calls it | Result |
|---|---|---|
| `GET /status?install_id=` | Every startup | `status` and `trial_end`. `none` means this machine has no subscription. |
| `POST /trial` with `{"install_id":"..."}` | Startup when status is `none` | Creates a no-card trial, or returns the subscription that already exists. |
| `POST /checkout` | The user chooses **Yes** after the trial has ended | Checkout page that collects a card. A later payment does not send trial days, so the Price must have no trial of its own. An existing trialing, active, or past-due subscription returns the portal instead. |
| `POST /portal` | The user chooses **No** on the payment dialog, or **Yes** on the “add a card” reminder | Customer Portal for that machine. |
| `POST /events` | While the tools are running | Daily usage totals. A machine that is not `trialing` or `active` gets `{"accepted":0}` and nothing is stored. |

Checkout success and cancel land on the WordPress home page with `?cwm_swx_mcp=success` or `?cwm_swx_mcp=cancel`. Those addresses only bring the person back to the site. The host does not watch the browser.

Status names the host understands:

| Stripe status | Value the plugin returns | Host |
|---|---|---|
| `trialing` | `trialing` | Tools start |
| `active` | `active` | Tools start |
| `past_due`, `unpaid`, `incomplete` | `past_due` | Payment dialog |
| `canceled`, `incomplete_expired`, `paused`, anything else | `canceled` | Payment dialog |
| no subscription | `none` | Host calls `/trial` |

When the trial ends with no card, Stripe cancels the subscription. The next startup gets `canceled` and opens the payment dialog.

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

Deleting `license.dat`, or signing in as another Windows user on the same PC, does not start another trial. Stripe already has that machine id. A new PC, a new virtual machine, a Windows reinstall, or an administrator edit of `MachineGuid` looks like a new machine and can start a trial.

### Near the end of the trial, and after it

In the last 3 days, while status is still `trialing`, a Yes/No dialog offers to add a card. **Yes** opens the portal. **No** continues. Either way the tools start.

After the trial, or when status is `past_due` or `canceled`:

- **Yes** opens Checkout (card required, no second trial).
- **No** opens the billing page.
- **Cancel** exits.

The host does not see the card. After the person finishes on Stripe’s page, they reload the MCP client so startup calls `/status` again. While a dialog is open, stdio has not started, so the client can show the server as failed until the dialog is answered and the client is reloaded.

If cloudcadai.com cannot be reached:

- A paid `active` subscription keeps working for 72 hours after the last successful check.
- A `trialing` subscription keeps working only until the stored trial end, and only inside those 72 hours.
- A check time in the future, or no earlier success, asks for payment. There is no offline grace on the first run.

### Usage

The host batches tool results and posts them to `/events` about every 20 results and again when the process exits. Rows show up under **Settings → SolidWorks MCP usage** only while that machine is `trialing` or `active`. The page summarizes the last 30 days. The daily job drops rows older than 90 days.

The payload is tool name, outcome, duration, exception type name, API member name for `invoke` and `describe_api`, client name, host version, Windows version, and SolidWorks year. It does not include prompts, tool arguments, file paths, document names, or model data. The field list for customers is in [solidworks-mcp/PRIVACY.md](../../solidworks-mcp/PRIVACY.md).

## Go-live list

1. Stripe live price has no trial. Customer Portal is on and payment-method updates are allowed.
2. Plugin on cloudcadai.com is version 1.0.0 or newer (`cwm-mcp-license.php`), active, with the live secret, the live `price_` id, and the trial days you want.
3. Permalinks are not Plain. `GET /wp-json/cwm-mcp/v1/status?install_id=` with a GUID returns JSON.
4. A test-mode `/trial` returned `trialing` and a second call did not create another subscription. For live, do this once with a GUID you can delete, or with your own PC, and confirm the Stripe subscription has no card and cancels when the payment method is missing.
5. `POST /portal` for that id returns an `https://` URL and the page opens.
6. A new host installer is published with [RELEASE.md](../../CWMServer/CWM.SolidWorks.Mcp.Host.Setup/RELEASE.md). Customer machines do not have `CWM_SOLIDWORKS_MCP_LICENSE_URL` set.
7. On a PC with that installer, the first MCP start shows no payment page, Stripe shows one `trialing` subscription for that PC’s `MachineGuid`, and **SolidWorks MCP usage** gains rows after tools run.
8. Reloading the MCP client after adding a card or paying shows the tools starting with no payment dialog.
