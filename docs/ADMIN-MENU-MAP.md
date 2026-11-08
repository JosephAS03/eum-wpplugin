# Enterprise User Management Admin Menu Map

## Top-Level Sections

Defined in:

- `includes/class-eum-admin.php`

Top-level slugs:

- `eum`
- `eum-workforce`
- `eum-experience`
- `eum-finance-hub`
- `eum-integrations`

## Enterprise

Purpose:

- platform overview
- health/status
- shared operational shortcuts

Primary screens:

- `Status`
- `Change Work Queue`
- `Login As User`

## Workforce

Purpose:

- people structure
- profile policy
- manager routing
- job architecture
- taxonomy administration

Primary screens:

- `Profile Access`
- `Profile Changes`
- `Manager IDs`
- `Enterprise Terms`
- `Job Titles`
- `Job Tasks`
- `Job Boards`
- `Company Divisions`
- `Custom Fields`
- `Bulk Import / Export`

Notes:

- `Manager IDs` is where path/title-aware manager routing is configured.
- job path budgets are edited on job path term screens, even though the Finance section owns the budget logic.

## Experience

Purpose:

- front-end homepage and dashboard UX
- fonts
- dashboard links

Primary screens:

- `Homepage Dashboard`
- `Dynamic Links`
- `Fonts`

Notes:

- Dynamic Links ordering for homepage panels is driven by dashboard header order, then header name, then item order.

## Finance

Purpose:

- cost centers
- budgets
- finance records
- recurring items
- labor and cost dashboards

Primary screens:

- `Finance Suite`
- `Performance Metrics`
- `Expected Labor`
- `Finance Receipt Queue`
- `Finance Paths`

Notes:

- finance paths act as cost centers / paycodes
- finance path budgets are stored on the finance-path terms
- job path budgets are stored on the `eum_job_path` terms
- managers can also reach `Performance Metrics` and `Expected Labor` from Dashboard pages even when they do not have the Finance admin hub

## Integrations

Purpose:

- email routing
- service bridges
- cloud integrations
- SSO

Primary screens:

- `Email Notifications`
- `Mail & Services`
- `Nextcloud`
- `SSO / OIDC`
- `Site Manager`

## Menu Placement Rule

When adding a new admin screen, choose the parent by domain:

- enterprise-wide platform and diagnostics -> `core`
- HR/workforce/profile/manager hierarchy -> `workforce`
- homepage, dashboard, links, presentation -> `experience`
- budgets, receipts, finance records, paycodes -> `finance`
- email, cloud, SSO, bridges -> `integrations`

Use `EUM_Admin::parent_slug(<section>)` instead of hardcoding parent menu slugs.

## Related Files

- `includes/class-eum-admin.php`
- `includes/class-eum-plugin.php`
- `includes/class-eum-module-registry.php`
