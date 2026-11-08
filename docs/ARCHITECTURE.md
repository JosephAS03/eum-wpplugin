# Enterprise User Management Architecture

## Overview

This plugin is a large operational platform that mixes workforce management, profile policy, scheduling, timeclock, finance, hiring, dashboard UX, integrations, and artist/release workflows.

The current structure is centered around:

- `enterprise-user-management.php`
  - plugin bootstrap and constants
- `includes/class-eum-plugin.php`
  - main plugin initializer
- `includes/class-eum-module-registry.php`
  - module include list
  - runtime boot list
  - admin boot list
  - activation hook map

The goal of the registry layer is to keep future refactors out of the bootstrap file. New modules should usually be added to the registry instead of being hard-wired directly into `class-eum-plugin.php`.

## Boot Flow

1. WordPress loads `enterprise-user-management.php`.
2. The bootstrap loads `includes/class-eum-plugin.php`.
3. `class-eum-plugin.php` loads `class-eum-module-registry.php`.
4. The registry provides:
   - files to `require_once`
   - runtime classes to instantiate and call `init()`
   - admin-only classes to instantiate and call `init()`
   - activation methods to run on plugin activation

## Structural Layers

### Core platform

Primary files:

- `includes/class-eum-access.php`
- `includes/class-eum-capabilities.php`
- `includes/class-eum-taxonomies.php`
- `includes/class-eum-fields.php`
- `includes/class-eum-personnel.php`
- `includes/class-eum-shortcodes.php`

These files define the shared access model, user/taxonomy data, and reusable platform helpers.

### Workforce and profile policy

Primary files:

- `includes/class-eum-profiles.php`
- `includes/class-eum-workforce-lifecycle.php`
- `includes/class-eum-manager-ids.php`
- `includes/class-eum-job-titles.php`
- `includes/class-eum-job-tasks.php`
- `includes/class-eum-company-divisions.php`
- `includes/class-eum-term-manager.php`
- `includes/class-eum-fields-admin.php`
- `includes/class-eum-bulk.php`
- `includes/class-eum-visual-bulk.php`

Important rule:

- Manager routing is no longer just job-path based.
- `EUM_Manager_IDs` supports path-aware inheritance and optional path+title lanes.
- Interim manager users are treated as active slot occupants by downstream manager-resolution logic.
- Employee IDs are lifecycle-owned, assigned on user registration/hire, usable in profile URLs and global lookup, and intentionally locked from normal profile/personnel/bulk editors.
- Leave, vacation, availability, out-of-office labels, employment duration, and terminated-user portal behavior live in `EUM_Workforce_Lifecycle`.

### Scheduling and labor

Primary files:

- `includes/class-eum-scheduling.php`
- `includes/class-eum-timeclock.php`
- `includes/class-eum-expected-labor.php`
- `includes/class-eum-performance-metrics.php`

Key behavior:

- Scheduling supports segment-level job path, task, and cost center billing.
- Scheduling displays approved leave/vacation out-of-office chips and warns when scheduled work lands outside the user's approved availability.
- The Discord bot is now the primary automation trigger for schedule template catch-up, Monday next-week template application, and Thursday next-week posting, with the in-plugin cron left as a safety fallback.
- Availability supports multiple segments, manager-routed requests, manager team updates, week-specific overrides, and default availability rules with an as-of date.
- Timecards support manager editing, proxy clock actions, and unmatched punch events in edit mode.
- Timeclock and scheduling now both expose `labor_rows_for_range(...)` rollups for reuse.
- Expected Labor rules can project weekly hours from performance metrics plus manual additions.
- Performance Metrics can be manual, API-backed, or hook-computed and are assigned to job paths.
- Timeclock labor rollups feed finance dashboards and the expected-labor model.

### Finance

Primary file:

- `includes/class-eum-finance.php`

Key behavior:

- Finance paths act as cost centers / paycodes.
- Budgets are stored on both finance paths and job paths.
- Finance dashboards combine approved finance records, timeclock labor, and scheduled labor projections.
- Finance now also hosts the admin entry points for Performance Metrics and Expected Labor.

### Experience / front-end configuration

Primary files:

- `includes/class-eum-home-dashboard.php`
- `includes/class-eum-dynamic-links.php`
- `includes/class-eum-fonts.php`
- `includes/class-eum-dtc.php`

Key behavior:

- homepage panels are configurable
- visibility can be limited by role, group, and job path
- dynamic links support dashboard headers, header order, and item order
- DTC Video Hub manages versioned channel/video workspaces, connector publishing, metadata snapshots, role signup events, and DTC access groups

### Hiring and job boards

Primary file:

- `includes/class-eum-job-boards.php`

Key behavior:

- candidate records are retained historically
- default active views hide completed outcomes
- application history also logs email-signup/profile-match events

### Integrations

Primary files:

- `includes/class-eum-email-notifications.php`
- `includes/class-eum-notification-center.php`
- `includes/class-eum-service-bridge.php`
- `includes/class-eum-nextcloud-bridge.php`
- `includes/class-eum-oidc-provider.php`
- `includes/class-eum-site-manager.php`

Key behavior:

