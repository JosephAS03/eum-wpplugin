<?php

if (!defined('ABSPATH')) exit;



class EUM_Shortcodes {

  public function init(): void {

    // Core shortcodes

    add_shortcode('eum_user_meta', [$this, 'sc_user_meta']);

    add_shortcode('eum_user_file', [$this, 'sc_user_file']);

    add_shortcode('eum_personnel_list', [$this, 'sc_personnel_list']);
    add_shortcode('eum_user_data', [$this, 'sc_user_data']);



    add_shortcode('eum_job_path', [$this, 'sc_job_path']);

    add_shortcode('eum_job_title', [$this, 'sc_job_title']);

    add_shortcode('eum_location_path', [$this, 'sc_location_path']);



    // Optional manager/permissions shortcodes

    add_shortcode('eum_manager_id', [$this, 'sc_manager_id']);

    add_shortcode('eum_manager_users', [$this, 'sc_manager_users']);



    // Gate

    add_shortcode('eum_gate', [$this, 'sc_gate']);



    // Dynamic Links

    add_shortcode('eum_dynamic_links', [$this, 'sc_dynamic_links']);



    // Manager Messages (banner system)

    add_shortcode('eum_manager_messages', [$this, 'sc_manager_messages']);



    // Optional: My Team dashboard passthrough

    if (class_exists('EUM_Manager_Dashboard')) {

      add_shortcode('eum_my_team', [new EUM_Manager_Dashboard(), 'shortcode_my_team']);

    }

  }



  private function resolve_user_id($atts): int {

    if (!empty($atts['user_id'])) return max(0, (int)$atts['user_id']);

    return (int)get_current_user_id();

  }



  // --------------------------------------------------------------------------

  // [eum_user_meta]

  // --------------------------------------------------------------------------

  public function sc_user_meta($atts): string {

    $atts = shortcode_atts([

      'key' => '',

      'user_id' => '',

      'source' => 'auto', // auto|fields|meta|user

      'fallback' => '',

      'escape' => '1',

    ], (array)$atts, 'eum_user_meta');



    $user_id = $this->resolve_user_id($atts);

    if (!$user_id) return '';



    $key = sanitize_key((string)$atts['key']);

    if (!$key) return '';



    $source = strtolower(trim((string)$atts['source']));

    if ($source === '') $source = 'auto';



    $value = null;

    $found = false;



    $try_fields = function() use ($user_id, $key, &$value, &$found) {

      if (!class_exists('EUM_Fields')) return;

      $fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);

      if (array_key_exists($key, $fields)) { $value = $fields[$key]; $found = true; }

    };



    $try_meta = function() use ($user_id, $key, &$value, &$found) {

      $v = get_user_meta($user_id, $key, true);

      if ($v !== '' || metadata_exists('user', $user_id, $key)) { $value = $v; $found = true; }

    };



    $try_user = function() use ($user_id, $key, &$value, &$found) {

      $u = get_user_by('id', $user_id);

      if (!$u) return;

      $map = ['email'=>'user_email','login'=>'user_login','username'=>'user_login','name'=>'display_name'];

      $prop = $map[$key] ?? $key;

      if (isset($u->$prop)) { $value = $u->$prop; $found = true; }

    };



    if ($source === 'fields') $try_fields();

    elseif ($source === 'meta') $try_meta();

    elseif ($source === 'user') $try_user();

    else { $try_fields(); if (!$found) $try_meta(); if (!$found) $try_user(); }



    if (!$found) $value = $atts['fallback'];

    if (is_array($value) || is_object($value)) $value = wp_json_encode($value);



    $out = (string)$value;

