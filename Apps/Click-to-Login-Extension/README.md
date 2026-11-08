# EUM Click-to-Login extension

This Chrome/Edge Manifest V3 extension connects the internal portal's Apps menu to encrypted app credentials. It does not save credentials in persistent browser storage or display them in the extension page.

## Install for testing

1. In Chrome or Edge, open the browser's Extensions page and enable Developer mode.
2. Choose **Load unpacked** and select this folder.
3. Sign in to `https://internal.jas.enterprises/` in the same browser.
4. Open the Apps menu and choose an assigned Click-to-Login app.

The extension requests access to all HTTPS sites once when installed or updated. Chrome will show a broad site-access warning; users must approve it for automatic login to work without repeated per-site prompts. App logins can be configured with CSS selectors for their username and password fields; common email/password fields are detected as fallbacks. Email-first login pages advance to the password step automatically. Auto-submit controls the final sign-in click.

## Provisioning

Managers can provision shared logins for multiple managed users at once. Each selected user receives a separate approval request routed to the requesting manager's manager. HR and IT assignments take effect immediately. An app can have multiple labeled logins assigned to one person; when more than one is provisioned, the Apps menu opens a submenu so the user can choose the account. For user-based apps, bulk provisioning accepts up to 200 tab-separated login rows: internal login or email, login label, external app username, and password. A user can appear on multiple rows if each login label is unique for that app. The older three-column format remains accepted and receives generated labels. Bulk operations require a reason.

## Security notes

- Passwords are encrypted on the WordPress server and fetched only for the signed-in user after a same-origin, nonce-protected request.
- The extension keeps a launch credential only in session memory until it fills the configured form, then clears it.
- Although Chrome grants HTTPS site access broadly, the extension opens only the configured login URL and fills only the tab whose origin matches that URL.
- The destination login page necessarily receives the password in its password field. A trusted destination can read credentials that are entered into its own login form. Do not assign an app unless its domain and login page are trusted.
- App forms inside cross-origin iframes and sites with nonstandard login flows may require app-specific extension support.