- password reset routing prefers personal email
- portal notifications now fan out into on-site read/unread inbox items, email delivery, and Discord delivery through the bridge hooks
- the Discord bridge exposes bot-driven automation, leave, availability, profile, attention, and timeclock endpoints
- a configurable Discord activity-log channel can receive portal and bot action summaries
- service and identity bridges remain isolated from the workforce/finance logic
- Site Manager filters regular managers’ selections through manager, job-path, and group rules; HR/IT can manually target any internal user by group, employee ID, or WordPress user ID, including terminated users, whose connected accounts remain inactive
- Connected-site sign-in uses the internal OIDC provider; remote accounts are linked by issuer and stable subject, not email, with a stable user-ID subject fallback when the configured email subject is unavailable
- connected-site sign-in uses the internal OIDC provider; remote accounts are linked by issuer and stable subject, not email

### Artist and release management

Primary file:

- `includes/class-eum-artist-releases.php`

Key behavior:

- artist workspace, release workspace, and track management live in one module
- reps are rule-driven by job path + title
- members can also resolve as reps
- release and track management supports tabbed UI, promo strategy, files, covers, audio, and project files
- release budgets can be assigned to cost centers
- artist/release money lines can sync into finance records and now carry cost center context

## Shared Data Flows

### Manager resolution

Main source:

- `EUM_Manager_IDs`

Used by:

- profiles
- finance visibility
- scheduling visibility
- timeclock edit access
- artist-rep/manager fallback logic

### Labor billing

Main sources:

- `EUM_Scheduling`
- `EUM_Timeclock`
- `EUM_Expected_Labor`
- `EUM_Performance_Metrics`

Consumes:

- job path
- job task
- optional finance-path cost center

Feeds:

- finance dashboard labor summaries
- expected-labor weekly projections and variance tables

### Artist/finance sync

Main sources:

- `EUM_Artist_Releases::render_money_panel()`
- `EUM_Artist_Releases::handle_submit_expense()`
- `EUM_Artist_Releases::sync_money_line_to_finance_suite()`

Behavior:

- release/artist money lines store local A&R context
- approved lines create finance records
- finance records inherit artist job path for rollup visibility
- finance path metadata is used when a cost center is chosen

### DTC content lifecycle

Main source:

- `EUM_DTC`

Uses:

- job path and group inheritance from platform taxonomies
- company division labels
- finance paths and job paths for budget ownership/billing
- Events for role signup staffing

Behavior:

- channels store budget, billing path, owning division, connector config, and access rules
- video projects store lifecycle status, research/script/metadata, linked event staffing, and versioned assets
- metadata snapshots are stored in a dedicated table instead of overwriting prior values
- DTC access synchronizes into the global `DTC User` group and per-channel DTC groups
- the connector interface isolates platform-specific publish logic so new sites can be added without rewriting the workspace layer

## Admin Structure

The plugin no longer hangs everything off a single top-level menu.

The top-level admin workspaces are now defined in:

- `includes/class-eum-admin.php`

Sections:

- Enterprise
- Workforce
- Experience
- Finance
- Integrations

See `docs/ADMIN-MENU-MAP.md` for the actual screen map.

## Direct Messages and Discord Recovery

- Portal direct and group messages are stored in private thread and message post types. Reusable recipient groups belong to their creator and may overlap.
- Managers can target job paths, user groups, and projects attached to their manager chain, including inherited subordinate scopes. HR and IT can target all such scopes.
- Announcements are read-only for recipients; the sender can add follow-up posts. Managers can inspect any direct reports' full inbox, including indirect reports; HR and IT can inspect any inbox.
- New message notifications link to the generated Messages page, contribute to the top-bar notification count, email the recipient's company address, and use the Discord notification bridge.
- Discord `/restore-member` uses the linked user's stored OAuth grant with the `guilds.join` scope to add the user directly to the configured primary server. Routine role sync checks configured guilds and restores active linked accounts missing from those servers. Terminated users are not re-added automatically.
- Portal inactivity reminders run after five days and repeat every five days while inactive. Discord DMs and personal-email reminders have separate delivery timestamps.

## Extension Guidelines

### When adding a new module

1. Create the module class in `includes/`.
2. Add the file to `EUM_Module_Registry::files()`.
3. Add the class to `runtime_classes()` or `admin_classes()` if it should auto-init.
4. Add activation methods to `activation_map()` only when the module truly needs activation-time registration.

### When adding an admin page

Do not default to parent slug `eum`.

Use:

- `EUM_Admin::parent_slug('core')`
- `EUM_Admin::parent_slug('workforce')`
- `EUM_Admin::parent_slug('experience')`
- `EUM_Admin::parent_slug('finance')`
- `EUM_Admin::parent_slug('integrations')`

### When changing manager logic

Touch all of these carefully:

- `class-eum-manager-ids.php`
- `class-eum-profiles.php`
- `class-eum-timeclock.php`
- `class-eum-scheduling.php`
- `class-eum-finance.php`
- `class-eum-artist-releases.php`

Manager logic is reused broadly and small changes can affect visibility, approvals, and ownership flows.

### When changing scheduling/timeclock

Check both:

- scheduling UI/editor behavior
- finance labor rollups

Those systems are coupled through labor row generation and cost center billing metadata.
