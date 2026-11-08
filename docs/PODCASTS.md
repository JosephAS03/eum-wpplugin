# Podcasts Module

## Files

- `includes/class-eum-podcasts.php`
- `includes/class-eum-access.php`
- `includes/class-eum-admin.php`
- `includes/class-eum-home-dashboard.php`

## What It Adds

- Multi-podcast management
- Podcast Manager ownership
- Podcast Member role resolution
- Episode management with audio uploads, video uploads, and pre-production resources
- Podcast-level and episode-level source links for external references, original pages, or companion resources
- Public RSS feed generation per podcast, with separate audio and video feed URLs
- Assignable finance path on each podcast
- Podcast expense logging with optional billable job path assignment
- Episode import/export support through single-episode XML, multi-episode CSV, full podcast XML packages, and RSS feed imports

## Access Model

- `IT` and `HR` always resolve as `Podcast Manager`.
- Explicit podcast managers are stored on each podcast record.
- Managers above an explicit podcast manager inherit access to that podcast through the manager chain.
- Explicit podcast members resolve as `Podcast Member`.
- A user can be assigned as both a `Podcast Manager` and a `Podcast Member` on the same show.
- `Podcast Member` is not manually assigned in user access flags. It is derived from podcast membership.
- Existing podcast managers can create new podcasts. This is the "another Podcast Manager" inheritance path used in the current implementation.

## Post Types

- `eum_podcast`
- `eum_podcast_episode`

## Public Feed

- Feed URL pattern: `/?eum_podcast_feed={podcast_id}&variant=audio`
- Video feed URL pattern: `/?eum_podcast_feed={podcast_id}&variant=video`
- The feed is allowed through the normal site login gate by the module's `eum_allow_public_request` filter.
- Only episodes marked `published` with matching media for that variant and a current-or-past publish time are included.

## Main Meta Keys

Podcast meta:

- `_eum_podcast_manager_user_ids`
- `_eum_podcast_member_user_ids`
- `_eum_podcast_finance_path_term_id`
- `_eum_podcast_cover_attachment_id`
- `_eum_podcast_expense_lines`
- `_eum_podcast_source_links`

Episode meta:

- `_eum_podcast_episode_podcast_id`
- `_eum_podcast_episode_audio_attachment_id`
- `_eum_podcast_episode_video_attachment_id`
- `_eum_podcast_episode_status`
- `_eum_podcast_episode_publish_at`
- `_eum_podcast_episode_guid`
- `_eum_podcast_episode_source_links`
- `_eum_podcast_episode_prepro_resources`

## Admin Surface

- Admin menu: `Experience -> Podcasts`
- Front-end shortcodes:
  - `[eum_podcasts_portal]`
  - `[eum_podcast_workspace]`

## Import / Export

- Individual episodes can be exported as XML from each episode card.
- Podcast managers can import a single episode XML file into a show.
- Podcast managers can import multiple episodes at once through CSV.
- Entire podcast packages can be exported and re-imported through podcast XML.
- Podcast managers can import external RSS feeds from the Feed tab by URL or by uploaded RSS/XML file.
- RSS imports skip episodes with matching GUIDs and can optionally update the existing podcast details from the feed channel.
- Podcast managers can import a ZIP containing one RSS feed XML, per-episode XML files, and matching MP3s. Match audio by the feed enclosure/GUID filename or by using the same basename for each episode XML and MP3. The feed updates show title, category, description, and author; episode XML supplies episode details; MP3s are copied into the site's media library and become the episode audio.
- ZIP imports update existing episodes with matching feed GUIDs, so the local audio files replace older enclosure URLs instead of creating duplicate episodes. Imported episode status defaults to draft.
- Each podcast has a server-side source folder at `{WordPress uploads}/eum-podcast-imports/{podcast_id}`. The workspace displays its full filesystem path. Upload extracted source files there for a one-episode-per-request import, avoiding a large browser upload. Episode XML plus matching MP3 files can be imported without an RSS feed; `feed.xml` is optional and supplies show details and episode publish dates when included. Podcast Generator episode XML does not include a publish date itself.
- Saving podcast details, episodes, team changes, or expenses writes `feed.xml`, `podcast.xml`, and per-episode `episode-{id}.xml` files to that folder. Uploaded episode MP3s and cover art are copied there as source files. `podcast.xml` includes settings, team, expenses, and episode metadata.
- To edit from files, update the XML or replace an episode MP3 in the podcast's folder, then choose **Import Folder Contents** in the Feed tab. Matching episodes are updated by GUID, changed MP3s replace the episode's media attachment, and the generated XML/feed files are refreshed after import. Source files stay in the folder.
- To update existing episode publish times from `feed.xml` without changing audio, choose **Update Publish Times from Feed (Preserve Audio)**. It matches existing episodes by GUID or enclosure filename, updates only publish times, and refreshes XML/feed metadata without copying or replacing media files.

## Source Links

- Podcast settings include a `Podcast Source Links` field.
- Episode create/edit forms include an `Episode Source Links` field.
- Enter one source per line as either `https://example.com/source` or `Label | https://example.com/source`.
- Source links are normalized, de-duplicated by URL, displayed in the workspace, and preserved in XML/CSV import/export payloads.