    return ($atts['escape'] === '1') ? esc_html($out) : $out;

  }



  // --------------------------------------------------------------------------

  // [eum_user_file]

  // --------------------------------------------------------------------------

  public function sc_user_file($atts): string {

    $atts = shortcode_atts([

      'user_id' => '',

      'index' => '0',

      'type' => 'link', // link|image

      'class' => '',

      'alt' => '',

    ], (array)$atts, 'eum_user_file');



    $user_id = $this->resolve_user_id($atts);

    if (!$user_id) return '';

    if (!class_exists('EUM_Personnel')) return '';



    $files = (array)get_user_meta($user_id, EUM_Personnel::META_FILES, true);

    $i = max(0, (int)$atts['index']);

    if (empty($files[$i]['url'])) return '';



    $url = esc_url($files[$i]['url']);

    $name = esc_html($files[$i]['name'] ?? 'file');



    if ($atts['type'] === 'image') {

      $class = trim(preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)$atts['class']));

      $alt = esc_attr($atts['alt'] ?: $name);

      return '<img src="'.$url.'" alt="'.$alt.'" class="'.esc_attr($class).'" />';

    }



    return '<a href="'.$url.'" target="_blank" rel="noopener">'.$name.'</a>';

  }



  // --------------------------------------------------------------------------

  // [eum_personnel_list]

  // --------------------------------------------------------------------------

  public function sc_personnel_list($atts): string {

    $atts = shortcode_atts(['user_id' => ''], (array)$atts, 'eum_personnel_list');



    $user_id = $this->resolve_user_id($atts);

    if (!$user_id) return '';

    if (!class_exists('EUM_Personnel')) return '';



    $files = (array)get_user_meta($user_id, EUM_Personnel::META_FILES, true);

    if (empty($files)) return '';



    $out = '<ul class="eum-personnel-list">';

    foreach ($files as $f) {

      if (empty($f['url'])) continue;

      $out .= '<li><a href="'.esc_url($f['url']).'" target="_blank" rel="noopener">'.esc_html($f['name'] ?? 'file').'</a></li>';

    }

    $out .= '</ul>';

    return $out;

  }



  // --------------------------------------------------------------------------

  // Job/Location

  // --------------------------------------------------------------------------

  public function sc_job_path($atts): string {

    $atts = shortcode_atts(['user_id'=>'', 'separator'=>' → '], (array)$atts, 'eum_job_path');

    return $this->taxonomy_full_path('eum_job_path', $atts);

  }



  public function sc_job_title($atts): string {

    $atts = shortcode_atts(['user_id'=>''], (array)$atts, 'eum_job_title');

    $user_id = $this->resolve_user_id($atts);

    if (!$user_id) return '';

    if (class_exists('EUM_Profiles')) {
      $label = EUM_Profiles::preferred_job_title_label($user_id);
      if ($label !== '') return esc_html($label);
    }

    $terms = wp_get_object_terms($user_id, 'eum_job_path');

    if (is_wp_error($terms) || empty($terms)) return '';

    return esc_html($terms[0]->name);

  }



  public function sc_location_path($atts): string {

    $atts = shortcode_atts(['user_id'=>'', 'separator'=>' / '], (array)$atts, 'eum_location_path');

    return $this->taxonomy_full_path('eum_location', $atts);

  }



  private function taxonomy_full_path(string $tax, array $atts): string {

    $user_id = $this->resolve_user_id($atts);

    if (!$user_id) return '';



    $terms = wp_get_object_terms($user_id, $tax);

    if (is_wp_error($terms) || empty($terms)) return '';

    $leaf = $terms[0];



    $anc = array_reverse(get_ancestors($leaf->term_id, $tax));

    $names = [];

    foreach ($anc as $aid) {

      $t = get_term($aid, $tax);

      if ($t && !is_wp_error($t)) $names[] = $t->name;

    }

    $names[] = $leaf->name;



    $sep = isset($atts['separator']) ? (string)$atts['separator'] : ' → ';

    return esc_html(implode($sep, $names));

  }



  // --------------------------------------------------------------------------

  // Manager shortcodes (safe if Manager IDs module exists)

  // --------------------------------------------------------------------------

  public function sc_manager_id($atts): string {

    if (!class_exists('EUM_Manager_IDs')) return '';

    $atts = shortcode_atts(['user_id'=>'0','field'=>'name','fallback'=>'None'], (array)$atts, 'eum_manager_id');



    $user_id = (int)$atts['user_id'];

    if ($user_id <= 0) $user_id = get_current_user_id();

    if ($user_id <= 0) return esc_html((string)$atts['fallback']);



    $mgr = new EUM_Manager_IDs();

    if (!method_exists($mgr, 'resolve_effective_manager_id_for_user')) return esc_html((string)$atts['fallback']);



    $mid = (int)$mgr->resolve_effective_manager_id_for_user($user_id);

    if ($mid <= 0) return esc_html((string)$atts['fallback']);



    $field = strtolower(trim((string)$atts['field']));

    if ($field === 'id') return (string)$mid;



    if ($field === 'code' && defined('EUM_Manager_IDs::META_CODE')) {

      $code = (string)get_post_meta($mid, EUM_Manager_IDs::META_CODE, true);

      return $code !== '' ? esc_html($code) : esc_html((string)$atts['fallback']);

    }



    $name = get_the_title($mid);

    return $name ? esc_html($name) : esc_html((string)$atts['fallback']);

  }



  public function sc_manager_users($atts): string {

    if (!class_exists('EUM_Manager_IDs')) return '';

    if (!defined('EUM_Manager_IDs::META_MANAGER_USERS')) return '';



    $atts = shortcode_atts(['user_id'=>'0','field'=>'names','fallback'=>'None','sep'=>', ','first'=>'0'], (array)$atts, 'eum_manager_users');



    $user_id = (int)$atts['user_id'];

    if ($user_id <= 0) $user_id = get_current_user_id();

    if ($user_id <= 0) return esc_html((string)$atts['fallback']);



    $mgr = new EUM_Manager_IDs();

    if (!method_exists($mgr, 'resolve_effective_manager_id_for_user')) return esc_html((string)$atts['fallback']);



    $mid = (int)$mgr->resolve_effective_manager_id_for_user($user_id);

    if ($mid <= 0) return esc_html((string)$atts['fallback']);



    $mgr_user_ids = (array)get_post_meta($mid, EUM_Manager_IDs::META_MANAGER_USERS, true);

    $mgr_user_ids = array_values(array_filter(array_map('intval', $mgr_user_ids)));

    if (empty($mgr_user_ids)) return esc_html((string)$atts['fallback']);



    $field = strtolower(trim((string)$atts['field']));

    $sep = (string)$atts['sep'];

    $first_only = ($atts['first'] === '1' || $atts['first'] === 'true');



    $out = [];

    foreach ($mgr_user_ids as $uid) {

      $u = get_user_by('id', $uid);

      if (!$u) continue;



      if ($field === 'ids') $out[] = (string)$uid;

      elseif ($field === 'emails') $out[] = (string)$u->user_email;

      else $out[] = (string)($u->display_name ?: $u->user_login);



      if ($first_only && !empty($out)) break;

    }



    if (empty($out)) return esc_html((string)$atts['fallback']);

    return esc_html(implode($sep, $out));

  }



  // --------------------------------------------------------------------------

  // Gate placeholder

  // --------------------------------------------------------------------------

  public function sc_gate($atts, $content = ''): string {

    return do_shortcode($content);

  }



  // --------------------------------------------------------------------------

  // Dynamic Links (unchanged; uses EUM_Dynamic_Links)

  // --------------------------------------------------------------------------

  public function sc_dynamic_links($atts): string {

    if (!is_user_logged_in()) return '';

    if (!class_exists('EUM_Dynamic_Links')) return '';



    $atts = shortcode_atts([

      'categories' => '',

      'tags' => '',

      'exclude_categories' => '',

      'exclude_tags' => '',

      'include_ids' => '',

      'exclude_ids' => '',

      'mode' => 'any',

      'limit' => '0',



      'layout' => 'div',

      'wrapper' => 'div',

      'item_wrap' => 'div',

      'title_tag' => '',

      'show_desc' => '0',

      'desc_wrap' => 'div',



      'wrapper_class' => 'eum-dynamic-links',

      'wrapper_style' => '',

      'item_class' => 'eum-dl-item',

      'item_style' => '',

      'link_class' => 'eum-dl-link',

      'link_style' => '',

      'desc_class' => 'eum-dl-desc',

      'desc_style' => '',

      'list_style' => 'none',

    ], (array)$atts, 'eum_dynamic_links');



    $uid = get_current_user_id();



    $access_context = class_exists('EUM_Dynamic_Links') && method_exists('EUM_Dynamic_Links', 'access_context')
      ? EUM_Dynamic_Links::access_context($uid)
      : ['job_path_ids' => [], 'group_ids' => []];

    $job_path_ids = array_values(array_filter(array_map('intval', (array)($access_context['job_path_ids'] ?? []))));

    $group_ids = array_values(array_filter(array_map('intval', (array)($access_context['group_ids'] ?? []))));



    $cat_ids    = $this->resolve_term_tokens(EUM_Dynamic_Links::TAX_CATEGORY, (string)$atts['categories']);

    $tag_ids    = $this->resolve_term_tokens(EUM_Dynamic_Links::TAX_TAG, (string)$atts['tags']);

    $ex_cat_ids = $this->resolve_term_tokens(EUM_Dynamic_Links::TAX_CATEGORY, (string)$atts['exclude_categories']);

    $ex_tag_ids = $this->resolve_term_tokens(EUM_Dynamic_Links::TAX_TAG, (string)$atts['exclude_tags']);



    $include_ids = $this->parse_int_list((string)$atts['include_ids']);

    $exclude_ids = $this->parse_int_list((string)$atts['exclude_ids']);



    $limit = max(0, intval($atts['limit']));

    $mode  = strtolower(trim((string)$atts['mode']));

    if (!in_array($mode, ['any','all'], true)) $mode = 'any';



    $tax_query = ['relation' => 'AND'];

    if (!empty($cat_ids))    $tax_query[] = ['taxonomy'=>EUM_Dynamic_Links::TAX_CATEGORY,'field'=>'term_id','terms'=>$cat_ids,'operator'=>'IN'];

    if (!empty($tag_ids))    $tax_query[] = ['taxonomy'=>EUM_Dynamic_Links::TAX_TAG,'field'=>'term_id','terms'=>$tag_ids,'operator'=>'IN'];

    if (!empty($ex_cat_ids)) $tax_query[] = ['taxonomy'=>EUM_Dynamic_Links::TAX_CATEGORY,'field'=>'term_id','terms'=>$ex_cat_ids,'operator'=>'NOT IN'];

    if (!empty($ex_tag_ids)) $tax_query[] = ['taxonomy'=>EUM_Dynamic_Links::TAX_TAG,'field'=>'term_id','terms'=>$ex_tag_ids,'operator'=>'NOT IN'];



    $args = [

      'post_type' => EUM_Dynamic_Links::CPT,

      'post_status' => 'publish',

      'numberposts' => 1000,

      'orderby' => 'title',

      'order' => 'ASC',

    ];

    if (count($tax_query) > 1) $args['tax_query'] = $tax_query;

    if (!empty($include_ids)) { $args['post__in'] = $include_ids; $args['orderby'] = 'post__in'; }

    if (!empty($exclude_ids)) { $args['post__not_in'] = $exclude_ids; }



    $posts = get_posts($args);

    if (!empty($posts) && empty($include_ids) && class_exists('EUM_Dynamic_Links') && method_exists('EUM_Dynamic_Links', 'sort_for_dashboard')) {

      $sort_context = !empty($cat_ids) ? $cat_ids : 0;
      $posts = EUM_Dynamic_Links::sort_for_dashboard($posts, $sort_context);

    }

    if (empty($posts)) return '';



    $layout = strtolower(trim((string)$atts['layout']));

    if (!in_array($layout, ['raw','div','ul','ol'], true)) $layout = 'div';



    $wrapper_tag = $this->safe_tag((string)$atts['wrapper'], 'div');

    $item_wrap   = $this->safe_tag((string)$atts['item_wrap'], 'div');

    $desc_wrap   = $this->safe_tag((string)$atts['desc_wrap'], 'div');



    $title_tag = trim((string)$atts['title_tag']);

    $title_tag = ($title_tag === '') ? '' : $this->safe_tag($title_tag, 'span');



    $show_desc = ($atts['show_desc'] === '1' || $atts['show_desc'] === 'true');



    $wrapper_class = $this->safe_classes((string)$atts['wrapper_class']);

    $item_class    = $this->safe_classes((string)$atts['item_class']);

    $link_class    = $this->safe_classes((string)$atts['link_class']);

    $desc_class    = $this->safe_classes((string)$atts['desc_class']);



    $wrapper_style = $this->safe_style((string)$atts['wrapper_style']);

    $item_style    = $this->safe_style((string)$atts['item_style']);

    $link_style    = $this->safe_style((string)$atts['link_style']);

    $desc_style    = $this->safe_style((string)$atts['desc_style']);



    $list_style_mode = strtolower(trim((string)$atts['list_style']));

    if (!in_array($list_style_mode, ['none','default'], true)) $list_style_mode = 'none';



    if (($layout === 'ul' || $layout === 'ol') && $list_style_mode === 'none') {

      $ls = 'list-style:none;margin:0;padding-left:0;';

      $wrapper_style = $wrapper_style ? $wrapper_style.';'.$ls : $ls;

    }



    $items_html = [];

    foreach ($posts as $p) {

      $pid = (int)$p->ID;



      $allowed_jobs   = (array)get_post_meta($pid, EUM_Dynamic_Links::META_ALLOWED_JOBS, true);

      $allowed_groups = (array)get_post_meta($pid, EUM_Dynamic_Links::META_ALLOWED_GROUP, true);

      $allowed_jobs   = array_values(array_filter(array_map('intval', $allowed_jobs)));

      $allowed_groups = array_values(array_filter(array_map('intval', $allowed_groups)));



      $require = ((string)get_post_meta($pid, EUM_Dynamic_Links::META_REQUIRE_RULES, true) === '1');



      $job_match = empty($allowed_jobs) ? false : (count(array_intersect($job_path_ids, $allowed_jobs)) > 0);

      $grp_match = empty($allowed_groups) ? false : (count(array_intersect($group_ids, $allowed_groups)) > 0);



      $has_any_rules = (!empty($allowed_jobs) || !empty($allowed_groups));



      if ($mode === 'all') {

        $ok = true;

        if (!empty($allowed_jobs)) $ok = $ok && $job_match;

        if (!empty($allowed_groups)) $ok = $ok && $grp_match;

        $allowed = $has_any_rules ? $ok : !$require;

      } else {

        $allowed = ($job_match || $grp_match);

        if (!$has_any_rules) $allowed = !$require;

      }



      if (!$allowed) continue;



      $url = (string)get_post_meta($pid, EUM_Dynamic_Links::META_URL, true);

      if ($url === '') continue;



      $target = (string)get_post_meta($pid, EUM_Dynamic_Links::META_TARGET, true);

      if (!in_array($target, ['_self','_blank'], true)) $target = '_self';



      $desc = (string)get_post_meta($pid, EUM_Dynamic_Links::META_DESC, true);



      $title = esc_html($p->post_title);

      if ($title_tag !== '') $title = '<'.$title_tag.'>'.$title.'</'.$title_tag.'>';



      $a_attrs = 'href="'.esc_url($url).'" target="'.esc_attr($target).'" rel="noopener"';

      if ($link_class !== '') $a_attrs .= ' class="'.esc_attr($link_class).'"';

      if ($link_style !== '') $a_attrs .= ' style="'.esc_attr($link_style).'"';



      $link_html = '<a '.$a_attrs.'>'.$title.'</a>';



      $desc_html = '';

      if ($show_desc && $desc !== '') {

        $d_attrs = '';

        if ($desc_class !== '') $d_attrs .= ' class="'.esc_attr($desc_class).'"';

        if ($desc_style !== '') $d_attrs .= ' style="'.esc_attr($desc_style).'"';

        $desc_html = '<'.$desc_wrap.$d_attrs.'>'.esc_html($desc).'</'.$desc_wrap.'>';

      }



      $item_attrs = '';

      if ($item_class !== '') $item_attrs .= ' class="'.esc_attr($item_class).'"';

      if ($item_style !== '') $item_attrs .= ' style="'.esc_attr($item_style).'"';



      $items_html[] = '<'.$item_wrap.$item_attrs.'>'.$link_html.$desc_html.'</'.$item_wrap.'>';



      if ($limit > 0 && count($items_html) >= $limit) break;

    }



    if (empty($items_html)) return '';



    $wrap_attrs = '';

    if ($wrapper_class !== '') $wrap_attrs .= ' class="'.esc_attr($wrapper_class).'"';

    if ($wrapper_style !== '') $wrap_attrs .= ' style="'.esc_attr($wrapper_style).'"';



    if ($layout === 'raw') return implode('', $items_html);

    if ($layout === 'div') return '<'.$wrapper_tag.$wrap_attrs.'>'.implode('', $items_html).'</'.$wrapper_tag.'>';



    $list_tag = $layout;

    $html = '<'.$list_tag.$wrap_attrs.'>';

    foreach ($items_html as $item) {

      if ($item_wrap === 'li') $html .= $item;

      else $html .= '<li class="eum-dl-li">'.$item.'</li>';

    }

    $html .= '</'.$list_tag.'>';

    return $html;

  }



  // --------------------------------------------------------------------------

  // Manager Messages shortcode

  // FIXES:

  // - Uses the NEW meta keys (banner/text color)

  // - Dismiss is NOT persisted (refresh shows it again)

  // --------------------------------------------------------------------------

  public function sc_manager_messages($atts): string {

    if (!is_user_logged_in()) return '';

    if (!class_exists('EUM_Manager_Messages')) return '';



    $atts = shortcode_atts([

      'limit' => '50',

      'max_width' => '1100px',

      'margin_px' => '16',

    ], (array)$atts, 'eum_manager_messages');



    $limit = max(1, (int)$atts['limit']);

    $uid = get_current_user_id();

    $now = time();



    // User context

    $job_leaf = 0;

    $job_path_anc = [];

    if (taxonomy_exists('eum_job_path')) {

      $ids = wp_get_object_terms($uid, 'eum_job_path', ['fields' => 'ids']);

      if (!is_wp_error($ids) && !empty($ids)) {

        $job_leaf = (int)$ids[0];

        $job_path_anc = array_map('intval', (array)get_ancestors($job_leaf, 'eum_job_path'));

      }

    }



    $group_ids = [];

    if (taxonomy_exists('eum_user_group')) {

      $g = wp_get_object_terms($uid, 'eum_user_group', ['fields' => 'ids']);

      if (!is_wp_error($g) && !empty($g)) $group_ids = array_values(array_filter(array_map('intval', (array)$g)));

    }



    $project_ids = [];

    if (taxonomy_exists('eum_project')) {

      $p = wp_get_object_terms($uid, 'eum_project', ['fields' => 'ids']);

      if (!is_wp_error($p) && !empty($p)) $project_ids = array_values(array_filter(array_map('intval', (array)$p)));

    }



    $msgs = get_posts([

      'post_type' => EUM_Manager_Messages::CPT,

      'post_status' => 'publish',

      'numberposts' => 2000,

      'orderby' => 'date',

      'order' => 'DESC',

    ]);

    if (empty($msgs)) return '';



    $out = [];

    $count = 0;



    foreach ($msgs as $m) {

      if ($count >= $limit) break;



      $pid = (int)$m->ID;



      $type = (string)get_post_meta($pid, EUM_Manager_Messages::META_TARGET_TYPE, true);

      $tid  = (int)get_post_meta($pid, EUM_Manager_Messages::META_TARGET_ID, true);



      $eff = (int)get_post_meta($pid, EUM_Manager_Messages::META_EFFECTIVE_TS, true);

      $exp = (int)get_post_meta($pid, EUM_Manager_Messages::META_EXPIRES_TS, true);



      if ($eff && $now < $eff) continue;

      if ($exp && $now >= $exp) continue;



      // Apply rules:

      // - jobpath: applies if selected jobpath is ancestor of user's leaf OR equals leaf

      // - group/project: applies if user has the term

      $applies = false;



      if ($type === 'jobpath') {

        if ($tid > 0 && $job_leaf > 0) {

          if ($tid === $job_leaf) $applies = true;

          else $applies = in_array($tid, $job_path_anc, true);

        }

      } elseif ($type === 'group') {

        $applies = ($tid > 0 && in_array($tid, $group_ids, true));

      } elseif ($type === 'project') {

        $applies = ($tid > 0 && in_array($tid, $project_ids, true));

      }



      if (!$applies) continue;



      $html = (string)get_post_meta($pid, EUM_Manager_Messages::META_MESSAGE_HTML, true);

      if (trim(wp_strip_all_tags($html)) === '') continue;



      // IMPORTANT: read the correct meta keys

      $bg  = (string)get_post_meta($pid, EUM_Manager_Messages::META_BANNER_COLOR, true);

      $txt = (string)get_post_meta($pid, EUM_Manager_Messages::META_TEXT_COLOR, true);



      if (trim($bg) === '')  $bg  = '#b91c1c';

      if (trim($txt) === '') $txt = '#ffffff';



      $author = (string)get_post_meta($pid, EUM_Manager_Messages::META_AUTHOR_NAME, true);

      if ($author === '') $author = 'Manager';



      $when = $eff ? gmdate('Y-m-d H:i', $eff).' UTC' : gmdate('Y-m-d H:i', strtotime($m->post_date_gmt ?: 'now')).' UTC';



      $dismissible = ((string)get_post_meta($pid, EUM_Manager_Messages::META_DISMISSIBLE, true) !== '0');



      $maxw = preg_replace('/[^0-9a-zA-Z\%\.\-]/', '', (string)$atts['max_width']);

      if ($maxw === '') $maxw = '1100px';



      $margin_px = max(0, (int)$atts['margin_px']);



      $dismiss_html = '';

      if ($dismissible) {

        $dismiss_html =

          '<button type="button" class="eum-mm-x" aria-label="Dismiss" '.

          'style="all:unset;cursor:pointer;color:'.esc_attr($txt).';font-family:Arial,sans-serif;font-size:18px;line-height:1;position:absolute;right:0;top:0;padding:12px 16px;">×</button>';

      }



      $out[] =

        '<div class="eum-mm-banner" style="width:100%;background:'.esc_attr($bg).';color:'.esc_attr($txt).';font-family:Arial,sans-serif;box-sizing:border-box;padding:16px 56px 12px 56px;position:relative;">'

          .$dismiss_html.

          '<div style="max-width:'.esc_attr($maxw).';margin:0 auto;text-align:center;padding:0 '.(int)$margin_px.'px;box-sizing:border-box;">'

            .'<div style="font-size:18px;line-height:1.35;white-space:normal;word-break:break-word;">'.$html.'</div>'

            .'<div style="margin-top:8px;font-size:12px;color:rgba(255,255,255,0.7);font-style:italic;">'

              .esc_html($author).' • '.esc_html($when)

            .'</div>'

          .'</div>'

        .'</div>';



      $count++;

    }



    if (empty($out)) return '';



    // Dismiss ONLY for this interaction (no storage). Refresh shows again.

    $js =

      '<script>

        (function(){

          document.addEventListener("click", function(e){

            var btn = e.target.closest && e.target.closest(".eum-mm-x");

            if(!btn) return;

            var banner = btn.closest(".eum-mm-banner");

            if(banner) banner.style.display = "none";

          });

        })();

      </script>';



    return implode("\n", $out) . $js;

  }



  // --------------------------------------------------------------------------

  // Helpers for Dynamic Links

  // --------------------------------------------------------------------------

  private function safe_classes(string $raw): string {

    $raw = trim($raw);

    if ($raw === '') return '';

    $raw = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $raw);

    $raw = preg_replace('/\s+/', ' ', $raw);

    return trim($raw);

  }



  private function safe_style(string $raw): string {

    $raw = trim($raw);

    if ($raw === '') return '';

    $raw = preg_replace('/[^a-zA-Z0-9\s\:\;\#\%\.\,\-\(\)\/]/', '', $raw);

    return trim($raw);

  }



  private function safe_tag(string $tag, string $default): string {

    $tag = strtolower(trim($tag));

    if ($tag === '') return $default;

    $allowed = ['div','span','p','ul','ol','li','section','article','nav','header','footer','strong','em','small','h1','h2','h3','h4','h5','h6'];

    return in_array($tag, $allowed, true) ? $tag : $default;

  }



  private function parse_list(string $raw): array {

    $raw = trim($raw);

    if ($raw === '') return [];

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    $parts = preg_split('/[,\n;|]+/', $raw);

    $parts = array_map('trim', (array)$parts);

    return array_values(array_filter($parts, fn($p) => $p !== ''));

  }



  private function parse_int_list(string $raw): array {

    $parts = $this->parse_list($raw);

    $ids = [];

    foreach ($parts as $p) if (ctype_digit($p)) $ids[] = (int)$p;

    return array_values(array_unique(array_filter($ids, fn($x) => $x > 0)));

  }



  private function resolve_term_tokens(string $tax, string $raw): array {

    $tokens = $this->parse_list($raw);

    if (empty($tokens) || !taxonomy_exists($tax)) return [];

    $is_h = is_taxonomy_hierarchical($tax);



    $out = [];

    foreach ($tokens as $tok) {

      $tok = trim((string)$tok);

      if ($tok === '') continue;



      if (ctype_digit($tok)) {

        $t = get_term((int)$tok, $tax);

        if ($t && !is_wp_error($t)) $out[] = (int)$t->term_id;

        continue;

      }



      if ($is_h && (strpos($tok, '>') !== false || strpos($tok, '→') !== false || strpos($tok, '/') !== false)) {

        $id = $this->resolve_hierarchical_path_to_term_id($tax, $tok);

        if ($id > 0) $out[] = $id;

        continue;

      }



      $t = get_term_by('slug', sanitize_title($tok), $tax);

      if ($t && !is_wp_error($t)) { $out[] = (int)$t->term_id; continue; }



      $t = get_term_by('name', $tok, $tax);

      if ($t && !is_wp_error($t)) $out[] = (int)$t->term_id;

    }



    return array_values(array_unique(array_filter(array_map('intval', $out))));

  }



  private function resolve_hierarchical_path_to_term_id(string $tax, string $path): int {

    $path = trim($path);

    if ($path === '') return 0;



    $normalized = str_replace(['→','/'], '>', $path);

    $parts = array_map('trim', explode('>', $normalized));

    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

    if (empty($parts)) return 0;



    $parent = 0;

    $leaf = 0;



    foreach ($parts as $name) {

      $existing = get_terms([

        'taxonomy' => $tax,

        'hide_empty' => false,

        'name' => $name,

        'parent' => $parent,

        'number' => 1,

      ]);



      if (!is_wp_error($existing) && !empty($existing)) {

        $leaf = (int)$existing[0]->term_id;

        $parent = $leaf;

        continue;

      }



      $t = get_term_by('name', $name, $tax);

      if ($t && !is_wp_error($t)) {

        $leaf = (int)$t->term_id;

        $parent = $leaf;

        continue;

      }



      return 0;

    }



    return (int)$leaf;

  }


  public function sc_user_data($atts): string {
    $atts = shortcode_atts([
      'key' => '',
      'user_id' => '',
      'format' => 'text', // text|raw|url|link|image|json
      'label' => '',
      'fallback' => '',
      'class' => '',
      'alt' => '',
      'source' => 'auto',
    ], (array)$atts, 'eum_user_data');

    $user_id = $this->resolve_user_id($atts);
    if ($user_id <= 0) return (string)$atts['fallback'];

    $key = trim((string)$atts['key']);
    if ($key === '') return (string)$atts['fallback'];

    $value = $this->resolve_user_data_value($user_id, $key, (string)$atts['source']);
    if ($value === null || $value === '') $value = (string)$atts['fallback'];

    $format = strtolower(trim((string)$atts['format']));
    $class = trim((string)$atts['class']);
    $label = (string)$atts['label'];
    $alt = (string)$atts['alt'];

    if (is_array($value) || is_object($value)) {
      $value = wp_json_encode($value);
      if ($format === 'json' || $format === 'raw') return (string)$value;
      return esc_html((string)$value);
    }

    $value = (string)$value;
    switch ($format) {
      case 'raw':
        return $value;
      case 'url':
        return esc_url($value);
      case 'link':
        $url = esc_url($value);
        if ($url === '') return '';
        $link_text = $label !== '' ? $label : $value;
        return '<a href="' . $url . '"' . ($class !== '' ? ' class="' . esc_attr($class) . '"' : '') . '>' . esc_html($link_text) . '</a>';
      case 'image':
        $src = esc_url($value);
        if ($src === '') return '';
        $alt_text = $alt !== '' ? $alt : ($label !== '' ? $label : '');
        return '<img src="' . $src . '" alt="' . esc_attr($alt_text) . '"' . ($class !== '' ? ' class="' . esc_attr($class) . '"' : '') . '>';
      case 'json':
        return esc_html(wp_json_encode($value));
      case 'text':
      default:
        return esc_html($value);
    }
  }

  private function resolve_user_data_value(int $user_id, string $key, string $source = 'auto') {
    $source = strtolower(trim($source));
    $raw_key = trim($key);
    $sanitized_key = sanitize_key($raw_key);
    $builtins = [
      'profile_image_url' => function() use ($user_id) { return class_exists('EUM_Profiles') ? EUM_Profiles::profile_image_url($user_id) : ''; },
      'job_path' => function() use ($user_id) { return class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'tax:eum_job_path') : ''; },
      'location_path' => function() use ($user_id) { return class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'tax:eum_location') : ''; },
      'job_company' => function() use ($user_id) { return class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'core:job_company') : ''; },
      'job_title' => function() use ($user_id) { return class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : ''; },
      'worker_type' => function() use ($user_id) { return class_exists('EUM_Profiles') ? EUM_Profiles::worker_type_badge_label($user_id) : ''; },
      'first_name' => function() use ($user_id) { return (string)get_user_meta($user_id, 'first_name', true); },
      'last_name' => function() use ($user_id) { return (string)get_user_meta($user_id, 'last_name', true); },
      'display_name' => function() use ($user_id) { $u = get_user_by('id', $user_id); return $u ? (string)$u->display_name : ''; },
      'user_email' => function() use ($user_id) { $u = get_user_by('id', $user_id); return $u ? (string)$u->user_email : ''; },
      'user_login' => function() use ($user_id) { $u = get_user_by('id', $user_id); return $u ? (string)$u->user_login : ''; },
      'logo' => function() use ($user_id) { return class_exists('EUM_Profiles') ? EUM_Profiles::get_field_raw_value($user_id, 'custom:logo') : ''; },
    ];

    if (isset($builtins[$raw_key])) return $builtins[$raw_key]();
    if (isset($builtins[$sanitized_key])) return $builtins[$sanitized_key]();

    $resolve_profiles = function(string $field_id) use ($user_id) {
      if (!class_exists('EUM_Profiles')) return null;
      return EUM_Profiles::get_field_raw_value($user_id, $field_id);
    };

    if ($source === 'field' || $source === 'fields') {
      return $resolve_profiles('custom:' . $sanitized_key);
    }
    if ($source === 'taxonomy') {
      if ($sanitized_key === 'job_path') return class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'tax:eum_job_path') : '';
      if ($sanitized_key === 'location' || $sanitized_key === 'location_path') return class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'tax:eum_location') : '';
    }
    if ($source === 'user') {
      $u = get_user_by('id', $user_id);
      if ($u && isset($u->$sanitized_key)) return $u->$sanitized_key;
      if ($u && $sanitized_key === 'username') return $u->user_login;
      return get_user_meta($user_id, $sanitized_key, true);
    }
    if ($source === 'meta') {
      $meta = get_user_meta($user_id, $sanitized_key, true);
      if ($meta !== '' || metadata_exists('user', $user_id, $sanitized_key)) return $meta;
      return null;
    }

    foreach ([
      'custom:' . $sanitized_key,
      'core:' . $sanitized_key,
      'tax:' . $sanitized_key,
    ] as $field_id) {
      $value = $resolve_profiles($field_id);
      if ($value !== null && $value !== '') return $value;
    }

    $meta = get_user_meta($user_id, $sanitized_key, true);
    if ($meta !== '' || metadata_exists('user', $user_id, $sanitized_key)) return $meta;

    $u = get_user_by('id', $user_id);
    if ($u) {
      if ($sanitized_key === 'username') return (string)$u->user_login;
      if (isset($u->$sanitized_key)) return $u->$sanitized_key;
    }

    return null;
  }

}
