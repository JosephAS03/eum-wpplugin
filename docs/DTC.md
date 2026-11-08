# DTC Video Hub

## Overview

The DTC Video Hub centralizes the internal Direct-to-Consumer video workflow inside the portal.

It currently covers:

- DTC channel management
- video project lifecycle tracking
- job-path, group, and user-based access control
- tab-level editing rules inside each project
- metadata snapshot history with restore
- versioned file assets with restore/download access
- event-linked role signup for project staffing
- connector-based publishing adapters
- DTC access group synchronization

Primary implementation file:

- `includes/class-eum-dtc.php`

## Main Objects

### Channels

Stored as CPT:

- `eum_dtc_channel`

Important channel metadata:

- `_eum_dtc_channel_status`
- `_eum_dtc_channel_description`
- `_eum_dtc_channel_budget`
- `_eum_dtc_channel_finance_path_term_id`
- `_eum_dtc_channel_billing_job_path_term_id`
- `_eum_dtc_channel_division`
- `_eum_dtc_channel_connector_key`
- `_eum_dtc_channel_connector_config`
- `_eum_dtc_channel_permissions`
- `_eum_dtc_channel_group_term_id`
- `_eum_dtc_channel_external_id`
- `_eum_dtc_channel_activity`

### Videos

Stored as CPT:

- `eum_dtc_video`

Important video metadata:

- `_eum_dtc_video_channel_id`
- `_eum_dtc_video_status`
- `_eum_dtc_video_concept`
- `_eum_dtc_video_research`
- `_eum_dtc_video_script`
- `_eum_dtc_video_description`
- `_eum_dtc_video_tags`
- `_eum_dtc_video_placeholder_id`
- `_eum_dtc_video_permissions`
- `_eum_dtc_video_tab_rules`
- `_eum_dtc_video_assets`
- `_eum_dtc_video_event_id`
- `_eum_dtc_video_event_roles`
- `_eum_dtc_video_last_push_at`
- `_eum_dtc_video_last_push_result`
- `_eum_dtc_video_activity`

### Snapshot Table

Metadata history is stored in:

- `{wp_prefix}eum_video_snapshots`

Columns:

- `video_id`
- `snapshot_key`
- `snapshot_value`
- `actor_user_id`
- `note`
- `created_at`

This is intentionally separate from post meta so text revision history remains queryable and does not overwrite current values.

## Status Model

Supported lifecycle statuses:

- `idea`
- `research`
- `scripting`
- `in_production`
- `post_production`
- `uploaded`
- `scheduled`
- `published`
- `canceled`

Deletion rules:

- IT can hard delete channels/videos.
- non-IT delete actions should transition the item to `canceled`.

## Permission Model

Permissions can be granted at both channel and video scope.

Supported permission levels:

- `viewer`
- `researcher`
- `scriptwriter`
- `asset_manager`
- `editor`
- `creator`
- `manager`

Supported subject types:

- `user`
- `group`
- `job_path`

Supported inheritance modes:

- `live`
- `snapshot`

Current behavior:

- channel permissions provide inherited workspace access
- video permissions can extend access for a single project
- tab rules control which permission roles can edit each project section
- IT and HR retain broad visibility overrides

## DTC Access Groups

The module maintains portal groups in `eum_user_group`:

- global group: `DTC User`
- per-channel group: `DTC User + {Channel Name}`

Group sync is recalculated when:

- DTC permissions change
- project roles change
- user profile data changes
- user/group/job-path term assignments change
- users are registered

The sync preserves non-DTC group assignments and only manages DTC-owned group terms.

## Event Integration

Videos can create or update a linked event through the Events module.

Project roles can define:

- role label
- seat count
- invited job paths
- invited groups
- invited users

Users can sign up for eligible roles from the project workspace. Linked event RSVP data is synchronized from project role signups.

## Connector Architecture

Connectors are defined through:

- `EUM_DTC_Connector_Interface`

Current built-in adapter:

- `EUM_DTC_YouTube_Connector`

Connector responsibilities:

- identify platform key/label
- store/edit connector config
- generate placeholder IDs
- prepare publish payloads

Important note:

- the current YouTube adapter is architecture-ready but does not yet perform a live remote upload. It prepares the publish payload and stores publish results, but server credentials and outbound transport still need to be completed for full platform publishing.

New platforms should be added as new connector classes rather than branching the core DTC module logic.

## Portal Surface

Shortcodes:

- `[eum_dtc_portal]`
- `[eum_dtc_workspace]`

Admin screen:

- `Experience -> DTC Video Hub`

High-level screens:

- directory / my active projects
- channel workspace
- video workspace

## Handoff Notes

If this module is extended further, the main places to review first are:

1. `includes/class-eum-dtc.php`
2. `includes/class-eum-module-registry.php`
3. `includes/class-eum-admin.php`
4. `docs/ARCHITECTURE.md`

Recommended next-phase improvements:

1. Real OAuth token refresh and upload transport for YouTube.
2. Storage abstraction for S3 or external object storage versioning.
3. Bulk dashboard/reporting views for channel budgets and publishing health.
4. Cross-linking DTC costs into the finance module once expense flows are defined.
