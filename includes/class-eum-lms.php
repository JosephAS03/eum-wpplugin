<?php
if (!defined('ABSPATH')) exit;

class EUM_LMS {
  const PAGE_SLUG = 'eum-lms';
  const CPT_COURSE = 'eum_lms_course';
  const CPT_LESSON = 'eum_lms_lesson';
  const CPT_ENROLLMENT = 'eum_lms_enrollment';

  const META_SHORT_DESCRIPTION = '_eum_lms_short_description';
  const META_OPTIONAL = '_eum_lms_optional';
  const META_ASSIGNMENTS = '_eum_lms_assignments';
  const META_DUE_VALUE = '_eum_lms_due_value';
  const META_DUE_UNIT = '_eum_lms_due_unit';
  const META_REASSIGN_MODE = '_eum_lms_reassign_mode';
  const META_REASSIGN_VALUE = '_eum_lms_reassign_value';
  const META_REASSIGN_UNIT = '_eum_lms_reassign_unit';
  const META_CERTIFICATE_ENABLED = '_eum_lms_certificate_enabled';
  const META_CERTIFICATE_TITLE = '_eum_lms_certificate_title';
  const META_CERTIFICATE_BODY = '_eum_lms_certificate_body';

  const META_LESSON_COURSE_ID = '_eum_lms_lesson_course_id';
  const META_LESSON_SORT_ORDER = '_eum_lms_lesson_sort_order';
  const META_LESSON_DOCUMENTS = '_eum_lms_lesson_documents';
  const META_LESSON_VIDEOS = '_eum_lms_lesson_videos';
  const META_LESSON_QUIZZES = '_eum_lms_lesson_quizzes';
  const META_LESSON_PASS_PERCENT = '_eum_lms_lesson_pass_percent';

  const META_ENROLLMENT_USER_ID = '_eum_lms_enrollment_user_id';
  const META_ENROLLMENT_COURSE_ID = '_eum_lms_enrollment_course_id';
  const META_ENROLLMENT_REQUIRED = '_eum_lms_enrollment_required';
  const META_ENROLLMENT_ACTIVE = '_eum_lms_enrollment_active';
  const META_ENROLLMENT_SELF_ENROLLED = '_eum_lms_enrollment_self_enrolled';
  const META_ENROLLMENT_SOURCE_LABELS = '_eum_lms_enrollment_source_labels';
  const META_ENROLLMENT_STATUS = '_eum_lms_enrollment_status';
  const META_ENROLLMENT_ASSIGNED_AT = '_eum_lms_enrollment_assigned_at';
  const META_ENROLLMENT_DUE_AT = '_eum_lms_enrollment_due_at';
  const META_ENROLLMENT_STARTED_AT = '_eum_lms_enrollment_started_at';
  const META_ENROLLMENT_COMPLETED_AT = '_eum_lms_enrollment_completed_at';
  const META_ENROLLMENT_LAST_COMPLETED_AT = '_eum_lms_enrollment_last_completed_at';
  const META_ENROLLMENT_CYCLE_COUNT = '_eum_lms_enrollment_cycle_count';
  const META_ENROLLMENT_LESSON_PROGRESS = '_eum_lms_enrollment_lesson_progress';
  const META_ENROLLMENT_CERTIFICATE_CODE = '_eum_lms_enrollment_certificate_code';
  const META_ENROLLMENT_CERTIFICATE_ISSUED_AT = '_eum_lms_enrollment_certificate_issued_at';
  const META_ENROLLMENT_LAST_CYCLE_REASON = '_eum_lms_enrollment_last_cycle_reason';

  const STATUS_ASSIGNED = 'assigned';
  const STATUS_IN_PROGRESS = 'in_progress';
  const STATUS_COMPLETED = 'completed';

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    if (is_admin()) {
      add_action('admin_menu', [$this, 'menu'], 30);
    }

    add_action('admin_post_eum_lms_course', [$this, 'handle_course_post']);
    add_action('admin_post_eum_lms_lesson', [$this, 'handle_lesson_post']);
    add_action('admin_post_eum_lms_optional_enroll', [$this, 'handle_optional_enroll']);
    add_action('admin_post_eum_lms_complete_lesson', [$this, 'handle_complete_lesson']);
    add_action('admin_post_eum_lms_complete_course', [$this, 'handle_complete_course']);

