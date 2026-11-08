<?php
if (!defined('ABSPATH')) exit;

class EUM_Direct_Messages {
  public const CPT_THREAD = 'eum_dm_thread';
  public const CPT_MESSAGE = 'eum_dm_message';
  public const META_PARTICIPANTS = '_eum_dm_participants';
  public const META_TYPE = '_eum_dm_type';
  public const META_SUBJECT = '_eum_dm_subject';
  public const META_LAST_MESSAGE_AT = '_eum_dm_last_message_at';
  public const META_ADMINS = '_eum_dm_admin_user_ids';
  public const META_EXCLUDED_USERS = '_eum_dm_excluded_user_ids';
  public const META_EXCLUDED_STATUSES = '_eum_dm_excluded_statuses';
  public const META_AVATAR_ID = '_eum_dm_avatar_attachment_id';
  public const USER_META_GROUPS = '_eum_dm_saved_groups';
  public const USER_META_THREAD_STATE = '_eum_dm_thread_state';

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    add_shortcode('eum_direct_messages', [$this, 'shortcode']);
    add_action('admin_post_eum_dm_send', [$this, 'handle_send']);
    add_action('admin_post_eum_dm_reply', [$this, 'handle_reply']);
    add_action('wp_ajax_eum_dm_popup_load', [$this, 'ajax_popup_load']);
    add_action('wp_ajax_eum_dm_popup_thread', [$this, 'ajax_popup_thread']);
    add_action('wp_ajax_eum_dm_popup_contacts', [$this, 'ajax_popup_contacts']);
    add_action('wp_ajax_eum_dm_popup_send', [$this, 'ajax_popup_send']);
    add_action('wp_ajax_eum_dm_popup_reply', [$this, 'ajax_popup_reply']);
    add_action('wp_ajax_eum_dm_popup_manage', [$this, 'ajax_popup_manage']);
    add_action('wp_ajax_eum_dm_popup_avatar', [$this, 'ajax_popup_avatar']);
    add_action('wp_ajax_eum_dm_popup_personal_state', [$this, 'ajax_popup_personal_state']);
    add_action('wp_ajax_eum_dm_popup_member_search', [$this, 'ajax_popup_member_search']);
  }

  public function ajax_popup_load(): void {
    $viewer_id = $this->require_popup_user();
    $subject_user_id = absint($_POST['subject_user_id'] ?? 0);
    if ($subject_user_id > 0 && !$this->can_view_inbox($viewer_id, $subject_user_id)) {
      wp_send_json_error(['message' => 'You do not have permission to view that inbox.'], 403);
    }
    if ($subject_user_id <= 0) $subject_user_id = $viewer_id;
    $include_hidden = !empty($_POST['include_hidden']) && (string)wp_unslash($_POST['include_hidden']) === '1';
    $threads = array_slice($this->threads_for_user($subject_user_id, $include_hidden), 0, 60);
    $rows = [];
    foreach ($threads as $thread) {
      $rows[] = $this->popup_thread_summary((int)$thread->ID, $subject_user_id, $viewer_id);
    }
    $team = [];
    foreach ($this->team_user_ids($viewer_id) as $team_user_id) {
      $user = get_userdata($team_user_id);
      if ($user) $team[] = ['id' => (int)$user->ID, 'name' => (string)($user->display_name ?: $user->user_login)];
    }
    $audience_tree = $this->popup_audience_tree($viewer_id);
    wp_send_json_success([
      'threads' => $rows,
      'subject_user_id' => $subject_user_id === $viewer_id ? 0 : $subject_user_id,
      'team_members' => $team,
      'include_hidden' => $include_hidden,
      'audience_tree' => $audience_tree,
      'can_select_audience' => $this->can_manage_audience($viewer_id),
      'can_announce' => $this->can_manage_audience($viewer_id),
      'unread_count' => class_exists('EUM_Notification_Center')
        ? EUM_Notification_Center::unread_count_for_user($viewer_id, [], ['direct_message'])
        : 0,
    ]);
  }

  public function ajax_popup_thread(): void {
    $viewer_id = $this->require_popup_user();
    $thread_id = absint($_POST['thread_id'] ?? 0);
    $subject_user_id = absint($_POST['subject_user_id'] ?? 0);
    if ($subject_user_id <= 0) $subject_user_id = $viewer_id;
    $include_hidden = !empty($_POST['include_hidden']) && (string)wp_unslash($_POST['include_hidden']) === '1';
    if ($subject_user_id !== $viewer_id && !$this->can_view_inbox($viewer_id, $subject_user_id)) {
      wp_send_json_error(['message' => 'You do not have permission to view that inbox.'], 403);
    }
    $thread = get_post($thread_id);
    if (!$thread instanceof WP_Post || $thread->post_type !== self::CPT_THREAD || !$this->can_read_thread($viewer_id, $subject_user_id, $thread_id)) {
      wp_send_json_error(['message' => 'That conversation is not available.'], 404);
    }
    $personal_state = $this->thread_state_for_user($subject_user_id, $thread_id);
    if (!$include_hidden && (!empty($personal_state['archived']) || !empty($personal_state['deleted']))) {
      wp_send_json_error(['message' => 'That chat is hidden for this inbox.'], 404);
    }
    if ($subject_user_id === $viewer_id) $this->mark_thread_notifications_read($viewer_id, $thread_id);
    $messages = get_posts([
      'post_type' => self::CPT_MESSAGE, 'post_status' => 'publish', 'post_parent' => $thread_id,
      'numberposts' => 250, 'orderby' => 'date', 'order' => 'DESC',
    ]);
    $messages = array_reverse($messages);
    $message_rows = [];
    foreach ($messages as $message) {
      $author_id = (int)$message->post_author;
      $author = get_userdata($author_id);
      $message_rows[] = [
        'id' => (int)$message->ID,
        'sender' => $author ? (string)($author->display_name ?: $author->user_login) : 'Portal user',
        'avatar' => class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_url($author_id) : '',
        'mine' => $author_id === $viewer_id,
        'body' => html_entity_decode(wp_strip_all_tags((string)$message->post_content), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'time' => get_date_from_gmt((string)$message->post_date_gmt, 'M j, g:i A'),
      ];
    }
    $type = (string)get_post_meta($thread_id, self::META_TYPE, true);
    $can_reply = $subject_user_id === $viewer_id && ($type !== 'announcement' || (int)$thread->post_author === $viewer_id);
    $participants = $this->thread_participants($thread_id);
    $admins = $this->thread_admin_ids($thread_id);
    $can_manage = $this->can_manage_thread($viewer_id, $thread_id);
    $avatar_id = (int)get_post_meta($thread_id, self::META_AVATAR_ID, true);
    $avatar_url = $avatar_id > 0 ? (string)wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
    if ($avatar_url === '' && $type === 'direct') {
      $other_ids = array_values(array_diff($participants, [$subject_user_id]));
      if (!empty($other_ids) && class_exists('EUM_Profiles')) $avatar_url = EUM_Profiles::profile_avatar_url((int)$other_ids[0]);
    }
    $members = [];
    foreach ($participants as $participant_id) {
      $member = get_userdata($participant_id);
      if (!$member) continue;
      $members[] = [
        'id' => (int)$member->ID,
        'name' => (string)($member->display_name ?: $member->user_login),
        'avatar' => class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_url((int)$member->ID) : '',
        'is_admin' => in_array((int)$member->ID, $admins, true),
      ];
    }
    wp_send_json_success([
      'thread' => [
        'id' => $thread_id,
        'title' => $this->thread_label($thread_id, $subject_user_id),
        'type' => $type ?: 'direct',
        'avatar' => $avatar_url,
        'is_group' => $type !== 'direct',
        'can_reply' => $can_reply,
        'members' => $members,
        'can_manage' => $can_manage,
        'admins' => $admins,
        'excluded_user_ids' => $can_manage ? $this->thread_excluded_user_ids($thread_id) : [],
        'excluded_users' => $can_manage ? $this->excluded_user_options($this->thread_excluded_user_ids($thread_id)) : [],
        'excluded_statuses' => $can_manage ? $this->thread_excluded_statuses($thread_id) : [],
        'personal_state' => $personal_state,
        'messages' => $message_rows,
      ],
      'subject_user_id' => $subject_user_id === $viewer_id ? 0 : $subject_user_id,
      'unread_count' => class_exists('EUM_Notification_Center')
        ? EUM_Notification_Center::unread_count_for_user($viewer_id, [], ['direct_message'])
        : 0,
    ]);
  }

  public function ajax_popup_contacts(): void {
    $viewer_id = $this->require_popup_user();
    $query = sanitize_text_field(wp_unslash((string)($_POST['q'] ?? '')));
    $needle = function_exists('mb_strtolower') ? mb_strtolower(trim($query)) : strtolower(trim($query));
    $excluded_users = $this->valid_excluded_user_ids((array)wp_unslash($_POST['excluded_user_ids'] ?? []));
    $excluded_statuses = $this->normalize_excluded_statuses((array)wp_unslash($_POST['excluded_statuses'] ?? []));
    $users = get_users(['number' => 2000, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => ['ID', 'display_name', 'user_login']]);
    $matches = [];
    foreach ($users as $user) {
      $id = (int)$user->ID;
      if ($id === $viewer_id) continue;
      if ($this->member_is_excluded($id, $excluded_users, $excluded_statuses)) continue;
      $name = (string)($user->display_name ?: $user->user_login);
      $haystack = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
      if ($needle !== '' && strpos($haystack, $needle) === false) continue;
      $matches[] = ['id' => $id, 'name' => $name];
      if (count($matches) >= 30) break;
    }
    wp_send_json_success(['contacts' => $matches]);
  }

  public function ajax_popup_send(): void {
    $sender_id = $this->require_popup_user();
    $recipient_mode = sanitize_key(wp_unslash((string)($_POST['recipient_mode'] ?? 'user')));
    if (!in_array($recipient_mode, ['user', 'group'], true)) {
      wp_send_json_error(['message' => 'Choose User or Group as the recipient type.'], 400);
    }
    $is_announcement = !empty($_POST['announcement']) && sanitize_key(wp_unslash((string)$_POST['announcement'])) === '1';
    if ($is_announcement && ($recipient_mode !== 'group' || !$this->can_manage_audience($sender_id))) {
      wp_send_json_error(['message' => 'Only managers, HR, or IT can send announcements.'], 403);
    }
    $body_value = wp_unslash($_POST['message'] ?? '');
    if (!is_scalar($body_value)) wp_send_json_error(['message' => 'Enter a message.'], 400);
    $body = wp_kses_post((string)$body_value);
    if (trim(wp_strip_all_tags($body)) === '') wp_send_json_error(['message' => 'Enter a message.'], 400);
    $subject_value = wp_unslash($_POST['subject'] ?? '');
    $subject = is_scalar($subject_value) ? sanitize_text_field((string)$subject_value) : '';
    $recipient_ids = $recipient_mode === 'user'
      ? $this->normalize_ids((array)wp_unslash($_POST['recipient_ids'] ?? []))
      : [];
    $excluded_user_ids = $this->valid_excluded_user_ids((array)wp_unslash($_POST['excluded_user_ids'] ?? []));
    $excluded_statuses = $this->normalize_excluded_statuses((array)wp_unslash($_POST['excluded_statuses'] ?? []));
    if (in_array($sender_id, $excluded_user_ids, true) || $this->member_matches_excluded_status($sender_id, $excluded_statuses)) {
      wp_send_json_error(['message' => 'You cannot exclude yourself from a chat you are starting.'], 400);
    }
    $audience_values = array_values(array_unique(array_filter(array_map(
      static function ($value) { return is_scalar($value) ? sanitize_text_field((string)$value) : ''; },
      (array)wp_unslash($_POST['audience_targets'] ?? [])
    ))));

    if ($recipient_mode === 'group') {
      if (empty($audience_values)) wp_send_json_error(['message' => 'Choose at least one Group or Job Path.'], 400);
      $options = $this->audience_options($sender_id);
      $allowed = [];
      foreach ($options as $option) {
        if (strpos((string)$option['value'], 'group:') === 0 || strpos((string)$option['value'], 'jobpath:') === 0) {
          $allowed[] = (string)$option['value'];
        }
      }
      foreach ($audience_values as $target_value) {
        if (!in_array($target_value, $allowed, true)) wp_send_json_error(['message' => 'You cannot send to that Group or Job Path.'], 403);
        [$taxonomy, $term_id] = array_pad(explode(':', $target_value, 2), 2, '0');
        if ($taxonomy === 'jobpath') {
          $term_ids = [(int)$term_id];
          $children = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'child_of' => (int)$term_id, 'fields' => 'ids', 'number' => 0]);
          if (!is_wp_error($children)) $term_ids = array_merge($term_ids, array_map('intval', (array)$children));
          foreach (array_unique(array_map('intval', $term_ids)) as $path_id) {
            $found = get_objects_in_term($path_id, 'eum_job_path');
            if (!is_wp_error($found)) $recipient_ids = array_merge($recipient_ids, array_map('intval', (array)$found));
          }
        } elseif ($taxonomy === 'group') {
          $found = get_objects_in_term((int)$term_id, 'eum_user_group');
          if (!is_wp_error($found)) $recipient_ids = array_merge($recipient_ids, array_map('intval', (array)$found));
        }
      }
    }
    $recipient_ids = $this->normalize_ids($recipient_ids);
    if ($recipient_mode === 'group') {
      // Taxonomy relationships can outlive deleted accounts; discard those stale IDs
      // so one orphaned membership does not prevent delivery to the rest of the audience.
      $recipient_ids = array_values(array_filter($recipient_ids, static function ($recipient_id) {
        return get_userdata((int)$recipient_id) instanceof WP_User;
      }));
    }
    $recipient_ids = array_values(array_filter($recipient_ids, function ($recipient_id) use ($excluded_user_ids, $excluded_statuses) {
      return !$this->member_is_excluded((int)$recipient_id, $excluded_user_ids, $excluded_statuses);
    }));
    $recipient_ids = array_values(array_diff($recipient_ids, [$sender_id]));
    if (empty($recipient_ids)) wp_send_json_error(['message' => 'No eligible recipients remain after applying this chat’s membership exclusions.'], 400);
    if ($recipient_mode === 'user') foreach ($recipient_ids as $recipient_id) {
      if (!get_userdata($recipient_id)) wp_send_json_error(['message' => 'One or more recipients are unavailable.'], 400);
    }
    $type_value = $is_announcement ? 'announcement' : (($recipient_mode === 'group' || count($recipient_ids) > 1) ? 'group' : 'direct');
    $thread_id = $this->create_thread($sender_id, $recipient_ids, $type_value, $subject, $body, $audience_values, $excluded_user_ids, $excluded_statuses);
    if ($thread_id <= 0) wp_send_json_error(['message' => 'The message could not be saved. Please try again.'], 500);
    wp_send_json_success([
      'thread_id' => $thread_id,
      'unread_count' => class_exists('EUM_Notification_Center')
        ? EUM_Notification_Center::unread_count_for_user($sender_id, [], ['direct_message'])
        : 0,
    ]);
  }

  public function ajax_popup_reply(): void {
    $user_id = $this->require_popup_user();
    $thread_id = absint($_POST['thread_id'] ?? 0);
    $thread = get_post($thread_id);
    if (!$thread instanceof WP_Post || $thread->post_type !== self::CPT_THREAD || !in_array($user_id, $this->thread_participants($thread_id), true)) {
      wp_send_json_error(['message' => 'You cannot reply to that conversation.'], 403);
    }
    $type = (string)get_post_meta($thread_id, self::META_TYPE, true);
    if ($type === 'announcement' && (int)$thread->post_author !== $user_id) {
      wp_send_json_error(['message' => 'Recipients cannot reply to announcements.'], 403);
    }
    $message_value = wp_unslash($_POST['message'] ?? '');
    if (!is_scalar($message_value)) wp_send_json_error(['message' => 'Enter a reply.'], 400);
    $body = wp_kses_post((string)$message_value);
    if (trim(wp_strip_all_tags($body)) === '') wp_send_json_error(['message' => 'Enter a reply.'], 400);
    if ($this->append_message($thread_id, $user_id, $body) <= 0) {
      wp_send_json_error(['message' => 'Your reply could not be saved.'], 500);
    }
    $this->set_thread_state_flag($user_id, $thread_id, 'archived', false);
    $this->set_thread_state_flag($user_id, $thread_id, 'deleted', false);
    wp_send_json_success(['thread_id' => $thread_id]);
  }

  public function ajax_popup_member_search(): void {
    $viewer_id = $this->require_popup_user();
    $thread_id = absint($_POST['thread_id'] ?? 0);
    $thread = get_post($thread_id);
    if (!$thread instanceof WP_Post || $thread->post_type !== self::CPT_THREAD || !$this->can_manage_thread($viewer_id, $thread_id)) {
      wp_send_json_error(['message' => 'You cannot manage members of that chat.'], 403);
    }
    $query = sanitize_text_field(wp_unslash((string)($_POST['q'] ?? '')));
    $needle = function_exists('mb_strtolower') ? mb_strtolower(trim($query)) : strtolower(trim($query));
    $purpose = sanitize_key(wp_unslash((string)($_POST['purpose'] ?? 'add')));
    $purpose = $purpose === 'exclude' ? 'exclude' : 'add';
    $participants = $this->thread_participants($thread_id);
    $excluded_users = $this->thread_excluded_user_ids($thread_id);
    $excluded_statuses = $this->thread_excluded_statuses($thread_id);
    $users = get_users(['number' => 3000, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => ['ID', 'display_name', 'user_login']]);
    $matches = [];
    foreach ($users as $user) {
      $id = (int)$user->ID;
      if ($purpose === 'add' && (in_array($id, $participants, true) || $this->member_is_excluded($id, $excluded_users, $excluded_statuses))) continue;
      $name = (string)($user->display_name ?: $user->user_login);
      $haystack = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
      if ($needle !== '' && strpos($haystack, $needle) === false) continue;
      $matches[] = ['id' => $id, 'name' => $name];
      if (count($matches) >= 30) break;
    }
    wp_send_json_success(['contacts' => $matches]);
  }

  public function ajax_popup_manage(): void {
    $viewer_id = $this->require_popup_user();
    $thread_id = absint($_POST['thread_id'] ?? 0);
    $thread = get_post($thread_id);
    if (!$thread instanceof WP_Post || $thread->post_type !== self::CPT_THREAD || !$this->can_manage_thread($viewer_id, $thread_id)) {
      wp_send_json_error(['message' => 'You cannot manage that chat.'], 403);
    }
    $operation = sanitize_key(wp_unslash((string)($_POST['operation'] ?? '')));
    $participants = $this->thread_participants($thread_id);
    $excluded_users = $this->thread_excluded_user_ids($thread_id);
    $excluded_statuses = $this->thread_excluded_statuses($thread_id);

    if ($operation === 'add_members') {
      $requested = $this->valid_excluded_user_ids((array)wp_unslash($_POST['user_ids'] ?? []));
      $requested = array_values(array_diff($requested, $participants));
      $eligible = array_values(array_filter($requested, function ($user_id) use ($excluded_users, $excluded_statuses) {
        return !$this->member_is_excluded((int)$user_id, $excluded_users, $excluded_statuses);
      }));
      if (empty($eligible)) wp_send_json_error(['message' => 'No eligible new members were selected. Check this chat’s exclusion rules.'], 400);
      $participants = array_values(array_unique(array_merge($participants, $eligible)));
      update_post_meta($thread_id, self::META_PARTICIPANTS, $participants);
      if ((string)get_post_meta($thread_id, self::META_TYPE, true) === 'direct') update_post_meta($thread_id, self::META_TYPE, 'group');
      $actor = get_userdata($viewer_id);
      $actor_name = $actor ? (string)($actor->display_name ?: $actor->user_login) : 'A chat manager';
      foreach ($eligible as $new_user_id) {
        $this->set_thread_state_flag((int)$new_user_id, $thread_id, 'archived', false);
        $this->set_thread_state_flag((int)$new_user_id, $thread_id, 'deleted', false);
        $state = $this->thread_state_for_user((int)$new_user_id, $thread_id);
        if (empty($state['muted']) && class_exists('EUM_Notification_Center')) {
          EUM_Notification_Center::create_notification((int)$new_user_id, [
            'title' => 'Added to a chat',
            'body' => $actor_name . ' added you to ' . $this->thread_label($thread_id, (int)$new_user_id) . '.',
            'type' => 'direct_message',
            'action_url' => add_query_arg(['eum_open_messages' => '1', 'eum_dm_thread' => $thread_id], home_url('/')),
            'actor_user_id' => $viewer_id,
            'related_object_id' => $thread_id,
            'deliver_discord' => true,
          ]);
        }
      }
      wp_send_json_success(['thread_id' => $thread_id, 'message' => count($eligible) . ' member(s) added.']);
    }

    if ($operation === 'remove_member') {
      $remove_id = absint($_POST['user_id'] ?? 0);
      if (!in_array($remove_id, $participants, true)) wp_send_json_error(['message' => 'That user is not a member of this chat.'], 404);
      $remaining = array_values(array_diff($participants, [$remove_id]));
      if (empty($remaining)) wp_send_json_error(['message' => 'A chat must have at least one member.'], 400);
      update_post_meta($thread_id, self::META_PARTICIPANTS, $remaining);
      $admins = array_values(array_diff($this->thread_admin_ids($thread_id), [$remove_id]));
      if (empty($admins)) $admins = [(int)$remaining[0]];
      update_post_meta($thread_id, self::META_ADMINS, $admins);
      wp_send_json_success(['thread_id' => $thread_id, 'actor_removed' => $remove_id === $viewer_id, 'message' => 'Member removed.']);
    }

    if ($operation === 'save_admins') {
      $admins = $this->normalize_ids((array)wp_unslash($_POST['admin_ids'] ?? []));
      if (empty($admins) || count(array_diff($admins, $participants)) > 0) {
        wp_send_json_error(['message' => 'Choose at least one chat admin from the current members.'], 400);
      }
      foreach ($admins as $admin_id) {
        if ($this->member_is_excluded($admin_id, $excluded_users, $excluded_statuses)) {
          wp_send_json_error(['message' => 'A chat admin cannot be excluded by this chat’s membership rules.'], 400);
        }
      }
      update_post_meta($thread_id, self::META_ADMINS, $admins);
      wp_send_json_success(['thread_id' => $thread_id, 'message' => 'Chat admins updated.']);
    }

    if ($operation === 'save_rules') {
      $new_excluded_users = $this->valid_excluded_user_ids((array)wp_unslash($_POST['excluded_user_ids'] ?? []));
      $new_excluded_statuses = $this->normalize_excluded_statuses((array)wp_unslash($_POST['excluded_statuses'] ?? []));
      $remaining = array_values(array_filter($participants, function ($user_id) use ($new_excluded_users, $new_excluded_statuses) {
        return !$this->member_is_excluded((int)$user_id, $new_excluded_users, $new_excluded_statuses);
      }));
      if (empty($remaining)) wp_send_json_error(['message' => 'These exclusions would remove every member. Adjust the rules and try again.'], 400);
      update_post_meta($thread_id, self::META_EXCLUDED_USERS, $new_excluded_users);
      update_post_meta($thread_id, self::META_EXCLUDED_STATUSES, $new_excluded_statuses);
      update_post_meta($thread_id, self::META_PARTICIPANTS, $remaining);
      $admins = array_values(array_intersect($this->thread_admin_ids($thread_id), $remaining));
      if (empty($admins)) $admins = [(int)$remaining[0]];
      update_post_meta($thread_id, self::META_ADMINS, $admins);
      wp_send_json_success(['thread_id' => $thread_id, 'removed_count' => count($participants) - count($remaining), 'actor_removed' => !in_array($viewer_id, $remaining, true), 'message' => 'Membership exclusions saved.']);
    }

    if ($operation === 'remove_avatar') {
      delete_post_meta($thread_id, self::META_AVATAR_ID);
      wp_send_json_success(['thread_id' => $thread_id, 'avatar' => '']);
    }

    wp_send_json_error(['message' => 'Choose a valid chat management action.'], 400);
  }

  public function ajax_popup_avatar(): void {
    $viewer_id = $this->require_popup_user();
    $thread_id = absint($_POST['thread_id'] ?? 0);
    $thread = get_post($thread_id);
    if (!$thread instanceof WP_Post || $thread->post_type !== self::CPT_THREAD || !$this->can_manage_thread($viewer_id, $thread_id)) {
      wp_send_json_error(['message' => 'You cannot change that chat image.'], 403);
    }
    if ((string)get_post_meta($thread_id, self::META_TYPE, true) === 'direct') {
      wp_send_json_error(['message' => 'Chat images are available for group conversations.'], 400);
    }
    if (empty($_FILES['chat_image']['name'])) wp_send_json_error(['message' => 'Choose an image to upload.'], 400);
    if (!function_exists('media_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $attachment_id = media_handle_upload('chat_image', $thread_id, [], [
      'test_form' => false,
      'mimes' => ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'],
    ]);
    if (is_wp_error($attachment_id) || !wp_attachment_is_image((int)$attachment_id)) {
      wp_send_json_error(['message' => is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Upload a valid image file.'], 400);
    }
    update_post_meta($thread_id, self::META_AVATAR_ID, (int)$attachment_id);
    wp_send_json_success(['thread_id' => $thread_id, 'avatar' => (string)wp_get_attachment_image_url((int)$attachment_id, 'thumbnail')]);
  }

  public function ajax_popup_personal_state(): void {
    $viewer_id = $this->require_popup_user();
    $thread_id = absint($_POST['thread_id'] ?? 0);
    $thread = get_post($thread_id);
    if (!$thread instanceof WP_Post || $thread->post_type !== self::CPT_THREAD) {
      wp_send_json_error(['message' => 'That chat is not available.'], 404);
    }
    $target_user_id = absint($_POST['target_user_id'] ?? 0);
    if ($target_user_id <= 0) $target_user_id = $viewer_id;
    if (!in_array($target_user_id, $this->thread_participants($thread_id), true)) {
      wp_send_json_error(['message' => 'That user is not a member of this chat.'], 404);
    }
    if ($target_user_id !== $viewer_id && (!$this->can_view_inbox($viewer_id, $target_user_id) || !$this->can_manage_thread($viewer_id, $thread_id))) {
      wp_send_json_error(['message' => 'You cannot manage this chat for that user.'], 403);
    }
    if ($target_user_id === $viewer_id && !in_array($viewer_id, $this->thread_participants($thread_id), true)) {
      wp_send_json_error(['message' => 'You are not a member of this chat.'], 403);
    }
    $operation = sanitize_key(wp_unslash((string)($_POST['operation'] ?? '')));
    $states = $this->thread_states_for_user($target_user_id);
    $key = (string)$thread_id;
    $state = is_array($states[$key] ?? null) ? $states[$key] : [];
    if ($operation === 'archive') {
      $state['archived'] = true;
      $state['deleted'] = false;
    } elseif ($operation === 'delete') {
      $state['deleted'] = true;
      $state['archived'] = false;
    } elseif ($operation === 'restore') {
      $state['archived'] = false;
      $state['deleted'] = false;
    } elseif ($operation === 'mute') {
      $state['muted'] = empty($state['muted']);
    } else {
      wp_send_json_error(['message' => 'Choose Archive, Delete, Mute, or Restore.'], 400);
    }
    $states[$key] = [
      'archived' => !empty($state['archived']),
      'deleted' => !empty($state['deleted']),
      'muted' => !empty($state['muted']),
    ];
    update_user_meta($target_user_id, self::USER_META_THREAD_STATE, $states);
    wp_send_json_success(['thread_id' => $thread_id, 'state' => $states[$key], 'target_user_id' => $target_user_id]);
  }

  private function require_popup_user(): int {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please sign in again.'], 401);
    check_ajax_referer('eum_dm_popup', 'nonce');
    return get_current_user_id();
  }

  private function popup_thread_summary(int $thread_id, int $subject_user_id, int $viewer_id): array {
    $messages = get_posts([
      'post_type' => self::CPT_MESSAGE, 'post_status' => 'publish', 'post_parent' => $thread_id,
      'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC',
    ]);
    $last = $messages ? $messages[0] : null;
    $preview = $last ? trim(preg_replace('/\\s+/', ' ', html_entity_decode(wp_strip_all_tags((string)$last->post_content), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '';
    $preview = function_exists('mb_substr') ? mb_substr($preview, 0, 110) : substr($preview, 0, 110);
    $other_participants = array_values(array_diff($this->thread_participants($thread_id), [$subject_user_id]));
    $avatar_user_id = !empty($other_participants) ? (int)$other_participants[0] : 0;
    $type = (string)get_post_meta($thread_id, self::META_TYPE, true);
    $avatar_id = (int)get_post_meta($thread_id, self::META_AVATAR_ID, true);
    $avatar_url = $avatar_id > 0 ? (string)wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
    if ($avatar_url === '' && $type === 'direct' && $avatar_user_id > 0 && class_exists('EUM_Profiles')) {
      $avatar_url = EUM_Profiles::profile_avatar_url($avatar_user_id);
    }
    $is_unread = false;
    if ($subject_user_id === $viewer_id && class_exists('EUM_Notification_Center')) {
      $notifications = get_posts([
        'post_type' => EUM_Notification_Center::CPT_NOTIFICATION, 'post_status' => 'publish', 'numberposts' => 1,
        'meta_query' => [
          ['key' => EUM_Notification_Center::META_USER_ID, 'value' => $viewer_id],
          ['key' => EUM_Notification_Center::META_RELATED_OBJECT_ID, 'value' => $thread_id],
          ['key' => EUM_Notification_Center::META_TYPE, 'value' => 'direct_message'],
          ['key' => EUM_Notification_Center::META_IS_READ, 'value' => '0'],
        ],
      ]);
      $is_unread = !empty($notifications);
    }
    return [
      'id' => $thread_id,
      'title' => $this->thread_label($thread_id, $subject_user_id),
      'avatar' => $avatar_url,
      'is_group' => $type !== 'direct',
      'type' => $type,
      'preview' => $preview,
      'time' => $last ? get_date_from_gmt((string)$last->post_date_gmt, 'M j') : '',
      'unread' => $is_unread,
      'archived' => !empty($this->thread_state_for_user($subject_user_id, $thread_id)['archived']),
      'deleted' => !empty($this->thread_state_for_user($subject_user_id, $thread_id)['deleted']),
      'muted' => !empty($this->thread_state_for_user($subject_user_id, $thread_id)['muted']),
    ];
  }

  public function register_post_types(): void {
    register_post_type(self::CPT_THREAD, [
      'labels' => ['name' => 'Message Threads', 'singular_name' => 'Message Thread'],
      'public' => false, 'publicly_queryable' => false, 'show_ui' => false, 'show_in_menu' => false,
      'supports' => ['title', 'author'], 'capability_type' => 'post', 'map_meta_cap' => true,
    ]);
    register_post_type(self::CPT_MESSAGE, [
      'labels' => ['name' => 'Direct Messages', 'singular_name' => 'Direct Message'],
      'public' => false, 'publicly_queryable' => false, 'show_ui' => false, 'show_in_menu' => false,
      'supports' => ['title', 'editor', 'author', 'page-attributes'], 'capability_type' => 'post', 'map_meta_cap' => true,
    ]);
  }

  public function shortcode(): string {
    if (!is_user_logged_in()) return '<p>Please sign in to view messages.</p>';
    return '<p class="eum-message-topbar-hint">Open Messages from the portal top bar to view or start a conversation.</p>';
  }

  private function render_team_inbox_picker(int $viewer_id, int $selected_user_id): void {
    $team_ids = $this->team_user_ids($viewer_id);
    if (empty($team_ids)) return;
    echo '<div style="border:1px solid #d0d5dd;border-radius:12px;padding:14px;margin:0 0 18px;background:#fff;">';
    echo '<strong>Team inboxes</strong><p style="margin:4px 0 10px;color:#667085;">Choose a person to review their complete conversation inbox.</p>';
    echo '<form method="get" action="' . esc_url(self::messages_url()) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;"><label class="screen-reader-text" for="eum-dm-team-inbox">Choose a team member</label><select id="eum-dm-team-inbox" name="eum_dm_inbox_user" style="min-width:240px;"><option value="">Choose a person</option>';
    foreach ($team_ids as $team_id) {
      $user = get_userdata($team_id);
      if (!$user) continue;
      echo '<option value="' . (int)$team_id . '" ' . selected($selected_user_id, (int)$team_id, false) . '>' . esc_html($user->display_name ?: $user->user_login) . '</option>';
    }
    echo '</select><button class="button" type="submit">Open inbox</button></form></div>';
  }

  private function render_thread_list(array $threads, int $subject_user_id): void {
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-bottom:24px;">';
    if (empty($threads)) echo '<p style="grid-column:1/-1;color:#667085;">No conversations yet.</p>';
    foreach ($threads as $thread) {
      $thread_id = (int)$thread->ID;
      $type = (string)get_post_meta($thread_id, self::META_TYPE, true);
      $label = $this->thread_label($thread_id, $subject_user_id);
      $url = add_query_arg(['eum_dm_inbox_user' => $subject_user_id, 'eum_dm_thread' => $thread_id], self::messages_url());
      if ($subject_user_id === get_current_user_id()) $url = add_query_arg('eum_dm_inbox_user', false, $url);
      $messages = get_posts(['post_type' => self::CPT_MESSAGE, 'post_status' => 'publish', 'post_parent' => $thread_id, 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC']);
      $last = $messages ? wp_strip_all_tags((string)$messages[0]->post_content) : '';
      $last = function_exists('mb_substr') ? mb_substr($last, 0, 135) : substr($last, 0, 135);
      echo '<a href="' . esc_url($url) . '" style="display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid #d0d5dd;border-radius:12px;padding:15px;">';
      echo '<strong>' . esc_html($label) . '</strong><span style="float:right;color:#667085;font-size:12px;">' . esc_html(ucfirst($type ?: 'direct')) . '</span>';
      if ($last !== '') echo '<p style="margin:8px 0 0;color:#667085;">' . esc_html($last) . '</p>';
      echo '</a>';
    }
    echo '</div>';
  }

  private function render_thread(int $thread_id, int $viewer_id, int $subject_user_id): void {
    $thread = get_post($thread_id);
    if (!$thread) return;
    $type = (string)get_post_meta($thread_id, self::META_TYPE, true);
    $label = $this->thread_label($thread_id, $subject_user_id);
    $back_url = $subject_user_id === $viewer_id ? self::messages_url() : add_query_arg('eum_dm_inbox_user', $subject_user_id, self::messages_url());
    echo '<div style="background:#fff;border:1px solid #d0d5dd;border-radius:14px;padding:18px;margin-bottom:22px;">';
    echo '<p style="margin:0 0 12px;"><a href="' . esc_url($back_url) . '">&larr; Inbox</a></p>';
    echo '<h3 style="margin:0 0 4px;">' . esc_html($label) . '</h3><p style="margin:0 0 14px;color:#667085;">' . esc_html(ucfirst($type ?: 'direct')) . ($type === 'announcement' ? ' · Replies are limited to the sender.' : '') . '</p>';
    $messages = get_posts(['post_type' => self::CPT_MESSAGE, 'post_status' => 'publish', 'post_parent' => $thread_id, 'numberposts' => 500, 'orderby' => 'date', 'order' => 'ASC']);
    echo '<div style="display:grid;gap:10px;">';
    foreach ($messages as $message) {
      $author_id = (int)$message->post_author;
      $author = get_userdata($author_id);
      $name = $author ? ($author->display_name ?: $author->user_login) : 'Portal user';
      $when = get_date_from_gmt((string)$message->post_date_gmt, 'M j, Y g:i A');
      echo '<article style="border:1px solid #eaecf0;border-radius:10px;padding:12px 14px;"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;"><strong>' . esc_html($name) . '</strong><small style="color:#667085;">' . esc_html($when) . '</small></div><div style="margin-top:8px;line-height:1.55;">' . wp_kses_post(wpautop((string)$message->post_content)) . '</div></article>';
    }
    echo '</div>';
    $can_reply = $subject_user_id === $viewer_id && ($type !== 'announcement' || (int)$thread->post_author === $viewer_id);
    if ($can_reply) $this->render_reply_form($thread_id);
    echo '</div>';
  }

  private function render_reply_form(int $thread_id): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
    echo '<input type="hidden" name="action" value="eum_dm_reply"><input type="hidden" name="thread_id" value="' . $thread_id . '">';
    wp_nonce_field('eum_dm_reply_' . $thread_id, 'eum_dm_nonce');
    echo '<label for="eum-dm-reply"><strong>Reply</strong></label><textarea id="eum-dm-reply" name="message" rows="4" required style="display:block;width:100%;margin:7px 0 10px;"></textarea><button class="button button-primary" type="submit">Send reply</button></form>';
  }

  private function render_compose_form(int $user_id): void {
    $users = get_users(['number' => 2000, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => ['ID', 'display_name', 'user_login']]);
    $groups = $this->saved_groups($user_id);
    $targets = $this->audience_options($user_id);
    echo '<div style="background:#f8fafc;border:1px solid #d0d5dd;border-radius:14px;padding:18px;"><h3 style="margin:0 0 12px;">Start a conversation or announcement</h3>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_dm_send">';
    wp_nonce_field('eum_dm_send', 'eum_dm_nonce');
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">';
    echo '<p style="margin:0;"><label><strong>Type</strong><select name="message_type" style="display:block;width:100%;margin-top:5px;"><option value="direct">Direct message</option><option value="group">Group message</option>';
    if ($this->can_manage_audience($user_id)) echo '<option value="announcement">Announcement (read-only for recipients)</option>';
    echo '</select></label></p>';
    echo '<p style="margin:0;"><label><strong>Subject (optional)</strong><input type="text" name="subject" maxlength="120" style="display:block;width:100%;margin-top:5px;"></label></p></div>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:12px;">';
    echo '<label><strong>People</strong><select name="recipient_ids[]" multiple size="8" style="display:block;width:100%;margin-top:5px;">';
    foreach ($users as $user) {
      $uid = (int)$user->ID;
      if ($uid === $user_id) continue;
      echo '<option value="' . $uid . '">' . esc_html($user->display_name ?: $user->user_login) . '</option>';
    }
    echo '</select><small>Select one person for a direct message. Multiple people or a managed audience will open a group conversation.</small></label>';
    echo '<div><strong>Saved recipient groups</strong>';
    if (empty($groups)) echo '<p style="margin:5px 0;color:#667085;">Save a group from a recipient list below to reuse it.</p>';
    else {
      echo '<div style="max-height:180px;overflow:auto;margin-top:6px;">';
      foreach ($groups as $group_id => $group) {
        echo '<label style="display:block;margin:5px 0;"><input type="checkbox" name="saved_group_ids[]" value="' . esc_attr((string)$group_id) . '"> ' . esc_html((string)$group['name']) . ' (' . count((array)$group['user_ids']) . ')</label>';
      }
      echo '</div>';
    }
    if (!empty($targets)) {
      echo '<label style="display:block;margin-top:12px;"><strong>Managed paths, groups, and projects</strong><select name="audience_targets[]" multiple size="6" style="display:block;width:100%;margin-top:5px;">';
      foreach ($targets as $target) echo '<option value="' . esc_attr($target['value']) . '">' . esc_html($target['label']) . '</option>';
      echo '</select><small>Only scopes you manage are available.</small></label>';
    }
    echo '</div></div>';
    echo '<p style="margin:12px 0 0;"><label>Save this recipient list as a reusable group <input type="text" name="save_group_name" maxlength="80" placeholder="Group name"></label></p>';
    echo '<label style="display:block;margin-top:12px;"><strong>Message</strong><textarea name="message" rows="5" required style="display:block;width:100%;margin-top:5px;"></textarea></label>';
    echo '<p style="margin:12px 0 0;"><button class="button button-primary" type="submit">Send</button></p></form></div>';
    echo '<p style="color:#667085;font-size:13px;margin-top:10px;">New messages create portal notifications, are sent to company email, and are delivered to linked Discord accounts.</p>';
  }

  public function handle_send(): void {
    $sender_id = get_current_user_id();
    if ($sender_id <= 0 || !isset($_POST['eum_dm_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_dm_nonce']), 'eum_dm_send')) wp_die('Security check failed.');
    $type_value = wp_unslash($_POST['message_type'] ?? 'direct');
    if (!is_scalar($type_value)) wp_die('Invalid message type.');
    $type = sanitize_key((string)$type_value);
    if (!in_array($type, ['direct', 'group', 'announcement'], true)) wp_die('Invalid message type.');
    if ($type === 'announcement' && !$this->can_manage_audience($sender_id)) wp_die('Only managers, HR, or IT can send announcements.');
    $message_value = wp_unslash($_POST['message'] ?? '');
    if (!is_scalar($message_value)) wp_die('Please enter a message.');
    $body = wp_kses_post((string)$message_value);
    if (trim(wp_strip_all_tags($body)) === '') wp_die('Please enter a message.');
    $subject_value = wp_unslash($_POST['subject'] ?? '');
    $subject = is_scalar($subject_value) ? sanitize_text_field((string)$subject_value) : '';
    $recipient_ids = $this->normalize_ids((array)wp_unslash($_POST['recipient_ids'] ?? []));
    $excluded_user_ids = $this->valid_excluded_user_ids((array)wp_unslash($_POST['excluded_user_ids'] ?? []));
    $excluded_statuses = $this->normalize_excluded_statuses((array)wp_unslash($_POST['excluded_statuses'] ?? []));
    if (in_array($sender_id, $excluded_user_ids, true) || $this->member_matches_excluded_status($sender_id, $excluded_statuses)) wp_die('You cannot exclude yourself from a chat you are starting.');
    $groups = $this->saved_groups($sender_id);
    foreach ((array)wp_unslash($_POST['saved_group_ids'] ?? []) as $group_id) {
      if (!is_scalar($group_id)) continue;
      $group_id = sanitize_key((string)$group_id);
      if (isset($groups[$group_id])) $recipient_ids = array_merge($recipient_ids, $this->normalize_ids((array)$groups[$group_id]['user_ids']));
    }
    $audience_values = [];
    foreach ((array)wp_unslash($_POST['audience_targets'] ?? []) as $target_value) {
      if (is_scalar($target_value)) $audience_values[] = sanitize_text_field((string)$target_value);
    }
    $audience_values = array_values(array_unique(array_filter($audience_values)));
    if (!empty($audience_values)) {
      $options = $this->audience_options($sender_id);
      $allowed = array_column($options, 'value');
      foreach ($audience_values as $target_value) {
        if (!in_array($target_value, $allowed, true)) wp_die('You cannot message that managed group or path.');
        [$taxonomy, $term_id] = array_pad(explode(':', $target_value, 2), 2, '0');
        if ($taxonomy === 'jobpath') {
          $term_ids = [(int)$term_id];
          $children = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'child_of' => (int)$term_id, 'fields' => 'ids', 'number' => 0]);
          if (!is_wp_error($children)) foreach ((array)$children as $child_id) $term_ids[] = (int)$child_id;
          foreach (array_unique(array_map('intval', $term_ids)) as $path_id) {
            $found = get_objects_in_term($path_id, 'eum_job_path');
            if (!is_wp_error($found)) $recipient_ids = array_merge($recipient_ids, array_map('intval', (array)$found));
          }
        } elseif ($taxonomy === 'group' || $taxonomy === 'project') {
          $audience_taxonomy = $taxonomy === 'group' ? 'eum_user_group' : 'eum_project';
          $found = get_objects_in_term((int)$term_id, $audience_taxonomy);
          if (!is_wp_error($found)) $recipient_ids = array_merge($recipient_ids, array_map('intval', (array)$found));
        }
      }
    }
    $recipient_ids = $this->normalize_ids($recipient_ids);
    $recipient_ids = array_values(array_filter($recipient_ids, function ($recipient_id) use ($excluded_user_ids, $excluded_statuses, $type) {
      if (in_array($type, ['group', 'announcement'], true) && !get_userdata((int)$recipient_id)) return false;
      return !$this->member_is_excluded((int)$recipient_id, $excluded_user_ids, $excluded_statuses);
    }));
    $recipient_ids = array_values(array_diff($recipient_ids, [$sender_id]));
    if (empty($recipient_ids)) wp_die('No eligible recipients remain after applying this chat’s membership exclusions.');
    if ($type === 'direct' && count($recipient_ids) > 1) $type = 'group';
    if ($type === 'direct' && count($recipient_ids) !== 1) wp_die('A direct message needs one recipient.');
    if ($type === 'group' && count($recipient_ids) < 2) wp_die('A group message needs at least two recipients.');
    if ($type === 'announcement' && empty($audience_values)) wp_die('Choose at least one managed path or group for the announcement.');
    foreach ($recipient_ids as $recipient_id) if (!get_userdata($recipient_id)) wp_die('One or more recipients are invalid.');
    if (empty($recipient_ids)) wp_die('Choose at least one recipient.');

    $save_group_value = wp_unslash($_POST['save_group_name'] ?? '');
    $save_group_name = is_scalar($save_group_value) ? sanitize_text_field((string)$save_group_value) : '';
    if ($save_group_name !== '') $this->save_group($sender_id, $save_group_name, $recipient_ids);
    $thread_id = $this->create_thread($sender_id, $recipient_ids, $type, $subject, $body, $audience_values, $excluded_user_ids, $excluded_statuses);
    if ($thread_id <= 0) wp_die('The message could not be saved. Please try again.');
    wp_safe_redirect(add_query_arg('eum_dm_thread', $thread_id, self::messages_url()));
    exit;
  }

  public function handle_reply(): void {
    $user_id = get_current_user_id();
    $thread_id = max(0, (int)($_POST['thread_id'] ?? 0));
    if ($user_id <= 0 || $thread_id <= 0 || !isset($_POST['eum_dm_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_dm_nonce']), 'eum_dm_reply_' . $thread_id)) wp_die('Security check failed.');
    $thread = get_post($thread_id);
    if (!$thread instanceof WP_Post || $thread->post_type !== self::CPT_THREAD || !in_array($user_id, $this->thread_participants($thread_id), true)) wp_die('You cannot reply to this conversation.');
    $type = (string)get_post_meta($thread_id, self::META_TYPE, true);
    if ($type === 'announcement' && (int)$thread->post_author !== $user_id) wp_die('Recipients cannot reply to announcements.');
    $message_value = wp_unslash($_POST['message'] ?? '');
    if (!is_scalar($message_value)) wp_die('Please enter a reply.');
    $body = wp_kses_post((string)$message_value);
    if (trim(wp_strip_all_tags($body)) === '') wp_die('Please enter a reply.');
    $this->append_message($thread_id, $user_id, $body);
    $this->set_thread_state_flag($user_id, $thread_id, 'archived', false);
    $this->set_thread_state_flag($user_id, $thread_id, 'deleted', false);
    wp_safe_redirect(add_query_arg('eum_dm_thread', $thread_id, self::messages_url()));
    exit;
  }

  private function create_thread(int $sender_id, array $recipient_ids, string $type, string $subject, string $body, array $audience_values, array $excluded_user_ids = [], array $excluded_statuses = []): int {
    $participants = $this->normalize_ids(array_merge([$sender_id], $recipient_ids));
    if ($type === 'direct') {
      $existing_thread_id = $this->existing_direct_thread($participants);
      if ($existing_thread_id > 0) {
        if ($subject !== '') {
          update_post_meta($existing_thread_id, self::META_SUBJECT, $subject);
          wp_update_post(['ID' => $existing_thread_id, 'post_title' => $subject]);
        }
        $message_id = $this->append_message($existing_thread_id, $sender_id, $body);
        if ($message_id <= 0) return 0;
        // Starting a new message intentionally brings the sender's copy back
        // into their active inbox; mute remains a separate notification choice.
        $this->set_thread_state_flag($sender_id, $existing_thread_id, 'archived', false);
        $this->set_thread_state_flag($sender_id, $existing_thread_id, 'deleted', false);
        return $existing_thread_id;
      }
    }
    $title = $subject !== '' ? $subject : ($type === 'announcement' ? 'Announcement' : 'Conversation');
    $thread_id = wp_insert_post(['post_type' => self::CPT_THREAD, 'post_status' => 'publish', 'post_title' => $title, 'post_author' => $sender_id], true);
    if (is_wp_error($thread_id) || (int)$thread_id <= 0) return 0;
    $thread_id = (int)$thread_id;
    update_post_meta($thread_id, self::META_PARTICIPANTS, $participants);
    update_post_meta($thread_id, self::META_TYPE, $type);
    update_post_meta($thread_id, self::META_SUBJECT, $subject);
    update_post_meta($thread_id, self::META_ADMINS, [$sender_id]);
    update_post_meta($thread_id, self::META_EXCLUDED_USERS, $this->normalize_ids($excluded_user_ids));
    update_post_meta($thread_id, self::META_EXCLUDED_STATUSES, $this->normalize_excluded_statuses($excluded_statuses));
    update_post_meta($thread_id, '_eum_dm_audience', $audience_values);
    $this->append_message($thread_id, $sender_id, $body);
    return $thread_id;
  }

  private function append_message(int $thread_id, int $sender_id, string $body): int {
    $message_id = wp_insert_post([
      'post_type' => self::CPT_MESSAGE, 'post_status' => 'publish', 'post_parent' => $thread_id,
      'post_title' => 'Message in thread ' . $thread_id, 'post_content' => $body, 'post_author' => $sender_id,
    ], true);
    if (is_wp_error($message_id) || (int)$message_id <= 0) return 0;
    update_post_meta($thread_id, self::META_LAST_MESSAGE_AT, current_time('mysql'));
    wp_update_post(['ID' => $thread_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', true)]);
    $participants = array_values(array_diff($this->thread_participants($thread_id), [$sender_id]));
    $type = (string)get_post_meta($thread_id, self::META_TYPE, true);
    $this->notify_recipients($thread_id, $sender_id, $participants, $type, $body);
    return (int)$message_id;
  }

  private function existing_direct_thread(array $participants): int {
    $threads = get_posts([
      'post_type' => self::CPT_THREAD, 'post_status' => 'publish', 'numberposts' => 3000,
      'orderby' => 'modified', 'order' => 'DESC',
      'meta_query' => [['key' => self::META_PARTICIPANTS, 'value' => 'i:' . (int)$participants[0] . ';', 'compare' => 'LIKE']],
    ]);
    $comparison = $participants;
    sort($comparison);
    foreach ($threads as $thread) {
      $thread_id = (int)$thread->ID;
      if ((string)get_post_meta($thread_id, self::META_TYPE, true) !== 'direct') continue;
      $thread_participants = $this->thread_participants($thread_id);
      sort($thread_participants);
      if ($thread_participants === $comparison) return $thread_id;
    }
    return 0;
  }

  private function notify_recipients(int $thread_id, int $sender_id, array $recipient_ids, string $type, string $body): void {
    $sender = get_userdata($sender_id);
    $sender_label = $sender ? ($sender->display_name ?: $sender->user_login) : 'A portal user';
    $title = $type === 'announcement' ? 'New Announcement' : 'New Message from ' . $sender_label;
    $preview = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($body)));
    $preview = function_exists('mb_substr') ? mb_substr($preview, 0, 280) : substr($preview, 0, 280);
    $url = home_url('/');
    foreach ($this->normalize_ids($recipient_ids) as $recipient_id) {
      if ($recipient_id === $sender_id) continue;
      $state = $this->thread_state_for_user($recipient_id, $thread_id);
      if (!empty($state['deleted'])) continue;
      if (!empty($state['archived'])) {
        $this->set_thread_state_flag($recipient_id, $thread_id, 'archived', false);
      }
      if (!empty($state['muted'])) continue;
      if (class_exists('EUM_Notification_Center')) {
        EUM_Notification_Center::create_notification($recipient_id, [
          'title' => $title, 'body' => $preview, 'type' => 'direct_message', 'action_url' => $url,
          'actor_user_id' => $sender_id, 'related_object_id' => $thread_id, 'deliver_discord' => true,
        ]);
      }
      $recipient = get_userdata($recipient_id);
      $company_email = $recipient ? sanitize_email((string)$recipient->user_email) : '';
      if ($company_email !== '' && (!class_exists('EUM_Personnel') || !EUM_Personnel::is_terminated($recipient_id))) {
        $message = '<p>' . esc_html($title) . '</p><p>' . nl2br(esc_html($preview)) . '</p><p>Open Messages from the top bar in the portal.</p><p><a href="' . esc_url($url) . '">Open portal</a></p>';
        wp_mail($company_email, $title, $message, ['Content-Type: text/html; charset=UTF-8']);
      }
    }
  }

  private function threads_for_user(int $user_id, bool $include_hidden = false): array {
    $all = get_posts([
      'post_type' => self::CPT_THREAD, 'post_status' => 'publish', 'numberposts' => 3000,
      'orderby' => 'modified', 'order' => 'DESC',
      'meta_query' => [['key' => self::META_PARTICIPANTS, 'value' => 'i:' . $user_id . ';', 'compare' => 'LIKE']],
    ]);
    return array_values(array_filter($all, function($thread) use ($user_id, $include_hidden) {
      if (!$thread instanceof WP_Post || !in_array($user_id, $this->thread_participants((int)$thread->ID), true)) return false;
      if ($include_hidden) return true;
      $state = $this->thread_state_for_user($user_id, (int)$thread->ID);
      return empty($state['archived']) && empty($state['deleted']);
    }));
  }

  private function thread_participants(int $thread_id): array {
    return $this->normalize_ids((array)get_post_meta($thread_id, self::META_PARTICIPANTS, true));
  }

  private function thread_admin_ids(int $thread_id): array {
    $participants = $this->thread_participants($thread_id);
    $admins = array_values(array_intersect($this->normalize_ids((array)get_post_meta($thread_id, self::META_ADMINS, true)), $participants));
    if (!empty($admins)) return $admins;
    $thread = get_post($thread_id);
    $fallback = $thread instanceof WP_Post ? (int)$thread->post_author : 0;
    if ($fallback > 0 && in_array($fallback, $participants, true)) return [$fallback];
    return !empty($participants) ? [(int)$participants[0]] : [];
  }

  private function can_manage_thread(int $user_id, int $thread_id): bool {
    if ($user_id <= 0 || $thread_id <= 0) return false;
    if (user_can($user_id, 'manage_options') || (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id))) return true;
    $participants = $this->thread_participants($thread_id);
    if (in_array($user_id, $this->thread_admin_ids($thread_id), true)) return true;
    if (class_exists('EUM_Manager_IDs')) {
      foreach ($participants as $participant_id) {
        if (EUM_Manager_IDs::user_is_manager_of_user($user_id, (int)$participant_id)) return true;
      }
    }
    return false;
  }

  private function thread_states_for_user(int $user_id): array {
    $states = get_user_meta($user_id, self::USER_META_THREAD_STATE, true);
    return is_array($states) ? $states : [];
  }

  private function thread_state_for_user(int $user_id, int $thread_id): array {
    $states = $this->thread_states_for_user($user_id);
    $state = $states[(string)$thread_id] ?? [];
    return is_array($state) ? $state : [];
  }

  private function set_thread_state_flag(int $user_id, int $thread_id, string $flag, bool $value): void {
    if ($user_id <= 0 || $thread_id <= 0 || !in_array($flag, ['archived', 'deleted', 'muted'], true)) return;
    $states = $this->thread_states_for_user($user_id);
    $key = (string)$thread_id;
    $state = is_array($states[$key] ?? null) ? $states[$key] : [];
    $state[$flag] = $value;
    $states[$key] = [
      'archived' => !empty($state['archived']),
      'deleted' => !empty($state['deleted']),
      'muted' => !empty($state['muted']),
    ];
    update_user_meta($user_id, self::USER_META_THREAD_STATE, $states);
  }

  private function thread_excluded_user_ids(int $thread_id): array {
    return $this->normalize_ids((array)get_post_meta($thread_id, self::META_EXCLUDED_USERS, true));
  }

  private function thread_excluded_statuses(int $thread_id): array {
    return $this->normalize_excluded_statuses((array)get_post_meta($thread_id, self::META_EXCLUDED_STATUSES, true));
  }

  private function normalize_excluded_statuses(array $statuses): array {
    $allowed = ['active', 'terminated', 'rehirable', 'non_rehirable'];
    $clean = [];
    foreach ($statuses as $status) {
      if (!is_scalar($status)) continue;
      $status = sanitize_key((string)$status);
      if (in_array($status, $allowed, true)) $clean[] = $status;
    }
    return array_values(array_unique($clean));
  }

  private function valid_excluded_user_ids(array $ids): array {
    $valid = [];
    foreach ($this->normalize_ids($ids) as $user_id) {
      if (get_userdata($user_id)) $valid[] = $user_id;
    }
    return $valid;
  }

  private function member_matches_excluded_status(int $user_id, array $statuses): bool {
    if ($user_id <= 0 || empty($statuses)) return false;
    $terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
    $rehirable = $terminated && class_exists('EUM_Personnel') && EUM_Personnel::is_rehirable($user_id);
    foreach ($this->normalize_excluded_statuses($statuses) as $status) {
      if ($status === 'active' && !$terminated) return true;
      if ($status === 'terminated' && $terminated) return true;
      if ($status === 'rehirable' && $rehirable) return true;
      if ($status === 'non_rehirable' && $terminated && !$rehirable) return true;
    }
    return false;
  }

  private function member_is_excluded(int $user_id, array $excluded_user_ids, array $excluded_statuses): bool {
    return in_array($user_id, $excluded_user_ids, true) || $this->member_matches_excluded_status($user_id, $excluded_statuses);
  }

  private function excluded_user_options(array $user_ids): array {
    $rows = [];
    foreach ($this->valid_excluded_user_ids($user_ids) as $user_id) {
      $user = get_userdata($user_id);
      if ($user) $rows[] = ['id' => $user_id, 'name' => (string)($user->display_name ?: $user->user_login)];
    }
    return $rows;
  }

  private function thread_label(int $thread_id, int $subject_user_id): string {
    $subject = trim((string)get_post_meta($thread_id, self::META_SUBJECT, true));
    if ($subject !== '') return $subject;
    $participants = array_values(array_diff($this->thread_participants($thread_id), [$subject_user_id]));
    $names = [];
    foreach (array_slice($participants, 0, 4) as $participant_id) {
      $user = get_userdata($participant_id);
      if ($user) $names[] = $user->display_name ?: $user->user_login;
    }
    return $names ? implode(', ', $names) : 'Conversation';
  }

  private function can_read_thread(int $viewer_id, int $subject_user_id, int $thread_id): bool {
    return in_array($subject_user_id, $this->thread_participants($thread_id), true)
      && ($viewer_id === $subject_user_id || $this->can_view_inbox($viewer_id, $subject_user_id));
  }

  private function can_view_inbox(int $viewer_id, int $subject_user_id): bool {
    if ($viewer_id <= 0 || $subject_user_id <= 0 || !get_userdata($subject_user_id)) return false;
    if ($viewer_id === $subject_user_id) return true;
    if (user_can($viewer_id, 'manage_options')) return true;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($viewer_id)) return true;
    if (class_exists('EUM_Manager_IDs') && EUM_Manager_IDs::user_is_manager_of_user($viewer_id, $subject_user_id)) return true;
    return in_array($subject_user_id, $this->team_user_ids($viewer_id), true);
  }

  private function can_browse_team(int $user_id): bool {
    return !empty($this->team_user_ids($user_id));
  }

  private function can_manage_audience(int $user_id): bool {
    return current_user_can('manage_options') || (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) || (class_exists('EUM_Access') && EUM_Access::is_manager_user($user_id));
  }

  private function team_user_ids(int $user_id): array {
    if (user_can($user_id, 'manage_options') || (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id))) {
      $users = get_users(['fields' => 'ids', 'number' => 2000, 'orderby' => 'display_name']);
      return array_values(array_filter(array_map('intval', (array)$users), static fn($id) => $id > 0 && $id !== $user_id));
    }
    $user_ids = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::managed_user_ids_for_manager_user($user_id, true, true) : [];
    foreach ($this->audience_options($user_id) as $target) {
      [$target_type, $target_id] = array_pad(explode(':', (string)$target['value'], 2), 2, '0');
      $taxonomy = $target_type === 'jobpath' ? 'eum_job_path' : ($target_type === 'group' ? 'eum_user_group' : ($target_type === 'project' ? 'eum_project' : ''));
      if ($taxonomy === '' || !taxonomy_exists($taxonomy)) continue;
      $term_ids = [(int)$target_id];
      if ($taxonomy === 'eum_job_path') {
        $children = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'child_of' => (int)$target_id, 'fields' => 'ids', 'number' => 0]);
        if (!is_wp_error($children)) $term_ids = array_merge($term_ids, array_map('intval', (array)$children));
      }
      foreach (array_unique(array_map('intval', $term_ids)) as $term_id) {
        $members = get_objects_in_term($term_id, $taxonomy);
        if (!is_wp_error($members)) $user_ids = array_merge($user_ids, array_map('intval', (array)$members));
      }
    }
    $user_ids = array_values(array_diff($this->normalize_ids($user_ids), [$user_id]));
    usort($user_ids, static function($a, $b) {
      $user_a = get_userdata((int)$a);
      $user_b = get_userdata((int)$b);
      $name_a = $user_a ? ($user_a->display_name ?: $user_a->user_login) : '';
      $name_b = $user_b ? ($user_b->display_name ?: $user_b->user_login) : '';
      return strcasecmp($name_a, $name_b);
    });
    return $user_ids;
  }

  private function audience_options(int $user_id): array {
    if (!$this->can_manage_audience($user_id)) return [];
    $options = [];
    $privileged = current_user_can('manage_options') || (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id));
    if ($privileged) {
      foreach (['eum_job_path' => 'Job path', 'eum_user_group' => 'Group', 'eum_project' => 'Project'] as $taxonomy => $label) {
        if (!taxonomy_exists($taxonomy)) continue;
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0]);
        if (!is_wp_error($terms)) foreach ($terms as $term) {
          $prefix = $taxonomy === 'eum_job_path' ? 'jobpath:' : ($taxonomy === 'eum_user_group' ? 'group:' : 'project:');
          $options[] = ['value' => $prefix . (int)$term->term_id, 'label' => $label . ': ' . $term->name];
        }
      }
      return $options;
    }

    $managed_ids = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::managed_user_ids_for_manager_user($user_id, true, true) : [];
    $related_user_ids = $this->normalize_ids(array_merge([$user_id], $managed_ids));
    $manager_ids = [];
    foreach ($related_user_ids as $related_user_id) {
      if (class_exists('EUM_Manager_IDs')) $manager_ids = array_merge($manager_ids, EUM_Manager_IDs::applicable_manager_ids_for_user($related_user_id));
    }
    $manager_ids = array_values(array_unique(array_filter(array_map('intval', $manager_ids))));
    $root_terms = [];
    foreach ($manager_ids as $manager_id) {
      $term_id = (int)get_post_meta($manager_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
      if ($term_id > 0) $root_terms[] = $term_id;
    }
    $path_ids = [];
    foreach (array_unique($root_terms) as $root_id) {
      $path_ids[] = $root_id;
      $children = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'child_of' => $root_id, 'fields' => 'ids', 'number' => 0]);
      if (!is_wp_error($children)) $path_ids = array_merge($path_ids, array_map('intval', (array)$children));
    }
    foreach (array_unique(array_map('intval', $path_ids)) as $term_id) {
      $term = get_term($term_id, 'eum_job_path');
      if ($term instanceof WP_Term) $options[] = ['value' => 'jobpath:' . $term_id, 'label' => 'Job path: ' . $this->term_path_label($term_id)];
    }
    foreach (['eum_user_group' => 'Group', 'eum_project' => 'Project'] as $taxonomy => $label) {
      if (!taxonomy_exists($taxonomy)) continue;
      $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0]);
      if (is_wp_error($terms)) continue;
      foreach ($terms as $term) {
        $term_manager_ids = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::manager_ids_for_term($taxonomy, (int)$term->term_id) : [];
        if (array_intersect($manager_ids, $term_manager_ids)) {
          $prefix = $taxonomy === 'eum_user_group' ? 'group:' : 'project:';
          $options[] = ['value' => $prefix . (int)$term->term_id, 'label' => $label . ': ' . $term->name];
        }
      }
    }
    usort($options, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $options;
  }

  private function popup_audience_tree(int $user_id): array {
    $allowed = [];
    foreach ($this->audience_options($user_id) as $option) {
      $value = (string)($option['value'] ?? '');
      if (preg_match('/^(group|jobpath):(\d+)$/', $value, $matches)) {
        $allowed[$matches[1]][(int)$matches[2]] = true;
      }
    }

    $groups = [];
    if (!empty($allowed['group']) && taxonomy_exists('eum_user_group')) {
      $terms = get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'number' => 0, 'orderby' => 'name', 'order' => 'ASC']);
      if (!is_wp_error($terms)) foreach ($terms as $term) {
        if (!isset($allowed['group'][(int)$term->term_id])) continue;
        $groups[] = ['id' => (int)$term->term_id, 'value' => 'group:' . (int)$term->term_id, 'label' => (string)$term->name];
      }
    }

    $job_paths = [];
    if (!empty($allowed['jobpath']) && taxonomy_exists('eum_job_path')) {
      $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'number' => 0, 'orderby' => 'name', 'order' => 'ASC']);
      if (!is_wp_error($terms)) foreach ($terms as $term) {
        $term_id = (int)$term->term_id;
        if (!isset($allowed['jobpath'][$term_id])) continue;
        $parent_id = (int)$term->parent;
        if ($parent_id <= 0 || !isset($allowed['jobpath'][$parent_id])) $parent_id = 0;
        $job_paths[] = [
          'id' => $term_id,
          'value' => 'jobpath:' . $term_id,
          'label' => (string)$term->name,
          'parent_id' => $parent_id,
        ];
      }
    }

    return ['groups' => $groups, 'job_paths' => $job_paths];
  }

  private function term_path_label(int $term_id): string {
    $term = get_term($term_id, 'eum_job_path');
    if (!$term instanceof WP_Term) return '';
    $parts = [$term->name];
    foreach ((array)get_ancestors($term_id, 'eum_job_path') as $ancestor_id) {
      $ancestor = get_term((int)$ancestor_id, 'eum_job_path');
      if ($ancestor instanceof WP_Term) $parts[] = $ancestor->name;
    }
    return implode(' → ', array_reverse($parts));
  }

  private function saved_groups(int $user_id): array {
    $groups = get_user_meta($user_id, self::USER_META_GROUPS, true);
    return is_array($groups) ? $groups : [];
  }

  private function save_group(int $user_id, string $name, array $user_ids): void {
    $groups = $this->saved_groups($user_id);
    $id = wp_generate_uuid4();
    $groups[$id] = ['name' => $name, 'user_ids' => $this->normalize_ids($user_ids)];
    update_user_meta($user_id, self::USER_META_GROUPS, $groups);
  }

  private function mark_thread_notifications_read(int $user_id, int $thread_id): void {
    if (!class_exists('EUM_Notification_Center')) return;
    $notifications = get_posts([
      'post_type' => EUM_Notification_Center::CPT_NOTIFICATION, 'post_status' => 'publish', 'numberposts' => -1,
      'meta_query' => [
        ['key' => EUM_Notification_Center::META_USER_ID, 'value' => $user_id],
        ['key' => EUM_Notification_Center::META_RELATED_OBJECT_ID, 'value' => $thread_id],
        ['key' => EUM_Notification_Center::META_TYPE, 'value' => 'direct_message'],
        ['key' => EUM_Notification_Center::META_IS_READ, 'value' => '0'],
      ],
    ]);
    foreach ($notifications as $notification) {
      update_post_meta((int)$notification->ID, EUM_Notification_Center::META_IS_READ, 1);
      update_post_meta((int)$notification->ID, EUM_Notification_Center::META_READ_AT, current_time('mysql'));
    }
  }

  private function normalize_ids(array $ids): array {
    $normalized = [];
    foreach ($ids as $id) {
      if (!is_scalar($id)) continue;
      $id = absint($id);
      if ($id > 0) $normalized[] = $id;
    }
    return array_values(array_unique($normalized));
  }

  private static function messages_url(): string {
    return add_query_arg('eum_open_messages', '1', home_url('/'));
  }
}