    add_shortcode('eum_lms_portal', [$this, 'shortcode_portal']);
    add_shortcode('eum_manager_lms', [$this, 'shortcode_manager']);
  }

  public function register_post_types(): void {
    register_post_type(self::CPT_COURSE, [
      'labels' => [
        'name' => 'LMS Courses',
        'singular_name' => 'LMS Course',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'editor'],
      'map_meta_cap' => true,
      'rewrite' => false,
      'query_var' => false,
    ]);

    register_post_type(self::CPT_LESSON, [
      'labels' => [
        'name' => 'LMS Lessons',
        'singular_name' => 'LMS Lesson',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'editor'],
      'map_meta_cap' => true,
      'rewrite' => false,
      'query_var' => false,
    ]);

    register_post_type(self::CPT_ENROLLMENT, [
      'labels' => [
        'name' => 'LMS Enrollments',
        'singular_name' => 'LMS Enrollment',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title'],
      'map_meta_cap' => true,
      'rewrite' => false,
      'query_var' => false,
    ]);
  }

  public function menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) return;
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'LMS', 'LMS', 'read', self::PAGE_SLUG, [$this, 'render_page']);
  }

  public function render_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');

    $course_edit_id = isset($_GET['course_edit']) ? max(0, (int)$_GET['course_edit']) : 0;
    $lesson_edit_id = isset($_GET['lesson_edit']) ? max(0, (int)$_GET['lesson_edit']) : 0;

    $course_post = $course_edit_id > 0 ? get_post($course_edit_id) : null;
    if (!$course_post || $course_post->post_type !== self::CPT_COURSE) {
      $course_edit_id = 0;
      $course_post = null;
    }

    $lesson_post = $lesson_edit_id > 0 ? get_post($lesson_edit_id) : null;
    if (!$lesson_post || $lesson_post->post_type !== self::CPT_LESSON) {
      $lesson_edit_id = 0;
      $lesson_post = null;
    }

    $notice = sanitize_text_field((string)($_GET['eum_lms_notice'] ?? ''));
    $notice_type = sanitize_key((string)($_GET['eum_lms_notice_type'] ?? 'success'));

    echo '<div class="wrap"><h1>LMS</h1>';
    echo '<p>Build required and optional learning tracks for employees, assign them by group/job structure, and track due dates, reassignments, and certificates from one place.</p>';
    echo '<p><strong>Front-end shortcodes:</strong> <code>[eum_lms_portal]</code> for the employee learning portal and <code>[eum_manager_lms]</code> for the standalone manager due/completion view. The combined manager portal also includes a <strong>Learning</strong> tab.</p>';
    if ($notice !== '') {
      $class = $notice_type === 'error' ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice) . '</p></div>';
    }

    echo '<div style="display:grid;grid-template-columns:minmax(360px,520px) minmax(360px,520px);gap:24px;align-items:start;max-width:1280px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . ($course_post ? 'Edit Course' : 'Add Course') . '</h2>';
    echo $this->render_course_form($course_post);
    echo '</div>';

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . ($lesson_post ? 'Edit Lesson' : 'Add Lesson') . '</h2>';
    echo $this->render_lesson_form($lesson_post);
    echo '</div>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:minmax(0,1fr);gap:24px;max-width:1280px;margin-top:24px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">Courses</h2>';
    echo $this->render_courses_table();
    echo '</div>';

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">Lessons</h2>';
    echo $this->render_lessons_table();
    echo '</div>';
    echo '</div>';
    echo '</div>';
  }

  private function render_course_form(?WP_Post $course_post): string {
    $course_id = $course_post ? (int)$course_post->ID : 0;
    $assignments = $course_id > 0 ? $this->course_assignments($course_id) : $this->default_assignment_rules();
    $course_content = $course_post ? (string)$course_post->post_content : '';

    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_lms_course">';
    echo '<input type="hidden" name="mode" value="' . ($course_id > 0 ? 'update' : 'create') . '">';
    echo '<input type="hidden" name="course_id" value="' . $course_id . '">';
    wp_nonce_field('eum_lms_course', 'eum_lms_nonce');

    echo '<p><label><strong>Course Name</strong></label><br><input type="text" name="course_title" value="' . esc_attr($course_post ? (string)$course_post->post_title : '') . '" class="regular-text" style="width:100%;max-width:480px;" required></p>';
    echo '<p><label><strong>Short Description</strong></label><br><input type="text" name="short_description" value="' . esc_attr($course_id > 0 ? $this->course_short_description($course_id) : '') . '" class="regular-text" style="width:100%;max-width:480px;"><br><span class="description">This appears in the course catalog and assignments list before the full course is opened.</span></p>';
    echo '<p><label><strong>Course Overview</strong></label></p>';
    wp_editor($course_content, 'eum_lms_course_content', [
      'textarea_name' => 'course_content',
      'textarea_rows' => 8,
      'media_buttons' => true,
      'teeny' => false,
    ]);

    echo '<h3 style="margin:18px 0 8px;">Assignments</h3>';
    echo '<p class="description">A course becomes required when a user matches any selected audience below. Optional courses can still be discovered by everyone in the optional catalog.</p>';
    echo '<p><label><input type="checkbox" name="optional_enabled" value="1" ' . checked($course_id > 0 ? $this->course_is_optional($course_id) : false, true, false) . '> Show in optional course catalog</label></p>';
    echo '<p><label><strong>Direct Users</strong></label><br><textarea name="assignment_users_raw" rows="4" style="width:100%;max-width:480px;">' . esc_textarea($this->format_direct_user_assignments($assignments['user_ids'] ?? [])) . '</textarea><br><span class="description">Enter user IDs, usernames, or emails separated by commas or new lines.</span></p>';
    echo '<p><label><strong>Job Paths</strong></label><br>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_checkboxes('assignment_job_paths', $assignments['job_paths'] ?? [], get_current_user_id(), ['enforce_manageable_job_paths' => false]) : $this->render_checkboxes('assignment_job_paths', $this->job_path_options(), $assignments['job_paths'] ?? [], 'No job paths found.')) . '</p>';
    echo '<p><label><strong>Groups</strong></label><br>' . $this->render_checkboxes('assignment_groups', $this->taxonomy_options('eum_user_group'), $assignments['groups'] ?? [], 'No groups found.') . '</p>';
    echo '<p><label><strong>Locations</strong></label><br>' . $this->render_checkboxes('assignment_locations', $this->taxonomy_options('eum_location'), $assignments['locations'] ?? [], 'No locations found.') . '</p>';
    echo '<p><label><strong>Projects</strong></label><br>' . $this->render_checkboxes('assignment_projects', $this->taxonomy_options('eum_project'), $assignments['projects'] ?? [], 'No projects found.') . '</p>';
    echo '<p><label><strong>Job Titles / Specifications</strong></label><br>' . $this->render_checkboxes('assignment_job_titles', $this->job_title_options(), $assignments['job_titles'] ?? [], 'No job titles found.') . '</p>';

    echo '<h3 style="margin:18px 0 8px;">Due Dates & Reassignments</h3>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">';
    echo '<p><label><strong>Due In</strong></label><br><input type="number" min="0" name="due_value" value="' . esc_attr((string)($course_id > 0 ? $this->course_due_value($course_id) : 30)) . '" class="small-text"></p>';
    echo '<p><label><strong>Due Unit</strong></label><br><select name="due_unit">' . $this->select_options($this->interval_unit_options(), $course_id > 0 ? $this->course_due_unit($course_id) : 'days') . '</select></p>';
    echo '<p><label><strong>Reassignment Rule</strong></label><br><select name="reassign_mode">' . $this->select_options($this->reassignment_mode_options(), $course_id > 0 ? $this->course_reassignment_mode($course_id) : 'none') . '</select></p>';
    echo '<p><label><strong>Reassign Every</strong></label><br><input type="number" min="0" name="reassign_value" value="' . esc_attr((string)($course_id > 0 ? $this->course_reassignment_value($course_id) : 0)) . '" class="small-text"></p>';
    echo '<p><label><strong>Reassign Unit</strong></label><br><select name="reassign_unit">' . $this->select_options($this->interval_unit_options(), $course_id > 0 ? $this->course_reassignment_unit($course_id) : 'days') . '</select></p>';
    echo '</div>';
    echo '<p class="description">Use <strong>After Completion Interval</strong> for "reassign X days/weeks/months after the user finishes." Use <strong>Rolling Interval</strong> for "every X days/weeks/months regardless of completion." Use hire/rehire options for workforce lifecycle-based retraining.</p>';

    echo '<h3 style="margin:18px 0 8px;">Certificate</h3>';
    echo '<p><label><input type="checkbox" name="certificate_enabled" value="1" ' . checked($course_id > 0 ? $this->course_certificate_enabled($course_id) : false, true, false) . '> Issue certificate on completion</label></p>';
    echo '<p><label><strong>Certificate Title</strong></label><br><input type="text" name="certificate_title" value="' . esc_attr($course_id > 0 ? $this->course_certificate_title($course_id) : 'Certificate of Completion') . '" class="regular-text" style="width:100%;max-width:480px;"></p>';
    echo '<p><label><strong>Certificate Body</strong></label><br><textarea name="certificate_body" rows="4" style="width:100%;max-width:480px;">' . esc_textarea($course_id > 0 ? $this->course_certificate_body($course_id) : 'This certifies that {{user_name}} successfully completed {{course_title}} on {{completed_at}}.') . '</textarea><br><span class="description">Available placeholders: <code>{{user_name}}</code>, <code>{{course_title}}</code>, <code>{{completed_at}}</code>, <code>{{certificate_code}}</code>.</span></p>';

    echo '<p>';
    echo '<button type="submit" class="button button-primary">' . ($course_id > 0 ? 'Update Course' : 'Create Course') . '</button> ';
    if ($course_id > 0) {
      echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">Cancel</a> ';
      echo '<button type="submit" name="mode" value="delete" class="button button-link-delete" onclick="return confirm(\'Delete this course?\');">Delete</button>';
    }
    echo '</p>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  private function render_lesson_form(?WP_Post $lesson_post): string {
    $lesson_id = $lesson_post ? (int)$lesson_post->ID : 0;
    $lesson_content = $lesson_post ? (string)$lesson_post->post_content : '';
    $documents = $lesson_id > 0 ? $this->lesson_documents($lesson_id) : [];
    $videos = $lesson_id > 0 ? $this->lesson_videos($lesson_id) : [];
    $quizzes = $lesson_id > 0 ? $this->lesson_quizzes($lesson_id) : [];

    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_lms_lesson">';
    echo '<input type="hidden" name="mode" value="' . ($lesson_id > 0 ? 'update' : 'create') . '">';
    echo '<input type="hidden" name="lesson_id" value="' . $lesson_id . '">';
    wp_nonce_field('eum_lms_lesson', 'eum_lms_nonce');

    echo '<p><label><strong>Lesson Name</strong></label><br><input type="text" name="lesson_title" value="' . esc_attr($lesson_post ? (string)$lesson_post->post_title : '') . '" class="regular-text" style="width:100%;max-width:480px;" required></p>';
    echo '<p><label><strong>Parent Course</strong></label><br><select name="lesson_course_id" style="min-width:320px;">';
    echo '<option value="">Select Course</option>';
    echo $this->select_options($this->course_options(), (string)($lesson_id > 0 ? $this->lesson_course_id($lesson_id) : ''));
    echo '</select></p>';
    echo '<p><label><strong>Sort Order</strong></label><br><input type="number" min="0" name="lesson_sort_order" value="' . esc_attr((string)($lesson_id > 0 ? $this->lesson_sort_order($lesson_id) : 0)) . '" class="small-text"></p>';
    echo '<p><label><strong>Lesson Content</strong></label></p>';
    wp_editor($lesson_content, 'eum_lms_lesson_content', [
      'textarea_name' => 'lesson_content',
      'textarea_rows' => 7,
      'media_buttons' => true,
      'teeny' => false,
    ]);
    echo '<p><label><strong>Documents</strong></label><br><textarea name="lesson_documents_raw" rows="5" style="width:100%;max-width:480px;">' . esc_textarea($this->format_link_rows_for_textarea($documents)) . '</textarea><br><span class="description">One per line: <code>Label | https://example.com/file.pdf</code></span></p>';
    echo '<p><label><strong>Videos</strong></label><br><textarea name="lesson_videos_raw" rows="5" style="width:100%;max-width:480px;">' . esc_textarea($this->format_link_rows_for_textarea($videos)) . '</textarea><br><span class="description">One per line: <code>Label | https://example.com/video</code>. OEmbed links will embed automatically when possible.</span></p>';
    echo '<p><label><strong>Quiz / Test Questions</strong></label><br><textarea name="lesson_quizzes_raw" rows="6" style="width:100%;max-width:480px;">' . esc_textarea($this->format_quizzes_for_textarea($quizzes)) . '</textarea><br><span class="description">One question per line. Format: <code>Question | *Correct Choice | Choice 2 | Choice 3</code>. Prefix the correct choice with <code>*</code>.</span></p>';
    echo '<p><label><strong>Passing Score %</strong></label><br><input type="number" min="1" max="100" name="lesson_pass_percent" value="' . esc_attr((string)($lesson_id > 0 ? $this->lesson_pass_percent($lesson_id) : 100)) . '" class="small-text"></p>';

    echo '<p>';
    echo '<button type="submit" class="button button-primary">' . ($lesson_id > 0 ? 'Update Lesson' : 'Create Lesson') . '</button> ';
    if ($lesson_id > 0) {
      echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">Cancel</a> ';
      echo '<button type="submit" name="mode" value="delete" class="button button-link-delete" onclick="return confirm(\'Delete this lesson?\');">Delete</button>';
    }
    echo '</p>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  public function handle_course_post(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    check_admin_referer('eum_lms_course', 'eum_lms_nonce');

    $mode = sanitize_key((string)($_POST['mode'] ?? 'create'));
    $course_id = max(0, (int)($_POST['course_id'] ?? 0));
    $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG);

    if ($mode === 'delete') {
      if ($course_id <= 0 || get_post_type($course_id) !== self::CPT_COURSE) {
        $this->redirect_admin($redirect, 'Course not found.', 'error');
      }
      $this->delete_course_related_records($course_id);
      wp_delete_post($course_id, true);
      $this->redirect_admin($redirect, 'Course deleted.');
    }

    $title = sanitize_text_field((string)wp_unslash($_POST['course_title'] ?? ''));
    if ($title === '') {
      $this->redirect_admin($redirect, 'Course name is required.', 'error', ['course_edit' => $course_id]);
    }

    $postarr = [
      'post_type' => self::CPT_COURSE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_content' => wp_kses_post((string)wp_unslash($_POST['course_content'] ?? '')),
    ];
    if ($mode === 'update' && $course_id > 0) $postarr['ID'] = $course_id;
    $saved_id = $mode === 'update' && $course_id > 0 ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
    if (is_wp_error($saved_id) || (int)$saved_id <= 0) {
      $this->redirect_admin($redirect, 'Course could not be saved.', 'error', ['course_edit' => $course_id]);
    }
    $course_id = (int)$saved_id;

    update_post_meta($course_id, self::META_SHORT_DESCRIPTION, sanitize_text_field((string)wp_unslash($_POST['short_description'] ?? '')));
    update_post_meta($course_id, self::META_OPTIONAL, !empty($_POST['optional_enabled']) ? 1 : 0);
    update_post_meta($course_id, self::META_ASSIGNMENTS, $this->normalize_assignment_rules_from_post($_POST));
    update_post_meta($course_id, self::META_DUE_VALUE, max(0, (int)($_POST['due_value'] ?? 0)));
    update_post_meta($course_id, self::META_DUE_UNIT, $this->normalize_interval_unit((string)($_POST['due_unit'] ?? 'days')));
    update_post_meta($course_id, self::META_REASSIGN_MODE, $this->normalize_reassignment_mode((string)($_POST['reassign_mode'] ?? 'none')));
    update_post_meta($course_id, self::META_REASSIGN_VALUE, max(0, (int)($_POST['reassign_value'] ?? 0)));
    update_post_meta($course_id, self::META_REASSIGN_UNIT, $this->normalize_interval_unit((string)($_POST['reassign_unit'] ?? 'days')));
    update_post_meta($course_id, self::META_CERTIFICATE_ENABLED, !empty($_POST['certificate_enabled']) ? 1 : 0);
    update_post_meta($course_id, self::META_CERTIFICATE_TITLE, sanitize_text_field((string)wp_unslash($_POST['certificate_title'] ?? 'Certificate of Completion')));
    update_post_meta($course_id, self::META_CERTIFICATE_BODY, sanitize_textarea_field((string)wp_unslash($_POST['certificate_body'] ?? '')));

    $this->redirect_admin($redirect, $mode === 'update' ? 'Course updated.' : 'Course created.');
  }

  public function handle_lesson_post(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    check_admin_referer('eum_lms_lesson', 'eum_lms_nonce');

    $mode = sanitize_key((string)($_POST['mode'] ?? 'create'));
    $lesson_id = max(0, (int)($_POST['lesson_id'] ?? 0));
    $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG);

    if ($mode === 'delete') {
      if ($lesson_id <= 0 || get_post_type($lesson_id) !== self::CPT_LESSON) {
        $this->redirect_admin($redirect, 'Lesson not found.', 'error');
      }
      wp_delete_post($lesson_id, true);
      $this->redirect_admin($redirect, 'Lesson deleted.');
    }

    $title = sanitize_text_field((string)wp_unslash($_POST['lesson_title'] ?? ''));
    $course_id = max(0, (int)($_POST['lesson_course_id'] ?? 0));
    if ($title === '') {
      $this->redirect_admin($redirect, 'Lesson name is required.', 'error', ['lesson_edit' => $lesson_id]);
    }
    if ($course_id <= 0 || get_post_type($course_id) !== self::CPT_COURSE) {
      $this->redirect_admin($redirect, 'Select a valid course for this lesson.', 'error', ['lesson_edit' => $lesson_id]);
    }

    $postarr = [
      'post_type' => self::CPT_LESSON,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_content' => wp_kses_post((string)wp_unslash($_POST['lesson_content'] ?? '')),
    ];
    if ($mode === 'update' && $lesson_id > 0) $postarr['ID'] = $lesson_id;
    $saved_id = $mode === 'update' && $lesson_id > 0 ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
    if (is_wp_error($saved_id) || (int)$saved_id <= 0) {
      $this->redirect_admin($redirect, 'Lesson could not be saved.', 'error', ['lesson_edit' => $lesson_id]);
    }
    $lesson_id = (int)$saved_id;

    update_post_meta($lesson_id, self::META_LESSON_COURSE_ID, $course_id);
    update_post_meta($lesson_id, self::META_LESSON_SORT_ORDER, max(0, (int)($_POST['lesson_sort_order'] ?? 0)));
    update_post_meta($lesson_id, self::META_LESSON_DOCUMENTS, $this->normalize_link_rows_from_textarea((string)wp_unslash($_POST['lesson_documents_raw'] ?? '')));
    update_post_meta($lesson_id, self::META_LESSON_VIDEOS, $this->normalize_link_rows_from_textarea((string)wp_unslash($_POST['lesson_videos_raw'] ?? '')));
    update_post_meta($lesson_id, self::META_LESSON_QUIZZES, $this->normalize_quiz_rows_from_textarea((string)wp_unslash($_POST['lesson_quizzes_raw'] ?? '')));
    update_post_meta($lesson_id, self::META_LESSON_PASS_PERCENT, min(100, max(1, (int)($_POST['lesson_pass_percent'] ?? 100))));

    $this->redirect_admin($redirect, $mode === 'update' ? 'Lesson updated.' : 'Lesson created.');
  }

  public function handle_optional_enroll(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    check_admin_referer('eum_lms_optional_enroll', 'eum_lms_nonce');

    $course_id = max(0, (int)($_POST['course_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $user_id = (int)get_current_user_id();
    if ($course_id <= 0 || get_post_type($course_id) !== self::CPT_COURSE || !$this->course_is_optional($course_id)) {
      $this->redirect_portal($return_url, 'Optional course not found.', 'error');
    }

    $this->sync_user_enrollments($user_id);
    $enrollment = $this->find_enrollment($user_id, $course_id);
    if ($enrollment) {
      update_post_meta($enrollment->ID, self::META_ENROLLMENT_ACTIVE, 1);
      update_post_meta($enrollment->ID, self::META_ENROLLMENT_SELF_ENROLLED, 1);
      if ((int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true) <= 0) {
        update_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, $course_id);
      }
    } else {
      $enrollment_id = $this->create_enrollment($user_id, $course_id, [
        'required' => false,
        'active' => true,
        'self_enrolled' => true,
        'source_labels' => ['Optional course catalog'],
        'anchor_at' => current_time('mysql'),
        'cycle_reason' => 'Optional self-enrollment',
      ]);
      if ($enrollment_id <= 0) {
        $this->redirect_portal($return_url, 'Could not enroll in that course.', 'error');
      }
    }

    $this->redirect_portal(add_query_arg(['eum_lms_course' => $course_id], $return_url), 'Course added to your learning plan.');
  }

  public function handle_complete_lesson(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    check_admin_referer('eum_lms_complete_lesson', 'eum_lms_nonce');

    $lesson_id = max(0, (int)($_POST['lesson_id'] ?? 0));
    $course_id = max(0, (int)($_POST['course_id'] ?? 0));
    $enrollment_id = max(0, (int)($_POST['enrollment_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $user_id = (int)get_current_user_id();

    $enrollment = $enrollment_id > 0 ? get_post($enrollment_id) : null;
    if (!$enrollment || $enrollment->post_type !== self::CPT_ENROLLMENT) {
      $this->redirect_portal($return_url, 'Enrollment not found.', 'error');
    }
    if ((int)get_post_meta($enrollment_id, self::META_ENROLLMENT_USER_ID, true) !== $user_id) {
      $this->redirect_portal($return_url, 'You do not have access to that lesson.', 'error');
    }
    if ($lesson_id <= 0 || get_post_type($lesson_id) !== self::CPT_LESSON || $course_id <= 0 || (int)$this->lesson_course_id($lesson_id) !== $course_id) {
      $this->redirect_portal($return_url, 'Lesson not found.', 'error');
    }

    $quiz_questions = $this->lesson_quizzes($lesson_id);
    $required_percent = $this->lesson_pass_percent($lesson_id);
    $correct = 0;
    $total = count($quiz_questions);

    if ($total > 0) {
      $answers = isset($_POST['quiz_answers']) && is_array($_POST['quiz_answers']) ? $_POST['quiz_answers'] : [];
      foreach ($quiz_questions as $index => $question) {
        $submitted = isset($answers[$index]) ? (int)$answers[$index] : -1;
        if ($submitted === (int)($question['correct_index'] ?? -1)) $correct++;
      }
      $percent = $total > 0 ? (int)round(($correct / $total) * 100) : 0;
      if ($percent < $required_percent) {
        $this->redirect_portal($return_url, 'Quiz score was ' . $percent . '%. Required score is ' . $required_percent . '%.', 'error');
      }
    }

    $progress = $this->enrollment_lesson_progress($enrollment_id);
    $progress[$lesson_id] = [
      'completed_at' => current_time('mysql'),
      'quiz_total' => $total,
      'quiz_correct' => $correct,
    ];
    update_post_meta($enrollment_id, self::META_ENROLLMENT_LESSON_PROGRESS, $progress);

    if (sanitize_key((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_STATUS, true)) === self::STATUS_ASSIGNED) {
      update_post_meta($enrollment_id, self::META_ENROLLMENT_STATUS, self::STATUS_IN_PROGRESS);
      update_post_meta($enrollment_id, self::META_ENROLLMENT_STARTED_AT, current_time('mysql'));
    } elseif (sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_STARTED_AT, true)) === '') {
      update_post_meta($enrollment_id, self::META_ENROLLMENT_STARTED_AT, current_time('mysql'));
    }

    if ($this->course_is_complete_for_enrollment($course_id, $enrollment_id)) {
      $this->complete_enrollment($enrollment_id, $course_id, $user_id);
      $this->redirect_portal(add_query_arg(['eum_lms_course' => $course_id], $return_url), 'Course completed.');
    }

    $this->redirect_portal(add_query_arg(['eum_lms_course' => $course_id], $return_url), 'Lesson completed.');
  }

  public function handle_complete_course(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    check_admin_referer('eum_lms_complete_course', 'eum_lms_nonce');

    $course_id = max(0, (int)($_POST['course_id'] ?? 0));
    $enrollment_id = max(0, (int)($_POST['enrollment_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $user_id = (int)get_current_user_id();

    $enrollment = $enrollment_id > 0 ? get_post($enrollment_id) : null;
    if (!$enrollment || $enrollment->post_type !== self::CPT_ENROLLMENT) {
      $this->redirect_portal($return_url, 'Enrollment not found.', 'error');
    }
    if ((int)get_post_meta($enrollment_id, self::META_ENROLLMENT_USER_ID, true) !== $user_id) {
      $this->redirect_portal($return_url, 'You do not have access to that course.', 'error');
    }
    if ($course_id <= 0 || (int)get_post_meta($enrollment_id, self::META_ENROLLMENT_COURSE_ID, true) !== $course_id || get_post_type($course_id) !== self::CPT_COURSE) {
      $this->redirect_portal($return_url, 'Course not found.', 'error');
    }
    if (!$this->course_is_complete_for_enrollment($course_id, $enrollment_id)) {
      $this->redirect_portal($return_url, 'Complete each lesson before marking the course complete.', 'error');
    }
    if (sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_STARTED_AT, true)) === '') {
      update_post_meta($enrollment_id, self::META_ENROLLMENT_STARTED_AT, current_time('mysql'));
    }
    $this->complete_enrollment($enrollment_id, $course_id, $user_id);
    $this->redirect_portal(add_query_arg(['eum_lms_course' => $course_id], $return_url), 'Course completed.');
  }

  public function shortcode_portal($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-lms"><p>Please sign in to access learning.</p></div>';

    $user_id = (int)get_current_user_id();
    $this->sync_user_enrollments($user_id);

    $enrollments = $this->user_enrollments($user_id, true);
    $selected_course_id = isset($_GET['eum_lms_course']) ? max(0, (int)$_GET['eum_lms_course']) : 0;
    $selected_enrollment = $selected_course_id > 0 ? $this->find_enrollment($user_id, $selected_course_id) : null;
    $required = [];
    $my_optional = [];

    foreach ($enrollments as $enrollment) {
      if (!($enrollment instanceof WP_Post)) continue;
      $course_id = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true);
      if ($course_id <= 0 || get_post_type($course_id) !== self::CPT_COURSE) continue;
      if ((int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_REQUIRED, true) === 1) $required[] = $enrollment;
      else $my_optional[] = $enrollment;
      if ($selected_course_id === 0) $selected_course_id = $course_id;
    }

    if ((!$selected_enrollment || !$selected_enrollment instanceof WP_Post) && $selected_course_id > 0) {
      $selected_enrollment = $this->find_enrollment($user_id, $selected_course_id);
    }

    $optional_catalog = $this->optional_catalog_courses_for_user($user_id);
    $selected_course = $selected_enrollment ? get_post((int)get_post_meta($selected_enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true)) : null;
    if (!$selected_course && !empty($required)) {
      $selected_enrollment = $required[0];
      $selected_course = get_post((int)get_post_meta($selected_enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true));
    } elseif (!$selected_course && !empty($my_optional)) {
      $selected_enrollment = $my_optional[0];
      $selected_course = get_post((int)get_post_meta($selected_enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true));
    }

    $notice = sanitize_text_field((string)($_GET['eum_lms_notice'] ?? ''));
    $notice_type = sanitize_key((string)($_GET['eum_lms_notice_type'] ?? 'success'));
    $current_url = $this->current_url();

    if ($selected_course instanceof WP_Post && !empty($_GET['eum_lms_certificate']) && $selected_enrollment instanceof WP_Post && $this->enrollment_is_completed($selected_enrollment->ID)) {
      return $this->render_certificate($selected_course, $selected_enrollment, $user_id);
    }

    $stats = [
      'required' => count($required),
      'overdue' => 0,
      'completed' => 0,
      'optional' => count($optional_catalog) + count($my_optional),
    ];
    foreach (array_merge($required, $my_optional) as $enrollment) {
      if (!($enrollment instanceof WP_Post)) continue;
      $status = $this->effective_enrollment_status($enrollment->ID);
      if ($status === self::STATUS_COMPLETED) $stats['completed']++;
      if ($status === 'overdue') $stats['overdue']++;
    }

    ob_start();
    echo '<div class="eum-lms" style="max-width:1280px;margin:0 auto;color:#111827;">';
    if ($notice !== '') {
      $styles = $notice_type === 'error'
        ? 'margin:0 0 18px;padding:14px 16px;border-radius:10px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;'
        : 'margin:0 0 18px;padding:14px 16px;border-radius:10px;border:1px solid #86efac;background:#f0fdf4;color:#166534;';
      echo '<div style="' . esc_attr($styles) . '">' . esc_html($notice) . '</div>';
    }

    echo '<div style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:24px;box-shadow:0 14px 34px rgba(15,23,42,.05);margin:0 0 18px;background:linear-gradient(180deg,#f8fbff 0%,#ffffff 100%);">';
    echo '<p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd;">Learning Management</p>';
    echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><h2 style="margin:0;font-size:32px;line-height:1.1;">Learning Portal</h2>';
    echo '<p style="margin:12px 0 0;max-width:760px;font-size:16px;line-height:1.65;color:#475569;">Assigned courses stay visible with due dates and recurring training logic. Optional courses can be self-added from the catalog whenever you want to learn beyond your required path.</p></div>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
    echo $this->summary_chip('Required', (string)$stats['required'], '#eff6ff', '#1d4ed8');
    echo $this->summary_chip('Overdue', (string)$stats['overdue'], '#fef2f2', '#b91c1c');
    echo $this->summary_chip('Completed', (string)$stats['completed'], '#ecfdf5', '#166534');
    echo $this->summary_chip('Optional', (string)$stats['optional'], '#f8fafc', '#334155');
    echo '</div></div></div>';

    echo '<div style="display:grid;grid-template-columns:minmax(320px,420px) minmax(0,1fr);gap:18px;align-items:start;">';
    echo '<aside style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,.05);">';
    echo '<h3 style="margin:0 0 12px;">Required Courses</h3>';
    if (empty($required)) {
      echo '<div style="padding:14px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;">No required courses are currently assigned.</div>';
    } else {
      echo '<div style="display:grid;gap:12px;">';
      foreach ($required as $enrollment) {
        if (!($enrollment instanceof WP_Post)) continue;
        echo $this->render_course_card($enrollment, $current_url, $selected_course ? (int)$selected_course->ID : 0, true);
      }
      echo '</div>';
    }

    echo '<h3 style="margin:18px 0 12px;">My Optional Courses</h3>';
    if (empty($my_optional)) {
      echo '<div style="padding:14px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;">You have not added any optional courses yet.</div>';
    } else {
      echo '<div style="display:grid;gap:12px;">';
      foreach ($my_optional as $enrollment) {
        if (!($enrollment instanceof WP_Post)) continue;
        echo $this->render_course_card($enrollment, $current_url, $selected_course ? (int)$selected_course->ID : 0, false);
      }
      echo '</div>';
    }

    echo '<h3 style="margin:18px 0 12px;">Optional Catalog</h3>';
    if (empty($optional_catalog)) {
      echo '<div style="padding:14px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;">No optional courses are available right now.</div>';
    } else {
      echo '<div style="display:grid;gap:12px;">';
      foreach ($optional_catalog as $course) {
        echo $this->render_optional_catalog_card($course, $current_url);
      }
      echo '</div>';
    }
    echo '</aside>';

    echo '<section style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:22px;box-shadow:0 14px 34px rgba(15,23,42,.05);min-height:640px;">';
    if (!$selected_course || !$selected_enrollment) {
      echo '<div style="padding:18px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#475569;">Select a course to review lessons, complete quizzes, and download certificates.</div>';
    } else {
      echo $this->render_course_workspace($selected_course, $selected_enrollment, $user_id, $current_url);
    }
    echo '</section>';
    echo '</div></div>';
    return (string)ob_get_clean();
  }

  public function dashboard_panel(int $user_id, array $args = []): string {
    if ($user_id <= 0) return '';

    $args = wp_parse_args($args, [
      'required_limit' => 4,
      'catalog_limit' => 3,
    ]);

    $required_limit = max(1, (int)$args['required_limit']);
    $catalog_limit = max(1, (int)$args['catalog_limit']);

    $this->sync_user_enrollments($user_id);
    $enrollments = $this->user_enrollments($user_id, true);
    $required = [];
    $optional = [];
    foreach ($enrollments as $enrollment) {
      if (!($enrollment instanceof WP_Post)) continue;
      $course_id = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true);
      if ($course_id <= 0 || get_post_type($course_id) !== self::CPT_COURSE) continue;
      if ((int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_REQUIRED, true) === 1) {
        $required[] = $enrollment;
      } else {
        $optional[] = $enrollment;
      }
    }

    $optional_catalog = array_slice($this->optional_catalog_courses_for_user($user_id), 0, $catalog_limit);
    $current_url = $this->current_url();
    $overdue = 0;
    foreach ($required as $enrollment) {
      if ($enrollment instanceof WP_Post && $this->effective_enrollment_status($enrollment->ID) === 'overdue') $overdue++;
    }

    ob_start();
    echo '<div class="eum-lms-dashboard-panel">';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">';
    echo $this->summary_chip('Required', (string)count($required), '#eff6ff', '#1d4ed8');
    echo $this->summary_chip('Overdue', (string)$overdue, '#fef2f2', '#b91c1c');
    echo $this->summary_chip('Optional', (string)(count($optional) + count($optional_catalog)), '#f8fafc', '#334155');
    echo '</div>';

    echo '<div style="display:grid;gap:12px;">';
    echo '<div>';
    echo '<strong style="display:block;margin-bottom:8px;color:#0f172a;">Required Courses</strong>';
    if (empty($required)) {
      echo '<p style="margin:0;color:#64748b;">No required learning is assigned right now.</p>';
    } else {
      foreach (array_slice($required, 0, $required_limit) as $enrollment) {
        if (!($enrollment instanceof WP_Post)) continue;
        $course_id = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true);
        $course = $course_id > 0 ? get_post($course_id) : null;
        if (!$course || $course->post_type !== self::CPT_COURSE) continue;
        $due_at = sanitize_text_field((string)get_post_meta($enrollment->ID, self::META_ENROLLMENT_DUE_AT, true));
        echo '<div style="padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;margin:0 0 8px;">';
        echo '<strong style="display:block;">' . esc_html((string)$course->post_title) . '</strong>';
        echo '<span style="display:block;margin-top:4px;color:#475569;">' . esc_html($this->course_progress_summary($course_id, (int)$enrollment->ID) . ' | ' . $this->enrollment_status_label((int)$enrollment->ID)) . '</span>';
        if ($due_at !== '') {
          echo '<span style="display:block;margin-top:4px;color:#94a3b8;font-size:12px;">Due ' . esc_html($this->human_datetime($due_at)) . '</span>';
        }
        echo '</div>';
      }
    }
    echo '</div>';

    echo '<div>';
    echo '<strong style="display:block;margin-bottom:8px;color:#0f172a;">Optional Course Catalog</strong>';
    if (empty($optional_catalog)) {
      echo '<p style="margin:0;color:#64748b;">No optional courses are available right now.</p>';
    } else {
      foreach ($optional_catalog as $course) {
        $course_id = (int)$course->ID;
        echo '<div style="padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;margin:0 0 8px;">';
        echo '<strong style="display:block;">' . esc_html((string)$course->post_title) . '</strong>';
        if ($this->course_short_description($course_id) !== '') {
          echo '<span style="display:block;margin-top:4px;color:#475569;">' . esc_html($this->course_short_description($course_id)) . '</span>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="eum_lms_optional_enroll">';
        echo '<input type="hidden" name="course_id" value="' . $course_id . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr($current_url) . '">';
        wp_nonce_field('eum_lms_optional_enroll', 'eum_lms_nonce');
        echo '<button class="button button-secondary" type="submit">Add Course</button>';
        echo '</form>';
        echo '</div>';
      }
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  public function shortcode_manager($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-manager-lms"><p>Please sign in to access manager learning status.</p></div>';
    $actor_user_id = (int)get_current_user_id();
    if (!(class_exists('EUM_Access') && EUM_Access::is_manager_user($actor_user_id))) {
      return '<div class="eum-manager-lms"><p>You do not have access to manager learning data.</p></div>';
    }

    $atts = shortcode_atts([
      'include_indirect' => '1',
      'limit' => '250',
    ], (array)$atts, 'eum_manager_lms');

    $include_indirect = $this->truthy($atts['include_indirect']);
    $limit = max(20, min(500, (int)$atts['limit']));
    $q = sanitize_text_field((string)($_GET['eum_lms_q'] ?? ''));
    $status_filter = sanitize_key((string)($_GET['eum_lms_status'] ?? 'all'));
    if (!in_array($status_filter, ['all', 'open', 'overdue', 'due_soon', 'completed'], true)) $status_filter = 'all';

    $rows = [];
    foreach ($this->managed_user_ids($actor_user_id, $include_indirect) as $user_id) {
      if ($user_id <= 0) continue;
      $this->sync_user_enrollments($user_id);
      foreach ($this->user_enrollments($user_id, true) as $enrollment) {
        if (!($enrollment instanceof WP_Post)) continue;
        if ((int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_REQUIRED, true) !== 1) continue;
        $course_id = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true);
        $course = $course_id > 0 ? get_post($course_id) : null;
        if (!$course || $course->post_type !== self::CPT_COURSE) continue;
        $effective_status = $this->effective_enrollment_status($enrollment->ID);
        $due_at = sanitize_text_field((string)get_post_meta($enrollment->ID, self::META_ENROLLMENT_DUE_AT, true));
        $completed_at = sanitize_text_field((string)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COMPLETED_AT, true));
        if ($status_filter === 'open' && !in_array($effective_status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS], true)) continue;
        if ($status_filter === 'overdue' && $effective_status !== 'overdue') continue;
        if ($status_filter === 'due_soon' && !$this->enrollment_due_soon($enrollment->ID)) continue;
        if ($status_filter === 'completed' && $effective_status !== self::STATUS_COMPLETED) continue;

        $user = get_user_by('id', $user_id);
        if (!$user) continue;
        $job_path = $this->user_term_path($user_id, 'eum_job_path');
        $course_title = sanitize_text_field((string)$course->post_title);
        $status_label = $this->enrollment_status_label($enrollment->ID);
        $hay = strtolower(trim($user->display_name . ' ' . $user->user_email . ' ' . $course_title . ' ' . $job_path . ' ' . $status_label));
        if ($q !== '' && strpos($hay, strtolower($q)) === false) continue;

        $rows[] = [
          'user_id' => $user_id,
          'user_name' => trim((string)$user->display_name) !== '' ? (string)$user->display_name : (string)$user->user_login,
          'user_email' => (string)$user->user_email,
          'job_path' => $job_path,
          'course_title' => $course_title,
          'status' => $status_label,
          'due_at' => $due_at,
          'completed_at' => $completed_at,
          'certificate' => $this->course_certificate_enabled($course_id) && $completed_at !== '' ? 'Issued' : '-',
          'progress' => $this->course_progress_summary($course_id, $enrollment->ID),
        ];
        if (count($rows) >= $limit) break 2;
      }
    }

    usort($rows, function($a, $b) {
      $a_due = $a['due_at'] !== '' ? strtotime((string)$a['due_at']) : PHP_INT_MAX;
      $b_due = $b['due_at'] !== '' ? strtotime((string)$b['due_at']) : PHP_INT_MAX;
      if ($a_due !== $b_due) return $a_due <=> $b_due;
      return strcasecmp((string)$a['user_name'], (string)$b['user_name']);
    });

    ob_start();
    echo '<div class="eum-manager-lms" style="max-width:1280px;margin:0 auto;">';
    echo '<div style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:24px;box-shadow:0 14px 34px rgba(15,23,42,.05);margin:0 0 18px;background:linear-gradient(180deg,#f8fbff 0%,#ffffff 100%);">';
    echo '<p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd;">Manager Learning</p>';
    echo '<h2 style="margin:0;font-size:32px;line-height:1.1;">Training Due & Completion</h2>';
    echo '<p style="margin:12px 0 0;max-width:760px;font-size:16px;line-height:1.65;color:#475569;">Track required learning across your team, see what is overdue, and confirm which employees have completed certificate-bearing courses.</p>';
    echo '</div>';

    echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">';
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['eum_lms_q', 'eum_lms_status'], true)) continue;
      if (is_scalar($value)) echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">';
    }
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Search</label><input type="search" name="eum_lms_q" value="' . esc_attr($q) . '" style="min-width:280px;"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Status</label><select name="eum_lms_status">' . $this->select_options([
      'all' => 'All',
      'open' => 'Open',
      'overdue' => 'Overdue',
      'due_soon' => 'Due Soon',
      'completed' => 'Completed',
    ], $status_filter) . '</select></p>';
    echo '<p style="margin:0;"><button class="button button-secondary" type="submit">Filter</button></p>';
    echo '</form>';

    echo '<div style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,.05);">';
    if (empty($rows)) {
      echo '<div style="padding:14px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;">No learning records matched the current filter.</div>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th>User</th><th>Course</th><th>Progress</th><th>Status</th><th>Due</th><th>Completed</th><th>Certificate</th><th>Job Path</th></tr></thead><tbody>';
      foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><strong>' . esc_html((string)$row['user_name']) . '</strong><br><span class="description">' . esc_html((string)$row['user_email']) . '</span></td>';
        echo '<td>' . esc_html((string)$row['course_title']) . '</td>';
        echo '<td>' . esc_html((string)$row['progress']) . '</td>';
        echo '<td>' . esc_html((string)$row['status']) . '</td>';
        echo '<td>' . esc_html($row['due_at'] !== '' ? $this->human_datetime((string)$row['due_at']) : '-') . '</td>';
        echo '<td>' . esc_html($row['completed_at'] !== '' ? $this->human_datetime((string)$row['completed_at']) : '-') . '</td>';
        echo '<td>' . esc_html((string)$row['certificate']) . '</td>';
        echo '<td>' . esc_html((string)$row['job_path']) . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_courses_table(): string {
    $courses = $this->admin_course_posts();
    if (empty($courses)) return '<p class="description">No courses created yet.</p>';

    ob_start();
    echo '<table class="widefat striped"><thead><tr><th>Course</th><th>Lessons</th><th>Assignments</th><th>Due / Reassign</th><th>Optional</th><th>Certificate</th><th></th></tr></thead><tbody>';
    foreach ($courses as $course) {
      $course_id = (int)$course->ID;
      $summary = $this->course_assignment_summary($course_id);
      $due = $this->course_due_value($course_id) . ' ' . $this->interval_unit_label($this->course_due_unit($course_id));
      $reassign = $this->reassignment_mode_label($this->course_reassignment_mode($course_id));
      if ($this->course_reassignment_value($course_id) > 0 && in_array($this->course_reassignment_mode($course_id), ['after_completion_interval', 'rolling_interval'], true)) {
        $reassign .= ' | ' . $this->course_reassignment_value($course_id) . ' ' . $this->interval_unit_label($this->course_reassignment_unit($course_id));
      }
      echo '<tr>';
      echo '<td><strong>' . esc_html((string)$course->post_title) . '</strong><br><span class="description">' . esc_html($this->course_short_description($course_id)) . '</span></td>';
      echo '<td>' . count($this->course_lessons($course_id)) . '</td>';
      echo '<td>' . esc_html($summary) . '</td>';
      echo '<td>' . esc_html($due . ' | ' . $reassign) . '</td>';
      echo '<td>' . ($this->course_is_optional($course_id) ? 'Yes' : 'No') . '</td>';
      echo '<td>' . ($this->course_certificate_enabled($course_id) ? 'Yes' : 'No') . '</td>';
      echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'course_edit' => $course_id], admin_url('admin.php'))) . '">Edit</a></td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
  }

  private function render_lessons_table(): string {
    $lessons = $this->admin_lesson_posts();
    if (empty($lessons)) return '<p class="description">No lessons created yet.</p>';

    ob_start();
    echo '<table class="widefat striped"><thead><tr><th>Lesson</th><th>Course</th><th>Order</th><th>Resources</th><th>Quiz</th><th></th></tr></thead><tbody>';
    foreach ($lessons as $lesson) {
      $lesson_id = (int)$lesson->ID;
      $course_id = $this->lesson_course_id($lesson_id);
      echo '<tr>';
      echo '<td><strong>' . esc_html((string)$lesson->post_title) . '</strong></td>';
      echo '<td>' . esc_html($course_id > 0 ? get_the_title($course_id) : 'Unassigned') . '</td>';
      echo '<td>' . (int)$this->lesson_sort_order($lesson_id) . '</td>';
      echo '<td>' . count($this->lesson_documents($lesson_id)) . ' docs | ' . count($this->lesson_videos($lesson_id)) . ' videos</td>';
      echo '<td>' . count($this->lesson_quizzes($lesson_id)) . ' questions | pass ' . $this->lesson_pass_percent($lesson_id) . '%</td>';
      echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'lesson_edit' => $lesson_id], admin_url('admin.php'))) . '">Edit</a></td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
  }

  private function render_course_card(WP_Post $enrollment, string $current_url, int $selected_course_id, bool $required): string {
    $course_id = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true);
    $course = get_post($course_id);
    if (!$course || $course->post_type !== self::CPT_COURSE) return '';
    $status = $this->enrollment_status_label($enrollment->ID);
    $progress = $this->course_progress_summary($course_id, $enrollment->ID);
    $due_at = sanitize_text_field((string)get_post_meta($enrollment->ID, self::META_ENROLLMENT_DUE_AT, true));
    $is_active = $selected_course_id === $course_id;
    $link = add_query_arg(['eum_lms_course' => $course_id], remove_query_arg('eum_lms_certificate', $current_url));

    $badge_bg = $required ? '#eff6ff' : '#f8fafc';
    $badge_color = $required ? '#1d4ed8' : '#334155';

    ob_start();
    echo '<a href="' . esc_url($link) . '" style="display:block;padding:16px;border:1px solid ' . ($is_active ? '#93c5fd' : '#e2e8f0') . ';border-radius:16px;text-decoration:none;color:#111827;background:' . ($is_active ? '#f8fbff' : '#fff') . ';">';
    echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">';
    echo '<div><strong style="display:block;font-size:15px;line-height:1.4;">' . esc_html((string)$course->post_title) . '</strong>';
    if ($this->course_short_description($course_id) !== '') {
      echo '<span style="display:block;margin-top:6px;color:#64748b;font-size:13px;">' . esc_html($this->course_short_description($course_id)) . '</span>';
    }
    echo '</div>';
    echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:' . esc_attr($badge_bg) . ';color:' . esc_attr($badge_color) . ';font-size:13px;font-weight:600;">' . esc_html($required ? 'Required' : 'Optional') . '</span>';
    echo '</div>';
    echo '<p style="margin:10px 0 0;color:#475569;line-height:1.55;">' . esc_html($progress . ' | ' . $status) . '</p>';
    if ($due_at !== '') echo '<p style="margin:10px 0 0;color:#94a3b8;font-size:12px;">Due ' . esc_html($this->human_datetime($due_at)) . '</p>';
    echo '</a>';
    return (string)ob_get_clean();
  }

  private function render_optional_catalog_card(WP_Post $course, string $current_url): string {
    $course_id = (int)$course->ID;
    ob_start();
    echo '<div style="padding:16px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
    echo '<strong style="display:block;font-size:15px;line-height:1.4;">' . esc_html((string)$course->post_title) . '</strong>';
    if ($this->course_short_description($course_id) !== '') {
      echo '<p style="margin:8px 0 0;color:#475569;line-height:1.55;">' . esc_html($this->course_short_description($course_id)) . '</p>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
    echo '<input type="hidden" name="action" value="eum_lms_optional_enroll">';
    echo '<input type="hidden" name="course_id" value="' . $course_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($current_url) . '">';
    wp_nonce_field('eum_lms_optional_enroll', 'eum_lms_nonce');
    echo '<button class="button button-secondary" type="submit">Add Course</button>';
    echo '</form>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_course_workspace(WP_Post $course, WP_Post $enrollment, int $user_id, string $current_url): string {
    $course_id = (int)$course->ID;
    $enrollment_id = (int)$enrollment->ID;
    $due_at = sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_DUE_AT, true));
    $completed_at = sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_COMPLETED_AT, true));
    $lessons = $this->course_lessons($course_id);
    $progress = $this->enrollment_lesson_progress($enrollment_id);
    $status = $this->enrollment_status_label($enrollment_id);
    $required = (int)get_post_meta($enrollment_id, self::META_ENROLLMENT_REQUIRED, true) === 1;
    $certificate_url = add_query_arg(['eum_lms_course' => $course_id, 'eum_lms_certificate' => 1], $current_url);

    ob_start();
    echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd;">' . esc_html($required ? 'Required Training' : 'Optional Training') . '</p>';
    echo '<h3 style="margin:0;font-size:30px;line-height:1.15;">' . esc_html((string)$course->post_title) . '</h3>';
    if ($this->course_short_description($course_id) !== '') {
      echo '<p style="margin:10px 0 0;color:#64748b;">' . esc_html($this->course_short_description($course_id)) . '</p>';
    }
    echo '</div>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
    echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:13px;font-weight:600;">' . esc_html($status) . '</span>';
    if ($due_at !== '') echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#f8fafc;color:#334155;font-size:13px;font-weight:600;">Due: ' . esc_html($this->human_datetime($due_at)) . '</span>';
    echo '</div></div>';

    if ((string)$course->post_content !== '') {
      echo '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;color:#334155;line-height:1.7;">' . wp_kses_post(wpautop((string)$course->post_content)) . '</div>';
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:18px;">';
    echo $this->summary_card('Progress', $this->course_progress_summary($course_id, $enrollment_id));
    echo $this->summary_card('Assigned', $this->human_datetime((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_ASSIGNED_AT, true)));
    echo $this->summary_card('Completed', $completed_at !== '' ? $this->human_datetime($completed_at) : 'Not yet');
    echo $this->summary_card('Certificate', $this->course_certificate_enabled($course_id) && $completed_at !== '' ? 'Available' : ($this->course_certificate_enabled($course_id) ? 'On completion' : 'Disabled'));
    echo '</div>';

    if (empty($lessons) && !$this->enrollment_is_completed($enrollment_id)) {
      echo '<div style="margin-top:18px;padding:16px;border:1px solid #dbeafe;border-radius:16px;background:#f8fbff;">';
      echo '<strong style="display:block;color:#1d4ed8;">Ready to complete</strong>';
      echo '<p style="margin:8px 0 0;color:#475569;">This course does not have lesson records yet. When you finish reviewing the course overview, you can mark the course complete here.</p>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
      echo '<input type="hidden" name="action" value="eum_lms_complete_course">';
      echo '<input type="hidden" name="course_id" value="' . $course_id . '">';
      echo '<input type="hidden" name="enrollment_id" value="' . $enrollment_id . '">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr(add_query_arg(['eum_lms_course' => $course_id], $current_url)) . '">';
      wp_nonce_field('eum_lms_complete_course', 'eum_lms_nonce');
      echo '<button class="button button-primary" type="submit">Mark Course Complete</button>';
      echo '</form>';
      echo '</div>';
    }

    if ($this->course_certificate_enabled($course_id) && $completed_at !== '') {
      echo '<div style="margin-top:18px;padding:16px;border:1px solid #bbf7d0;border-radius:16px;background:#f0fdf4;">';
      echo '<strong style="display:block;color:#166534;">Certificate Ready</strong>';
      echo '<p style="margin:8px 0 0;color:#166534;">This course issues a certificate. You can open the printable certificate at any time.</p>';
      echo '<p style="margin:12px 0 0;"><a class="button button-secondary" href="' . esc_url($certificate_url) . '">Open Certificate</a></p>';
      echo '</div>';
    }

    echo '<div style="margin-top:18px;display:grid;gap:16px;">';
    if (empty($lessons)) {
      echo '<div style="padding:16px;border:1px dashed #cbd5e1;border-radius:16px;color:#64748b;">This course does not have any lessons yet.</div>';
    } else {
      foreach ($lessons as $lesson) {
        $lesson_id = (int)$lesson->ID;
        $lesson_done = !empty($progress[$lesson_id]['completed_at']);
        echo '<article style="padding:18px;border:1px solid ' . ($lesson_done ? '#bbf7d0' : '#e2e8f0') . ';border-radius:16px;background:' . ($lesson_done ? '#f0fdf4' : '#fff') . ';">';
        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
        echo '<div><strong style="display:block;font-size:18px;">' . esc_html((string)$lesson->post_title) . '</strong>';
        if ($lesson_done) {
          echo '<span style="display:block;margin-top:6px;color:#166534;font-size:13px;">Completed ' . esc_html($this->human_datetime((string)($progress[$lesson_id]['completed_at'] ?? ''))) . '</span>';
        }
        echo '</div>';
        echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:' . esc_attr($lesson_done ? '#dcfce7' : '#f8fafc') . ';color:' . esc_attr($lesson_done ? '#166534' : '#334155') . ';font-size:13px;font-weight:600;">' . esc_html($lesson_done ? 'Completed' : 'Open') . '</span>';
        echo '</div>';

        if ((string)$lesson->post_content !== '') {
          echo '<div style="margin-top:12px;color:#334155;line-height:1.7;">' . wp_kses_post(wpautop((string)$lesson->post_content)) . '</div>';
        }

        $documents = $this->lesson_documents($lesson_id);
        if (!empty($documents)) {
          echo '<div style="margin-top:14px;"><strong style="display:block;margin-bottom:8px;">Documents</strong><div style="display:grid;gap:8px;">';
          foreach ($documents as $document) {
            echo '<a href="' . esc_url((string)$document['url']) . '" target="_blank" rel="noopener noreferrer" style="display:block;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;text-decoration:none;color:#0f172a;">' . esc_html((string)$document['label']) . '</a>';
          }
          echo '</div></div>';
        }

        $videos = $this->lesson_videos($lesson_id);
        if (!empty($videos)) {
          echo '<div style="margin-top:14px;display:grid;gap:12px;">';
          foreach ($videos as $video) {
            $embed = wp_oembed_get((string)$video['url'], ['width' => 760]);
            echo '<div style="padding:14px;border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;">';
            echo '<strong style="display:block;margin-bottom:8px;">' . esc_html((string)$video['label']) . '</strong>';
            if (is_string($embed) && $embed !== '') {
              echo $embed;
            } else {
              echo '<a href="' . esc_url((string)$video['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string)$video['url']) . '</a>';
            }
            echo '</div>';
          }
          echo '</div>';
        }

        $quizzes = $this->lesson_quizzes($lesson_id);
        if (!empty($quizzes)) {
          echo '<div style="margin-top:14px;padding:16px;border:1px solid #dbeafe;border-radius:14px;background:#f8fbff;">';
          echo '<strong style="display:block;margin-bottom:8px;color:#1d4ed8;">Quiz / Test</strong>';
          echo '<p style="margin:0 0 12px;color:#475569;">Pass ' . (int)$this->lesson_pass_percent($lesson_id) . '% or better to complete this lesson.</p>';
          if ($lesson_done) {
            echo '<p style="margin:0;color:#166534;">Quiz passed.</p>';
          } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="eum_lms_complete_lesson">';
            echo '<input type="hidden" name="lesson_id" value="' . $lesson_id . '">';
            echo '<input type="hidden" name="course_id" value="' . $course_id . '">';
            echo '<input type="hidden" name="enrollment_id" value="' . $enrollment_id . '">';
            echo '<input type="hidden" name="return_url" value="' . esc_attr(add_query_arg(['eum_lms_course' => $course_id], $current_url)) . '">';
            wp_nonce_field('eum_lms_complete_lesson', 'eum_lms_nonce');
            foreach ($quizzes as $index => $question) {
              echo '<div style="margin:0 0 14px;">';
              echo '<strong style="display:block;margin-bottom:8px;">' . esc_html((string)$question['question']) . '</strong>';
              foreach ((array)($question['choices'] ?? []) as $choice_index => $choice) {
                echo '<label style="display:block;margin:0 0 6px;"><input type="radio" name="quiz_answers[' . (int)$index . ']" value="' . (int)$choice_index . '"> ' . esc_html((string)$choice) . '</label>';
              }
              echo '</div>';
            }
            echo '<button class="button button-primary" type="submit">Submit Quiz & Complete Lesson</button>';
            echo '</form>';
          }
          echo '</div>';
        } elseif (!$lesson_done) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px;">';
          echo '<input type="hidden" name="action" value="eum_lms_complete_lesson">';
          echo '<input type="hidden" name="lesson_id" value="' . $lesson_id . '">';
          echo '<input type="hidden" name="course_id" value="' . $course_id . '">';
          echo '<input type="hidden" name="enrollment_id" value="' . $enrollment_id . '">';
          echo '<input type="hidden" name="return_url" value="' . esc_attr(add_query_arg(['eum_lms_course' => $course_id], $current_url)) . '">';
          wp_nonce_field('eum_lms_complete_lesson', 'eum_lms_nonce');
          echo '<button class="button button-primary" type="submit">Mark Lesson Complete</button>';
          echo '</form>';
        }

        echo '</article>';
      }
    }
    echo '</div>';

    return (string)ob_get_clean();
  }

  private function render_certificate(WP_Post $course, WP_Post $enrollment, int $user_id): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return '<div class="eum-lms-certificate"><p>User not found.</p></div>';

    $completed_at = sanitize_text_field((string)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COMPLETED_AT, true));
    $code = sanitize_text_field((string)get_post_meta($enrollment->ID, self::META_ENROLLMENT_CERTIFICATE_CODE, true));
    $title = $this->course_certificate_title((int)$course->ID);
    $body = $this->course_certificate_body((int)$course->ID);
    if ($title === '') $title = 'Certificate of Completion';
    if ($body === '') $body = 'This certifies that {{user_name}} successfully completed {{course_title}} on {{completed_at}}.';
    $body = str_replace(
      ['{{user_name}}', '{{course_title}}', '{{completed_at}}', '{{certificate_code}}'],
      [
        trim((string)$user->display_name) !== '' ? (string)$user->display_name : (string)$user->user_login,
        (string)$course->post_title,
        $completed_at !== '' ? $this->human_datetime($completed_at) : current_time('mysql'),
        $code,
      ],
      $body
    );

    ob_start();
    echo '<div class="eum-lms-certificate" style="max-width:980px;margin:0 auto;padding:36px;background:#fff;border:10px solid #dbeafe;border-radius:24px;box-shadow:0 24px 44px rgba(15,23,42,.08);text-align:center;">';
    echo '<p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#0f6cbd;">Learning Certificate</p>';
    echo '<h2 style="margin:0;font-size:40px;line-height:1.1;">' . esc_html($title) . '</h2>';
    echo '<div style="margin:28px auto 0;max-width:760px;font-size:20px;line-height:1.8;color:#334155;">' . wp_kses_post(wpautop($body)) . '</div>';
    echo '<div style="display:flex;justify-content:center;gap:36px;flex-wrap:wrap;margin-top:32px;color:#475569;">';
    echo '<div><strong style="display:block;color:#0f172a;">Completed</strong>' . esc_html($completed_at !== '' ? $this->human_datetime($completed_at) : current_time('mysql')) . '</div>';
    echo '<div><strong style="display:block;color:#0f172a;">Certificate Code</strong>' . esc_html($code) . '</div>';
    echo '</div>';
    echo '<p style="margin:28px 0 0;color:#64748b;">Use your browser print dialog if you want to save or print this certificate.</p>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function sync_user_enrollments(int $user_id): void {
    if ($user_id <= 0) return;
    $matched_required = [];
    foreach ($this->course_posts() as $course) {
      $course_id = (int)$course->ID;
      $source_labels = $this->course_matches_user($course_id, $user_id);
      if (!empty($source_labels)) {
        $matched_required[$course_id] = $source_labels;
        $enrollment = $this->find_enrollment($user_id, $course_id);
        if ($enrollment) {
          update_post_meta($enrollment->ID, self::META_ENROLLMENT_REQUIRED, 1);
          update_post_meta($enrollment->ID, self::META_ENROLLMENT_ACTIVE, 1);
          update_post_meta($enrollment->ID, self::META_ENROLLMENT_SOURCE_LABELS, array_values($source_labels));
          $this->maybe_reassign_enrollment($enrollment->ID, $course_id, $user_id);
        } else {
          $this->create_enrollment($user_id, $course_id, [
            'required' => true,
            'active' => true,
            'self_enrolled' => false,
            'source_labels' => array_values($source_labels),
            'anchor_at' => $this->initial_assignment_anchor_at($course_id, $user_id),
            'cycle_reason' => 'Initial assignment',
          ]);
        }
      }
    }

    foreach ($this->user_enrollments($user_id, false) as $enrollment) {
      if (!($enrollment instanceof WP_Post)) continue;
      $course_id = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true);
      if ($course_id <= 0) continue;
      $required = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_REQUIRED, true) === 1;
      $self_enrolled = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_SELF_ENROLLED, true) === 1;
      if (isset($matched_required[$course_id])) continue;
      if ($required) {
        update_post_meta($enrollment->ID, self::META_ENROLLMENT_REQUIRED, 0);
        if (!$self_enrolled && !$this->enrollment_is_completed($enrollment->ID)) {
          update_post_meta($enrollment->ID, self::META_ENROLLMENT_ACTIVE, 0);
        }
      }
      if ((int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_ACTIVE, true) === 1) {
        $this->maybe_reassign_enrollment($enrollment->ID, $course_id, $user_id);
      }
    }
  }

  private function maybe_reassign_enrollment(int $enrollment_id, int $course_id, int $user_id): void {
    if ($enrollment_id <= 0 || $course_id <= 0 || $user_id <= 0) return;
    if (!$this->enrollment_is_completed($enrollment_id)) return;

    $mode = $this->course_reassignment_mode($course_id);
    if ($mode === 'none') return;

    $assigned_at = sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_ASSIGNED_AT, true));
    $completed_at = sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_COMPLETED_AT, true));
    $anchor_at = '';
    $reason = '';

    if ($mode === 'after_completion_interval') {
      $value = $this->course_reassignment_value($course_id);
      if ($value <= 0 || $completed_at === '') return;
      $anchor_at = $this->add_interval($completed_at, $value, $this->course_reassignment_unit($course_id));
      $reason = 'Recurring after completion';
    } elseif ($mode === 'rolling_interval') {
      $value = $this->course_reassignment_value($course_id);
      if ($value <= 0 || $assigned_at === '') return;
      $anchor_at = $this->add_interval($assigned_at, $value, $this->course_reassignment_unit($course_id));
      $reason = 'Rolling retraining interval';
    } elseif ($mode === 'on_hire') {
      $anchor_at = $this->user_hire_anchor_at($user_id);
      $reason = 'Reassigned from hire date';
      if ($anchor_at === '' || ($completed_at !== '' && strtotime($anchor_at) <= strtotime($completed_at))) return;
    } elseif ($mode === 'on_rehire') {
      $anchor_at = $this->user_rehire_anchor_at($user_id);
      $reason = 'Reassigned from rehire date';
      if ($anchor_at === '' || ($completed_at !== '' && strtotime($anchor_at) <= strtotime($completed_at))) return;
    }

    if ($anchor_at === '') return;
    $anchor_ts = strtotime($anchor_at);
    if (!$anchor_ts || $anchor_ts > current_time('timestamp')) return;

    $this->reset_enrollment_cycle($enrollment_id, $course_id, $anchor_at, $reason);
  }

  private function create_enrollment(int $user_id, int $course_id, array $args): int {
    $post_id = wp_insert_post([
      'post_type' => self::CPT_ENROLLMENT,
      'post_status' => 'publish',
      'post_title' => $this->enrollment_title($user_id, $course_id, 1),
    ], true);
    if (is_wp_error($post_id) || (int)$post_id <= 0) return 0;
    $post_id = (int)$post_id;

    update_post_meta($post_id, self::META_ENROLLMENT_USER_ID, $user_id);
    update_post_meta($post_id, self::META_ENROLLMENT_COURSE_ID, $course_id);
    update_post_meta($post_id, self::META_ENROLLMENT_REQUIRED, !empty($args['required']) ? 1 : 0);
    update_post_meta($post_id, self::META_ENROLLMENT_ACTIVE, !empty($args['active']) ? 1 : 0);
    update_post_meta($post_id, self::META_ENROLLMENT_SELF_ENROLLED, !empty($args['self_enrolled']) ? 1 : 0);
    update_post_meta($post_id, self::META_ENROLLMENT_SOURCE_LABELS, array_values(array_filter(array_map('sanitize_text_field', (array)($args['source_labels'] ?? [])))));
    update_post_meta($post_id, self::META_ENROLLMENT_CYCLE_COUNT, 1);
    update_post_meta($post_id, self::META_ENROLLMENT_CERTIFICATE_CODE, $this->build_certificate_code($user_id, $course_id));
    $this->reset_enrollment_cycle($post_id, $course_id, sanitize_text_field((string)($args['anchor_at'] ?? current_time('mysql'))), sanitize_text_field((string)($args['cycle_reason'] ?? 'Initial assignment')), false);
    return $post_id;
  }

  private function reset_enrollment_cycle(int $enrollment_id, int $course_id, string $anchor_at, string $reason, bool $increment_cycle = true): void {
    $cycle_count = max(1, (int)get_post_meta($enrollment_id, self::META_ENROLLMENT_CYCLE_COUNT, true));
    if ($increment_cycle) {
      $cycle_count++;
      update_post_meta($enrollment_id, self::META_ENROLLMENT_CYCLE_COUNT, $cycle_count);
    }
    $due_at = $this->course_due_value($course_id) > 0 ? $this->add_interval($anchor_at, $this->course_due_value($course_id), $this->course_due_unit($course_id)) : '';
    update_post_meta($enrollment_id, self::META_ENROLLMENT_STATUS, self::STATUS_ASSIGNED);
    update_post_meta($enrollment_id, self::META_ENROLLMENT_ASSIGNED_AT, $anchor_at);
    update_post_meta($enrollment_id, self::META_ENROLLMENT_DUE_AT, $due_at);
    update_post_meta($enrollment_id, self::META_ENROLLMENT_STARTED_AT, '');
    update_post_meta($enrollment_id, self::META_ENROLLMENT_COMPLETED_AT, '');
    update_post_meta($enrollment_id, self::META_ENROLLMENT_CERTIFICATE_ISSUED_AT, '');
    update_post_meta($enrollment_id, self::META_ENROLLMENT_LESSON_PROGRESS, []);
    update_post_meta($enrollment_id, self::META_ENROLLMENT_LAST_CYCLE_REASON, $reason);
    wp_update_post([
      'ID' => $enrollment_id,
      'post_title' => $this->enrollment_title((int)get_post_meta($enrollment_id, self::META_ENROLLMENT_USER_ID, true), $course_id, $cycle_count),
    ]);
  }

  private function complete_enrollment(int $enrollment_id, int $course_id, int $user_id): void {
    $completed_at = current_time('mysql');
    update_post_meta($enrollment_id, self::META_ENROLLMENT_STATUS, self::STATUS_COMPLETED);
    update_post_meta($enrollment_id, self::META_ENROLLMENT_COMPLETED_AT, $completed_at);
    update_post_meta($enrollment_id, self::META_ENROLLMENT_LAST_COMPLETED_AT, $completed_at);
    if ($this->course_certificate_enabled($course_id)) {
      update_post_meta($enrollment_id, self::META_ENROLLMENT_CERTIFICATE_ISSUED_AT, $completed_at);
      $code = sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_CERTIFICATE_CODE, true));
      if ($code === '') update_post_meta($enrollment_id, self::META_ENROLLMENT_CERTIFICATE_CODE, $this->build_certificate_code($user_id, $course_id));
    }
  }

  private function course_is_complete_for_enrollment(int $course_id, int $enrollment_id): bool {
    $lessons = $this->course_lessons($course_id);
    if (empty($lessons)) return true;
    $progress = $this->enrollment_lesson_progress($enrollment_id);
    foreach ($lessons as $lesson) {
      $lesson_id = (int)$lesson->ID;
      if (empty($progress[$lesson_id]['completed_at'])) return false;
    }
    return true;
  }

  private function user_enrollments(int $user_id, bool $active_only): array {
    $posts = get_posts([
      'post_type' => self::CPT_ENROLLMENT,
      'post_status' => 'publish',
      'numberposts' => 500,
      'orderby' => 'modified',
      'order' => 'DESC',
      'meta_query' => [
        [
          'key' => self::META_ENROLLMENT_USER_ID,
          'value' => $user_id,
          'compare' => '=',
          'type' => 'NUMERIC',
        ],
      ],
    ]);
    if (!$active_only) return $posts;

    return array_values(array_filter($posts, function($post) {
      if (!$post instanceof WP_Post) return false;
      if ((int)get_post_meta((int)$post->ID, self::META_ENROLLMENT_ACTIVE, true) === 1) return true;
      return sanitize_text_field((string)get_post_meta((int)$post->ID, self::META_ENROLLMENT_COMPLETED_AT, true)) !== '';
    }));
  }

  private function find_enrollment(int $user_id, int $course_id): ?WP_Post {
    $posts = get_posts([
      'post_type' => self::CPT_ENROLLMENT,
      'post_status' => 'publish',
      'numberposts' => 1,
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => self::META_ENROLLMENT_USER_ID,
          'value' => $user_id,
          'compare' => '=',
          'type' => 'NUMERIC',
        ],
        [
          'key' => self::META_ENROLLMENT_COURSE_ID,
          'value' => $course_id,
          'compare' => '=',
          'type' => 'NUMERIC',
        ],
      ],
    ]);
    return !empty($posts[0]) && $posts[0] instanceof WP_Post ? $posts[0] : null;
  }

  private function course_posts(): array {
    return get_posts([
      'post_type' => self::CPT_COURSE,
      'post_status' => 'publish',
      'numberposts' => 500,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);
  }

  private function admin_course_posts(): array {
    return get_posts([
      'post_type' => self::CPT_COURSE,
      'post_status' => ['publish', 'draft'],
      'numberposts' => 500,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);
  }

  private function admin_lesson_posts(): array {
    return get_posts([
      'post_type' => self::CPT_LESSON,
      'post_status' => ['publish', 'draft'],
      'numberposts' => 1000,
      'meta_key' => self::META_LESSON_SORT_ORDER,
      'orderby' => ['meta_value_num' => 'ASC', 'title' => 'ASC'],
      'order' => 'ASC',
    ]);
  }

  private function course_lessons(int $course_id): array {
    if ($course_id <= 0) return [];
    return get_posts([
      'post_type' => self::CPT_LESSON,
      'post_status' => 'publish',
      'numberposts' => 500,
      'meta_key' => self::META_LESSON_SORT_ORDER,
      'orderby' => ['meta_value_num' => 'ASC', 'title' => 'ASC'],
      'order' => 'ASC',
      'meta_query' => [
        [
          'key' => self::META_LESSON_COURSE_ID,
          'value' => $course_id,
          'compare' => '=',
          'type' => 'NUMERIC',
        ],
      ],
    ]);
  }

  private function course_short_description(int $course_id): string {
    return sanitize_text_field((string)get_post_meta($course_id, self::META_SHORT_DESCRIPTION, true));
  }

  private function course_is_optional(int $course_id): bool {
    return !empty(get_post_meta($course_id, self::META_OPTIONAL, true));
  }

  private function course_assignments(int $course_id): array {
    return $this->normalize_assignment_rules(get_post_meta($course_id, self::META_ASSIGNMENTS, true));
  }

  private function course_due_value(int $course_id): int {
    return max(0, (int)get_post_meta($course_id, self::META_DUE_VALUE, true));
  }

  private function course_due_unit(int $course_id): string {
    return $this->normalize_interval_unit((string)get_post_meta($course_id, self::META_DUE_UNIT, true));
  }

  private function course_reassignment_mode(int $course_id): string {
    return $this->normalize_reassignment_mode((string)get_post_meta($course_id, self::META_REASSIGN_MODE, true));
  }

  private function course_reassignment_value(int $course_id): int {
    return max(0, (int)get_post_meta($course_id, self::META_REASSIGN_VALUE, true));
  }

  private function course_reassignment_unit(int $course_id): string {
    return $this->normalize_interval_unit((string)get_post_meta($course_id, self::META_REASSIGN_UNIT, true));
  }

  private function course_certificate_enabled(int $course_id): bool {
    return !empty(get_post_meta($course_id, self::META_CERTIFICATE_ENABLED, true));
  }

  private function course_certificate_title(int $course_id): string {
    return sanitize_text_field((string)get_post_meta($course_id, self::META_CERTIFICATE_TITLE, true));
  }

  private function course_certificate_body(int $course_id): string {
    return sanitize_textarea_field((string)get_post_meta($course_id, self::META_CERTIFICATE_BODY, true));
  }

  private function lesson_course_id(int $lesson_id): int {
    return max(0, (int)get_post_meta($lesson_id, self::META_LESSON_COURSE_ID, true));
  }

  private function lesson_sort_order(int $lesson_id): int {
    return max(0, (int)get_post_meta($lesson_id, self::META_LESSON_SORT_ORDER, true));
  }

  private function lesson_documents(int $lesson_id): array {
    return $this->normalize_link_rows(get_post_meta($lesson_id, self::META_LESSON_DOCUMENTS, true));
  }

  private function lesson_videos(int $lesson_id): array {
    return $this->normalize_link_rows(get_post_meta($lesson_id, self::META_LESSON_VIDEOS, true));
  }

  private function lesson_quizzes(int $lesson_id): array {
    return $this->normalize_quiz_rows(get_post_meta($lesson_id, self::META_LESSON_QUIZZES, true));
  }

  private function lesson_pass_percent(int $lesson_id): int {
    return min(100, max(1, (int)get_post_meta($lesson_id, self::META_LESSON_PASS_PERCENT, true)));
  }

  private function enrollment_lesson_progress(int $enrollment_id): array {
    $raw = get_post_meta($enrollment_id, self::META_ENROLLMENT_LESSON_PROGRESS, true);
    if (!is_array($raw)) return [];
    $clean = [];
    foreach ($raw as $lesson_id => $row) {
      if (!is_array($row)) continue;
      $clean[(int)$lesson_id] = [
        'completed_at' => sanitize_text_field((string)($row['completed_at'] ?? '')),
        'quiz_total' => max(0, (int)($row['quiz_total'] ?? 0)),
        'quiz_correct' => max(0, (int)($row['quiz_correct'] ?? 0)),
      ];
    }
    return $clean;
  }

  private function enrollment_is_completed(int $enrollment_id): bool {
    return sanitize_key((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_STATUS, true)) === self::STATUS_COMPLETED;
  }

  private function effective_enrollment_status(int $enrollment_id): string {
    $status = sanitize_key((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_STATUS, true));
    if ($status === self::STATUS_COMPLETED) return self::STATUS_COMPLETED;
    $due_at = sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_DUE_AT, true));
    if ($due_at !== '' && strtotime($due_at) && strtotime($due_at) < current_time('timestamp')) return 'overdue';
    if ($status === self::STATUS_IN_PROGRESS) return self::STATUS_IN_PROGRESS;
    return self::STATUS_ASSIGNED;
  }

  private function enrollment_due_soon(int $enrollment_id): bool {
    if ($this->enrollment_is_completed($enrollment_id)) return false;
    $due_at = sanitize_text_field((string)get_post_meta($enrollment_id, self::META_ENROLLMENT_DUE_AT, true));
    $ts = $due_at !== '' ? strtotime($due_at) : false;
    if (!$ts) return false;
    $now = current_time('timestamp');
    return $ts >= $now && $ts <= ($now + (14 * DAY_IN_SECONDS));
  }

  private function enrollment_status_label(int $enrollment_id): string {
    $status = $this->effective_enrollment_status($enrollment_id);
    if ($status === self::STATUS_COMPLETED) return 'Completed';
    if ($status === self::STATUS_IN_PROGRESS) return 'In Progress';
    if ($status === 'overdue') return 'Overdue';
    return 'Assigned';
  }

  private function course_progress_summary(int $course_id, int $enrollment_id): string {
    $lessons = $this->course_lessons($course_id);
    if (empty($lessons)) return '0 / 0 lessons';
    $progress = $this->enrollment_lesson_progress($enrollment_id);
    $done = 0;
    foreach ($lessons as $lesson) {
      if (!empty($progress[(int)$lesson->ID]['completed_at'])) $done++;
    }
    return $done . ' / ' . count($lessons) . ' lessons';
  }

  private function optional_catalog_courses_for_user(int $user_id): array {
    $enrolled_ids = [];
    foreach ($this->user_enrollments($user_id, true) as $enrollment) {
      if (!($enrollment instanceof WP_Post)) continue;
      $course_id = (int)get_post_meta($enrollment->ID, self::META_ENROLLMENT_COURSE_ID, true);
      if ($course_id > 0) $enrolled_ids[$course_id] = true;
    }

    $catalog = [];
    foreach ($this->course_posts() as $course) {
      if (!$this->course_is_optional((int)$course->ID)) continue;
      if (isset($enrolled_ids[(int)$course->ID])) continue;
      $catalog[] = $course;
    }
    return $catalog;
  }

  private function managed_user_ids(int $actor_user_id, bool $include_indirect): array {
    if ($actor_user_id <= 0) return [];
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
      return array_values(array_filter(array_map(static fn($user) => (int)$user->ID, get_users(['fields' => ['ID']]))));
    }
    if (class_exists('EUM_Manager_IDs') && method_exists('EUM_Manager_IDs', 'managed_user_levels_for_manager_user')) {
      return array_values(array_map('intval', array_keys((array)EUM_Manager_IDs::managed_user_levels_for_manager_user($actor_user_id, false, $include_indirect))));
    }

    $ids = [];
    foreach (get_users(['fields' => ['ID']]) as $user) {
      $user_id = (int)$user->ID;
      if ($user_id > 0 && class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $user_id)) {
        $ids[] = $user_id;
      }
    }
    return array_values(array_unique($ids));
  }

  private function course_matches_user(int $course_id, int $user_id): array {
    $rules = $this->course_assignments($course_id);
    $matches = [];

    if (in_array($user_id, (array)($rules['user_ids'] ?? []), true)) {
      $matches[] = 'Direct user assignment';
    }

    $job_path_ids = $this->user_term_ids($user_id, 'eum_job_path');
    $job_path_match_ids = [];
    foreach ($job_path_ids as $term_id) {
      $job_path_match_ids[] = $term_id;
      foreach ((array)get_ancestors($term_id, 'eum_job_path') as $ancestor_id) {
        $job_path_match_ids[] = (int)$ancestor_id;
      }
    }
    if (!empty(array_intersect($job_path_match_ids, (array)($rules['job_paths'] ?? [])))) {
      $matches[] = 'Job path';
    }

    if (!empty(array_intersect($this->user_term_ids($user_id, 'eum_user_group'), (array)($rules['groups'] ?? [])))) {
      $matches[] = 'Group';
    }
    if (!empty(array_intersect($this->user_term_ids($user_id, 'eum_location'), (array)($rules['locations'] ?? [])))) {
      $matches[] = 'Location';
    }
    if (!empty(array_intersect($this->user_term_ids($user_id, 'eum_project'), (array)($rules['projects'] ?? [])))) {
      $matches[] = 'Project';
    }

    $job_title_id = $this->user_job_title_id($user_id);
    if ($job_title_id > 0 && in_array($job_title_id, (array)($rules['job_titles'] ?? []), true)) {
      $matches[] = 'Job title';
    }

    return array_values(array_unique(array_filter(array_map('sanitize_text_field', $matches))));
  }

  private function user_term_ids(int $user_id, string $taxonomy): array {
    if ($user_id <= 0 || !taxonomy_exists($taxonomy)) return [];
    $ids = wp_get_object_terms($user_id, $taxonomy, ['fields' => 'ids']);
    if (is_wp_error($ids)) return [];
    return array_values(array_filter(array_map('intval', (array)$ids), static fn($id) => $id > 0));
  }

  private function user_term_path(int $user_id, string $taxonomy): string {
    $ids = $this->user_term_ids($user_id, $taxonomy);
    if (empty($ids)) return '';
    $term_id = (int)$ids[0];
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'term_full_path')) {
      return EUM_Profiles::term_full_path($taxonomy, $term_id, ' / ');
    }
    $term = get_term($term_id, $taxonomy);
    return $term && !is_wp_error($term) ? sanitize_text_field((string)$term->name) : '';
  }

  private function user_job_title_id(int $user_id): int {
    if ($user_id <= 0 || !class_exists('EUM_Fields') || !class_exists('EUM_Profiles')) return 0;
    $fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    return EUM_Profiles::job_title_post_id_from_value((string)($fields['job_title'] ?? ''));
  }

  private function user_hire_anchor_at(int $user_id): string {
    $user = get_user_by('id', $user_id);
    return $user && !empty($user->user_registered) ? sanitize_text_field((string)$user->user_registered) : '';
  }

  private function user_rehire_anchor_at(int $user_id): string {
    if ($user_id <= 0 || !class_exists('EUM_Profiles')) return '';
    $changes = get_posts([
      'post_type' => EUM_Profiles::CPT_CHANGE,
      'post_status' => ['publish', 'draft', 'pending', 'private'],
      'numberposts' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        [
          'key' => EUM_Profiles::META_TARGET_USER,
          'value' => $user_id,
          'compare' => '=',
          'type' => 'NUMERIC',
        ],
      ],
    ]);
    foreach ($changes as $change) {
      $after = get_post_meta((int)$change->ID, EUM_Profiles::META_AFTER, true);
      if (!is_array($after)) continue;
      if (array_key_exists('system:eum_terminated', $after) && empty($after['system:eum_terminated'])) {
        $occurred = sanitize_text_field((string)get_post_meta((int)$change->ID, EUM_Profiles::META_OCCURRED_AT, true));
        if ($occurred !== '') return $occurred;
        $approved = sanitize_text_field((string)get_post_meta((int)$change->ID, EUM_Profiles::META_APPROVED_AT, true));
        if ($approved !== '') return $approved;
      }
    }
    return '';
  }

  private function normalize_assignment_rules_from_post(array $post): array {
    return $this->normalize_assignment_rules([
      'user_ids' => $this->resolve_direct_user_assignments((string)wp_unslash($post['assignment_users_raw'] ?? '')),
      'job_paths' => array_map('intval', (array)($post['assignment_job_paths'] ?? [])),
      'groups' => array_map('intval', (array)($post['assignment_groups'] ?? [])),
      'locations' => array_map('intval', (array)($post['assignment_locations'] ?? [])),
      'projects' => array_map('intval', (array)($post['assignment_projects'] ?? [])),
      'job_titles' => array_map('intval', (array)($post['assignment_job_titles'] ?? [])),
    ]);
  }

  private function default_assignment_rules(): array {
    return [
      'user_ids' => [],
      'job_paths' => [],
      'groups' => [],
      'locations' => [],
      'projects' => [],
      'job_titles' => [],
    ];
  }

  private function normalize_assignment_rules($rules): array {
    $rules = is_array($rules) ? $rules : [];
    $clean = $this->default_assignment_rules();
    foreach (array_keys($clean) as $key) {
      $clean[$key] = array_values(array_unique(array_filter(array_map('intval', (array)($rules[$key] ?? [])), static fn($id) => $id > 0)));
      sort($clean[$key]);
    }
    return $clean;
  }

  private function normalize_link_rows_from_textarea(string $raw): array {
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line, 2));
      $label = sanitize_text_field((string)($parts[0] ?? ''));
      $url = esc_url_raw((string)($parts[1] ?? ''));
      if ($label === '' || $url === '') continue;
      $rows[] = ['label' => $label, 'url' => $url];
    }
    return $this->normalize_link_rows($rows);
  }

  private function normalize_link_rows($rows): array {
    if (!is_array($rows)) return [];
    $clean = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      $url = esc_url_raw((string)($row['url'] ?? ''));
      if ($label === '' || $url === '') continue;
      $clean[] = ['label' => $label, 'url' => $url];
    }
    return array_values($clean);
  }

  private function normalize_quiz_rows_from_textarea(string $raw): array {
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) < 3) continue;
      $question = sanitize_text_field(array_shift($parts));
      if ($question === '') continue;
      $choices = [];
      $correct_index = -1;
      foreach ($parts as $index => $choice) {
        $choice = trim((string)$choice);
        $is_correct = strpos($choice, '*') === 0;
        if ($is_correct) $choice = ltrim($choice, '* ');
        $choice = sanitize_text_field($choice);
        if ($choice === '') continue;
        $choices[] = $choice;
        if ($is_correct) $correct_index = count($choices) - 1;
      }
      if (count($choices) < 2 || $correct_index < 0) continue;
      $rows[] = [
        'question' => $question,
        'choices' => $choices,
        'correct_index' => $correct_index,
      ];
    }
    return $this->normalize_quiz_rows($rows);
  }

  private function normalize_quiz_rows($rows): array {
    if (!is_array($rows)) return [];
    $clean = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $question = sanitize_text_field((string)($row['question'] ?? ''));
      $choices = array_values(array_filter(array_map('sanitize_text_field', (array)($row['choices'] ?? [])), static fn($value) => $value !== ''));
      $correct_index = max(-1, (int)($row['correct_index'] ?? -1));
      if ($question === '' || count($choices) < 2 || $correct_index < 0 || !isset($choices[$correct_index])) continue;
      $clean[] = [
        'question' => $question,
        'choices' => $choices,
        'correct_index' => $correct_index,
      ];
    }
    return array_values($clean);
  }

  private function format_link_rows_for_textarea(array $rows): string {
    $lines = [];
    foreach ($this->normalize_link_rows($rows) as $row) {
      $lines[] = $row['label'] . ' | ' . $row['url'];
    }
    return implode("\n", $lines);
  }

  private function format_quizzes_for_textarea(array $rows): string {
    $lines = [];
    foreach ($this->normalize_quiz_rows($rows) as $row) {
      $parts = [$row['question']];
      foreach ((array)$row['choices'] as $index => $choice) {
        $parts[] = ((int)$row['correct_index'] === (int)$index ? '*' : '') . $choice;
      }
      $lines[] = implode(' | ', $parts);
    }
    return implode("\n", $lines);
  }

  private function resolve_direct_user_assignments(string $raw): array {
    $ids = [];
    foreach (preg_split('/[\r\n,;]+/', $raw) as $chunk) {
      $chunk = trim((string)$chunk);
      if ($chunk === '') continue;
      if (ctype_digit($chunk)) {
        $id = (int)$chunk;
        if ($id > 0 && get_user_by('id', $id)) $ids[$id] = $id;
        continue;
      }
      $user = is_email($chunk) ? get_user_by('email', sanitize_email($chunk)) : get_user_by('login', sanitize_user($chunk, true));
      if ($user instanceof WP_User) $ids[(int)$user->ID] = (int)$user->ID;
    }
    return array_values($ids);
  }

  private function format_direct_user_assignments(array $user_ids): string {
    $labels = [];
    foreach (array_values(array_unique(array_map('intval', $user_ids))) as $user_id) {
      $user = get_user_by('id', $user_id);
      if ($user instanceof WP_User) {
        $labels[] = $user->user_email !== '' ? $user->user_email : (string)$user->user_login;
      } else {
        $labels[] = (string)$user_id;
      }
    }
    return implode("\n", $labels);
  }

  private function course_assignment_summary(int $course_id): string {
    $rules = $this->course_assignments($course_id);
    $parts = [];
    if (!empty($rules['user_ids'])) $parts[] = count($rules['user_ids']) . ' users';
    if (!empty($rules['job_paths'])) $parts[] = count($rules['job_paths']) . ' job paths';
    if (!empty($rules['groups'])) $parts[] = count($rules['groups']) . ' groups';
    if (!empty($rules['locations'])) $parts[] = count($rules['locations']) . ' locations';
    if (!empty($rules['projects'])) $parts[] = count($rules['projects']) . ' projects';
    if (!empty($rules['job_titles'])) $parts[] = count($rules['job_titles']) . ' job titles';
    if (empty($parts)) $parts[] = 'No required audience';
    if ($this->course_is_optional($course_id)) $parts[] = 'Optional catalog';
    return implode(' | ', $parts);
  }

  private function course_options(): array {
    $options = [];
    foreach ($this->admin_course_posts() as $course) {
      $options[(int)$course->ID] = sanitize_text_field((string)$course->post_title);
    }
    natcasesort($options);
    return $options;
  }

  private function job_path_options(): array {
    if (!taxonomy_exists('eum_job_path')) return [];
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'number' => 0]);
    if (is_wp_error($terms)) return [];
    $options = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $options[$term_id] = class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ') : sanitize_text_field((string)$term->name);
    }
    natcasesort($options);
    return $options;
  }

  private function taxonomy_options(string $taxonomy): array {
    if (!taxonomy_exists($taxonomy)) return [];
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0]);
    if (is_wp_error($terms)) return [];
    $options = [];
    foreach ($terms as $term) {
      $options[(int)$term->term_id] = sanitize_text_field((string)$term->name);
    }
    natcasesort($options);
    return $options;
  }

  private function job_title_options(): array {
    if (!class_exists('EUM_Job_Titles')) return [];
    $options = [];
    foreach (EUM_Job_Titles::title_posts() as $post) {
      $options[(int)$post->ID] = sanitize_text_field((string)$post->post_title);
    }
    natcasesort($options);
    return $options;
  }

  private function render_checkboxes(string $name, array $options, array $selected, string $empty): string {
    if (empty($options)) return '<p class="description">' . esc_html($empty) . '</p>';
    $selected = array_values(array_unique(array_map('intval', $selected)));
    $html = '<div style="max-height:180px;overflow:auto;border:1px solid #ccd0d4;padding:8px 10px;background:#fff;">';
    foreach ($options as $value => $label) {
      $html .= '<label style="display:block;margin:0 0 6px 0;"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . (int)$value . '" ' . checked(in_array((int)$value, $selected, true), true, false) . '> ' . esc_html((string)$label) . '</label>';
    }
    $html .= '</div>';
    return $html;
  }

  private function select_options(array $options, string $selected): string {
    $html = '';
    foreach ($options as $value => $label) {
      $html .= '<option value="' . esc_attr((string)$value) . '"' . selected((string)$selected, (string)$value, false) . '>' . esc_html((string)$label) . '</option>';
    }
    return $html;
  }

  private function interval_unit_options(): array {
    return [
      'days' => 'Days',
      'weeks' => 'Weeks',
      'months' => 'Months',
    ];
  }

  private function reassignment_mode_options(): array {
    return [
      'none' => 'No Reassignment',
      'after_completion_interval' => 'After Completion Interval',
      'rolling_interval' => 'Rolling Interval',
      'on_hire' => 'On Hire',
      'on_rehire' => 'On Rehire',
    ];
  }

  private function normalize_interval_unit(string $unit): string {
    return array_key_exists($unit, $this->interval_unit_options()) ? $unit : 'days';
  }

  private function normalize_reassignment_mode(string $mode): string {
    return array_key_exists($mode, $this->reassignment_mode_options()) ? $mode : 'none';
  }

  private function interval_unit_label(string $unit): string {
    $options = $this->interval_unit_options();
    return (string)($options[$this->normalize_interval_unit($unit)] ?? 'Days');
  }

  private function reassignment_mode_label(string $mode): string {
    $options = $this->reassignment_mode_options();
    return (string)($options[$this->normalize_reassignment_mode($mode)] ?? 'No Reassignment');
  }

  private function build_certificate_code(int $user_id, int $course_id): string {
    return 'LMS-' . $course_id . '-' . $user_id . '-' . strtoupper(wp_generate_password(6, false, false));
  }

  private function enrollment_title(int $user_id, int $course_id, int $cycle_count): string {
    $user = get_user_by('id', $user_id);
    $user_label = $user instanceof WP_User ? (trim((string)$user->display_name) !== '' ? (string)$user->display_name : (string)$user->user_login) : ('User #' . $user_id);
    $course_title = $course_id > 0 ? (string)get_the_title($course_id) : 'Course';
    return sanitize_text_field($course_title . ' / ' . $user_label . ' / Cycle ' . max(1, $cycle_count));
  }

  private function initial_assignment_anchor_at(int $course_id, int $user_id): string {
    $mode = $this->course_reassignment_mode($course_id);
    if ($mode === 'on_hire') {
      $anchor_at = $this->user_hire_anchor_at($user_id);
      if ($anchor_at !== '') return $anchor_at;
    }
    if ($mode === 'on_rehire') {
      $anchor_at = $this->user_rehire_anchor_at($user_id);
      if ($anchor_at !== '') return $anchor_at;
      $anchor_at = $this->user_hire_anchor_at($user_id);
      if ($anchor_at !== '') return $anchor_at;
    }
    return current_time('mysql');
  }

  private function add_interval(string $datetime, int $value, string $unit): string {
    $timestamp = strtotime($datetime);
    if (!$timestamp || $value <= 0) return sanitize_text_field($datetime);
    $unit = $this->normalize_interval_unit($unit);
    $modified = strtotime('+' . $value . ' ' . $unit, $timestamp);
    return $modified ? date_i18n('Y-m-d H:i:s', $modified) : sanitize_text_field($datetime);
  }

  private function summary_chip(string $label, string $value, string $bg, string $color): string {
    return '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-size:13px;font-weight:600;">' . esc_html($label . ': ' . $value) . '</span>';
  }

  private function summary_card(string $label, string $value): string {
    return '<div style="padding:14px 16px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;"><span style="display:block;margin-bottom:6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;">' . esc_html($label) . '</span>' . esc_html($value) . '</div>';
  }

  private function truthy($value): bool {
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
  }

  private function human_datetime(string $datetime): string {
    $ts = $datetime !== '' ? strtotime($datetime) : false;
    return $ts ? date_i18n('Y-m-d g:i A', $ts) : 'Unknown';
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : parse_url(home_url('/'), PHP_URL_HOST);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    return $scheme . $host . $request_uri;
  }

  private function redirect_admin(string $url, string $message, string $type = 'success', array $extra_args = []): void {
    $args = array_merge($extra_args, [
      'eum_lms_notice' => $message,
      'eum_lms_notice_type' => $type,
    ]);
    wp_safe_redirect(add_query_arg($args, $url));
    exit;
  }

  private function redirect_portal(string $url, string $message, string $type = 'success'): void {
    $target = add_query_arg([
      'eum_lms_notice' => $message,
      'eum_lms_notice_type' => $type,
    ], remove_query_arg(['eum_lms_notice', 'eum_lms_notice_type'], $url));
    wp_safe_redirect($target);
    exit;
  }

  private function delete_course_related_records(int $course_id): void {
    foreach ($this->course_lessons($course_id) as $lesson) {
      if ($lesson instanceof WP_Post) wp_delete_post((int)$lesson->ID, true);
    }
    $enrollments = get_posts([
      'post_type' => self::CPT_ENROLLMENT,
      'post_status' => 'publish',
      'numberposts' => 1000,
      'meta_query' => [
        [
          'key' => self::META_ENROLLMENT_COURSE_ID,
          'value' => $course_id,
          'compare' => '=',
          'type' => 'NUMERIC',
        ],
      ],
    ]);
    foreach ($enrollments as $enrollment) {
      if ($enrollment instanceof WP_Post) wp_delete_post((int)$enrollment->ID, true);
    }
  }
}
