<?php
/**
 * Plugin Name: LinkedFetch – gemte LinkedIn-opslag
 * Description: Modtager gemte LinkedIn-opslag fra Chrome-udvidelsen "LinkedIn til Notion" og viser dem i et dashboard på /linkedfetch (kun for administratorer). Med automatiske og egne tags samt filtre.
 * Version: 0.2.0
 * Author: Kenneth Hansen
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

const LF_VERSION = '0.2.0';
const LF_DB_VERSION = '2';

function lf_table() {
    global $wpdb;
    return $wpdb->prefix . 'linkedfetch_posts';
}

/* ---------- Installation og opgradering ---------- */

function lf_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = lf_table();
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_urn varchar(100) NOT NULL,
        nr int(11) DEFAULT NULL,
        author varchar(255) NOT NULL DEFAULT '',
        text longtext NOT NULL,
        url varchar(500) NOT NULL DEFAULT '',
        posted date DEFAULT NULL,
        image varchar(255) DEFAULT NULL,
        tags text NULL,
        tags_locked tinyint(1) NOT NULL DEFAULT 0,
        fetched datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY post_urn (post_urn),
        KEY nr (nr),
        KEY posted (posted)
    ) $c;");
    $first_tags = !is_array(get_option('linkedfetch_tags'));
    lf_get_tags(); // opretter standard-tags første gang
    update_option('linkedfetch_db_version', LF_DB_VERSION);
    lf_image_dir();
    if ($first_tags) {
        lf_retag_all();
    }
}

register_activation_hook(__FILE__, function () {
    lf_install();
    lf_rewrite();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

add_action('plugins_loaded', function () {
    if (get_option('linkedfetch_db_version') !== LF_DB_VERSION) {
        lf_install();
    }
});

/* ---------- Tags ---------- */

// Standard-tags. Nøgleord matcher begyndelsen af et ord (fx "agent" matcher "agenter" og "agentic").
function lf_default_tags() {
    return [
        ['name' => 'Prompting', 'color' => '#8b5cf6', 'keywords' => ['prompt']],
        ['name' => 'AI-agenter', 'color' => '#ec4899', 'keywords' => ['agent', 'agentic', 'automatiser', 'automation', 'copilot studio', 'skills']],
        ['name' => 'ChatGPT og OpenAI', 'color' => '#10b981', 'keywords' => ['chatgpt', 'openai', 'gpt-', 'gpt4', 'gpt5', 'customgpt', 'custom gpt', 'sora']],
        ['name' => 'Claude', 'color' => '#f97316', 'keywords' => ['claude', 'anthropic']],
        ['name' => 'Microsoft Copilot', 'color' => '#0ea5e9', 'keywords' => ['copilot', 'microsoft']],
        ['name' => 'Google og Gemini', 'color' => '#eab308', 'keywords' => ['gemini', 'notebooklm', 'nano banana', 'google']],
        ['name' => 'Eksamen og snyd', 'color' => '#ef4444', 'keywords' => ['eksamen', 'eksamens', 'exams', 'examination', 'snyd', 'plagiat', 'cheating', 'assessment', 'bedømmelse', 'karakter']],
        ['name' => 'Feedback', 'color' => '#14b8a6', 'keywords' => ['feedback', 'feed forward', 'feedforward']],
        ['name' => 'Didaktik og læringsdesign', 'color' => '#6366f1', 'keywords' => ['didaktik', 'didaktisk', 'læringsdesign', 'learning design', 'instructional design', 'pædagogik', 'pædagogisk', 'pedagogy', 'undervisningsdesign', 'læringsteori', 'gruppearbejde', 'group work']],
        ['name' => 'Online og digital læring', 'color' => '#06b6d4', 'keywords' => ['online', 'asynkron', 'e-læring', 'elearning', 'e-learning', 'blended', 'microlearning', 'moodle', 'digitale læremidler', 'lms', 'videoforelæsning']],
        ['name' => 'Forskning', 'color' => '#a855f7', 'keywords' => ['forskning', 'forsker', 'research', 'studie', 'study', 'peer-review', 'rapport', 'survey', 'literature review', 'ph.d', 'phd']],
        ['name' => 'AI literacy og kompetencer', 'color' => '#22c55e', 'keywords' => ['literacy', 'kompetence', 'kørekort', 'opkvalificering', 'upskill', 'kritisk tænkning', 'critical thinking', 'dannelse']],
        ['name' => 'Ledelse og strategi', 'color' => '#64748b', 'keywords' => ['ledelse', 'leder', 'strategi', 'strategy', 'bestyrelse', 'implementering', 'organisation', 'governance', 'transformation']],
        ['name' => 'Regulering og etik', 'color' => '#dc2626', 'keywords' => ['ai act', 'ai-forordning', 'forordning', 'lovkrav', 'compliance', 'regulering', 'regulation', 'gdpr', 'retningslinjer', 'guidelines', 'policy', 'etik', 'ethic']],
        ['name' => 'Grundskole og gymnasium', 'color' => '#f59e0b', 'keywords' => ['gymnasi', 'grundskole', 'folkeskole', 'skoleelev', 'elever', 'elevers', 'stx', 'hhx', 'htx', 'k-12', 'school']],
        ['name' => 'Videregående uddannelse', 'color' => '#3b82f6', 'keywords' => ['studerende', 'students', 'higher education', 'universitet', 'university', 'erhvervsakademi', 'professionshøjskole', 'videregående', 'professionsbachelor']],
        ['name' => 'Guides og værktøjer', 'color' => '#84cc16', 'keywords' => ['guide', 'tutorial', 'skabelon', 'template', 'værktøj', 'tool', 'gratis', 'bookmark', 'save this', 'tips']],
        ['name' => 'Arbejdsliv og job', 'color' => '#78716c', 'keywords' => ['jobsøgning', 'arbejdsmarked', 'arbejdsliv', 'ansættelse', 'career', 'karriere', 'cv']],
    ];
}

function lf_get_tags() {
    $t = get_option('linkedfetch_tags');
    if (!is_array($t)) {
        $t = lf_default_tags();
        update_option('linkedfetch_tags', $t, false);
    }
    return $t;
}

// Finder de tags, hvis nøgleord forekommer i teksten (nøgleord matcher begyndelsen af et ord)
function lf_match_tags($text, $defs) {
    $hay = ' ' . mb_strtolower((string) $text) . ' ';
    $out = [];
    foreach ($defs as $d) {
        foreach ((array) ($d['keywords'] ?? []) as $kw) {
            $kw = trim(mb_strtolower((string) $kw));
            if ($kw === '') {
                continue;
            }
            // Meget korte nøgleord (fx "cv") skal være et helt ord
            $end = mb_strlen($kw) <= 3 ? '(?![\p{L}\p{N}])' : '';
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . $end . '/u', $hay)) {
                $out[] = $d['name'];
                break;
            }
        }
    }
    return $out;
}

function lf_json($v) {
    return wp_json_encode(array_values($v), JSON_UNESCAPED_UNICODE);
}

function lf_decode_tags($s) {
    $a = json_decode((string) $s, true);
    return is_array($a) ? $a : [];
}

// Sætter automatiske tags på alle opslag, som brugeren ikke selv har ændret
function lf_retag_all() {
    global $wpdb;
    $t = lf_table();
    $defs = lf_get_tags();
    $rows = $wpdb->get_results("SELECT id, text FROM $t WHERE tags_locked = 0");
    foreach ($rows as $r) {
        $wpdb->update($t, ['tags' => lf_json(lf_match_tags($r->text, $defs))], ['id' => $r->id]);
    }
    return count($rows);
}

function lf_clean_tag_name($s) {
    $s = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $s)));
    return mb_substr($s, 0, 40);
}

function lf_clean_color($c) {
    return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? strtolower($c) : '#64748b';
}

/* ---------- Billeder ---------- */

// Mappe til billeder: wp-content/uploads/linkedfetch
function lf_image_dir() {
    $up = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'linkedfetch';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    if (!file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', "<?php\n// Stille.\n");
    }
    return ['dir' => $dir, 'url' => trailingslashit($up['baseurl']) . 'linkedfetch'];
}

// Henter et billede fra LinkedIn og gemmer det (som WebP, hvis serveren kan).
// Kun billeder fra LinkedIns billedserver accepteres.
function lf_save_image($url, $urn) {
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!$host || !preg_match('/(^|\.)licdn\.com$/i', $host)) {
        return null;
    }
    $res = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return null;
    }
    $type = wp_remote_retrieve_header($res, 'content-type');
    $body = wp_remote_retrieve_body($res);
    if (!$body || strpos((string) $type, 'image/') !== 0 || strlen($body) > 10 * 1024 * 1024) {
        return null;
    }

    $paths = lf_image_dir();
    $base = substr(md5($urn . wp_salt('auth')), 0, 20);

    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $img = @imagecreatefromstring($body);
        if ($img) {
            $file = $base . '.webp';
            $ok = imagewebp($img, $paths['dir'] . '/' . $file, 75);
            imagedestroy($img);
            if ($ok) {
                return $file;
            }
        }
    }
    $ext = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$type] ?? 'jpg';
    $file = $base . '.' . $ext;
    if (file_put_contents($paths['dir'] . '/' . $file, $body) === false) {
        return null;
    }
    return $file;
}

/* ---------- REST-API ---------- */

add_action('rest_api_init', function () {
    $admin = function () {
        return current_user_can('manage_options');
    };

    // Til udvidelsen: hvilke opslag findes allerede, og hvad er det højeste Nr?
    register_rest_route('linkedfetch/v1', '/ids', [
        'methods' => 'GET',
        'permission_callback' => $admin,
        'callback' => function () {
            global $wpdb;
            $t = lf_table();
            $rows = $wpdb->get_results("SELECT post_urn, image IS NOT NULL AS has_image FROM $t", ARRAY_A);
            return [
                'ids' => array_map(function ($r) { return $r['post_urn']; }, $rows),
                'noImage' => array_values(array_map(
                    function ($r) { return $r['post_urn']; },
                    array_filter($rows, function ($r) { return !$r['has_image']; })
                )),
                'maxNr' => (int) $wpdb->get_var("SELECT MAX(nr) FROM $t"),
                'version' => LF_VERSION,
            ];
        },
    ]);

    // Til udvidelsen: modtag opslag. Nye indsættes med automatiske tags;
    // eksisterende uden billede får billedet.
    register_rest_route('linkedfetch/v1', '/posts', [
        'methods' => 'POST',
        'permission_callback' => $admin,
        'callback' => function (WP_REST_Request $req) {
            global $wpdb;
            $t = lf_table();
            $posts = $req->get_param('posts');
            if (!is_array($posts) || count($posts) > 100) {
                return new WP_Error('lf_bad', 'Forventede op til 100 opslag.', ['status' => 400]);
            }
            $defs = lf_get_tags();
            $inserted = 0;
            $images = 0;
            foreach ($posts as $p) {
                $urn = sanitize_text_field($p['id'] ?? '');
                if (!preg_match('/^urn:li:(activity|share|ugcPost):\d+$/', $urn)) {
                    continue;
                }
                $existing = $wpdb->get_row($wpdb->prepare("SELECT id, image FROM $t WHERE post_urn = %s", $urn));
                $imageUrl = esc_url_raw($p['image'] ?? '');

                if ($existing) {
                    if (!$existing->image && $imageUrl) {
                        $file = lf_save_image($imageUrl, $urn);
                        if ($file) {
                            $wpdb->update($t, ['image' => $file], ['id' => $existing->id]);
                            $images++;
                        }
                    }
                    continue;
                }

                $text = sanitize_textarea_field($p['text'] ?? '');
                $posted = sanitize_text_field($p['date'] ?? '');
                $file = $imageUrl ? lf_save_image($imageUrl, $urn) : null;
                $ok = $wpdb->insert($t, [
                    'post_urn' => $urn,
                    'nr' => isset($p['nr']) ? (int) $p['nr'] : null,
                    'author' => mb_substr(sanitize_text_field($p['author'] ?? ''), 0, 255),
                    'text' => $text,
                    'url' => esc_url_raw($p['url'] ?? ''),
                    'posted' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted) ? $posted : null,
                    'image' => $file,
                    'tags' => lf_json(lf_match_tags($text, $defs)),
                    'tags_locked' => 0,
                    'fetched' => current_time('mysql'),
                ]);
                if ($ok) {
                    $inserted++;
                    if ($file) {
                        $images++;
                    }
                }
            }
            return ['inserted' => $inserted, 'images' => $images];
        },
    ]);

    // Til dashboardet: sæt tags på ét opslag (eller nulstil til automatiske tags)
    register_rest_route('linkedfetch/v1', '/post-tags', [
        'methods' => 'POST',
        'permission_callback' => $admin,
        'callback' => function (WP_REST_Request $req) {
            global $wpdb;
            $t = lf_table();
            $urn = sanitize_text_field($req->get_param('id'));
            $row = $wpdb->get_row($wpdb->prepare("SELECT id, text FROM $t WHERE post_urn = %s", $urn));
            if (!$row) {
                return new WP_Error('lf_404', 'Opslaget findes ikke.', ['status' => 404]);
            }
            if ($req->get_param('auto')) {
                $tags = lf_match_tags($row->text, lf_get_tags());
                $wpdb->update($t, ['tags' => lf_json($tags), 'tags_locked' => 0], ['id' => $row->id]);
                return ['tags' => $tags, 'locked' => false];
            }
            $names = array_column(lf_get_tags(), 'name');
            $tags = array_values(array_unique(array_filter(
                array_map('lf_clean_tag_name', (array) $req->get_param('tags')),
                function ($n) use ($names) { return in_array($n, $names, true); }
            )));
            $wpdb->update($t, ['tags' => lf_json($tags), 'tags_locked' => 1], ['id' => $row->id]);
            return ['tags' => $tags, 'locked' => true];
        },
    ]);

    // Til dashboardet: gem hele tag-listen (navn, farve, nøgleord) og opdater automatiske tags.
    // Omdøbte tags (oldName) og slettede tags rettes også på opslag, brugeren selv har tagget.
    register_rest_route('linkedfetch/v1', '/tags', [
        'methods' => 'POST',
        'permission_callback' => $admin,
        'callback' => function (WP_REST_Request $req) {
            global $wpdb;
            $t = lf_table();
            $in = (array) $req->get_param('tags');
            $old = array_column(lf_get_tags(), 'name');
            $new = [];
            $renames = [];
            foreach ($in as $d) {
                $name = lf_clean_tag_name($d['name'] ?? '');
                if ($name === '' || isset($new[$name])) {
                    continue;
                }
                $kws = array_values(array_filter(array_map(function ($k) {
                    return mb_substr(trim(wp_strip_all_tags((string) $k)), 0, 60);
                }, (array) ($d['keywords'] ?? []))));
                $new[$name] = ['name' => $name, 'color' => lf_clean_color($d['color'] ?? ''), 'keywords' => $kws];
                $oldName = lf_clean_tag_name($d['oldName'] ?? '');
                if ($oldName !== '' && $oldName !== $name && in_array($oldName, $old, true)) {
                    $renames[$oldName] = $name;
                }
            }
            update_option('linkedfetch_tags', array_values($new), false);

            // Ret omdøbte og slettede tags på opslag, som brugeren selv har tagget
            $keep = array_keys($new);
            $rows = $wpdb->get_results("SELECT id, tags FROM $t WHERE tags_locked = 1");
            foreach ($rows as $r) {
                $tags = array_map(function ($n) use ($renames) { return $renames[$n] ?? $n; }, lf_decode_tags($r->tags));
                $tags = array_values(array_unique(array_filter($tags, function ($n) use ($keep) {
                    return in_array($n, $keep, true);
                })));
                $wpdb->update($t, ['tags' => lf_json($tags)], ['id' => $r->id]);
            }
            $retagged = lf_retag_all();
            return ['tags' => array_values($new), 'retagged' => $retagged];
        },
    ]);
});

/* ---------- Dashboard på /linkedfetch ---------- */

function lf_rewrite() {
    add_rewrite_rule('^linkedfetch/?$', 'index.php?linkedfetch=1', 'top');
}
add_action('init', 'lf_rewrite');

add_filter('query_vars', function ($vars) {
    $vars[] = 'linkedfetch';
    return $vars;
});

add_action('template_redirect', function () {
    if (!get_query_var('linkedfetch')) {
        return;
    }
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    if (!current_user_can('manage_options')) {
        wp_die('Kun for administratorer.', 403);
    }
    nocache_headers();
    lf_render_dashboard();
    exit;
});

function lf_render_dashboard() {
    global $wpdb;
    $t = lf_table();
    $rows = $wpdb->get_results("SELECT post_urn, nr, author, text, url, posted, image, tags, tags_locked FROM $t ORDER BY nr DESC, id DESC", ARRAY_A);
    $imgBase = lf_image_dir()['url'];
    $data = array_map(function ($r) use ($imgBase) {
        return [
            'id' => $r['post_urn'],
            'nr' => (int) $r['nr'],
            'a' => $r['author'],
            't' => $r['text'],
            'u' => $r['url'],
            'd' => $r['posted'],
            'i' => $r['image'] ? $imgBase . '/' . rawurlencode($r['image']) : null,
            'g' => lf_decode_tags($r['tags']),
            'l' => (bool) $r['tags_locked'],
        ];
    }, $rows);
    $boot = [
        'posts' => $data,
        'tags' => lf_get_tags(),
        'api' => esc_url_raw(rest_url('linkedfetch/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'version' => LF_VERSION,
    ];
    $json = wp_json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    ?><!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>LinkedFetch – gemte opslag</title>
<style>
  :root { --bg:#0f1318; --card:#171d24; --line:#27303b; --text:#e6ebf0; --muted:#8c99a8; --accent:#3d8bfd; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; }
  header { position:sticky; top:0; z-index:5; background:rgba(15,19,24,.96); backdrop-filter:blur(6px); border-bottom:1px solid var(--line); padding:12px 20px 10px; }
  .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .row + .row { margin-top:10px; }
  h1 { font-size:20px; margin:0; font-weight:650; }
  .ver { color:var(--muted); font-size:12px; }
  .count { background:#222b35; border-radius:99px; padding:2px 10px; color:var(--muted); font-size:13px; }
  .spacer { flex:1; }
  input, select, button { font:inherit; }
  input[type=search], input[type=date], select { background:#11171d; color:var(--text); border:1px solid var(--line); border-radius:8px; padding:6px 10px; }
  input[type=search] { width:min(300px,100%); }
  .btn { background:#1d252e; color:var(--text); border:1px solid var(--line); border-radius:8px; padding:6px 12px; cursor:pointer; }
  .btn:hover { border-color:#3a4655; }
  .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
  .lbl { color:var(--muted); font-size:13px; }
  .chips { display:flex; gap:6px; flex-wrap:wrap; }
  .chip { --c:#64748b; border:1px solid color-mix(in srgb, var(--c) 55%, transparent); background:color-mix(in srgb, var(--c) 14%, transparent);
          color:var(--text); border-radius:99px; padding:2px 10px; font-size:12px; cursor:pointer; white-space:nowrap; }
  .chip.on { background:var(--c); border-color:var(--c); color:#fff; }
  .chip .n { opacity:.65; margin-left:4px; }
  main { max-width:1600px; margin:0 auto; padding:18px 20px; columns:4 250px; column-gap:14px; }
  main.big { columns:2 380px; column-gap:20px; font-size:15px; }
  .card { break-inside:avoid; background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px 14px; margin:0 0 14px; }
  .head { display:flex; gap:8px; align-items:center; margin-bottom:6px; }
  .av { width:28px; height:28px; border-radius:50%; background:#2a3542; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px; color:#b8c4d0; flex:none; }
  .who { font-weight:600; font-size:13px; line-height:1.3; }
  .meta { color:var(--muted); font-size:12px; }
  .text { white-space:pre-wrap; word-wrap:break-word; }
  .text.clamp { display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden; }
  main.big .text.clamp { -webkit-line-clamp:7; }
  .more { background:none; border:0; color:var(--accent); padding:2px 0; cursor:pointer; font-weight:600; font-size:13px; }
  .img { margin-top:8px; width:100%; max-height:170px; object-fit:cover; border-radius:8px; display:block; background:#0b0f13; cursor:zoom-in; }
  .img.full, main.big .img { max-height:none; object-fit:contain; cursor:zoom-out; }
  .tags { display:flex; gap:5px; flex-wrap:wrap; margin-top:8px; align-items:center; }
  .tags .chip { cursor:default; font-size:11px; padding:1px 8px; }
  .addtag { background:none; border:1px dashed #3a4655; color:var(--muted); border-radius:99px; padding:0 8px; font-size:11px; cursor:pointer; }
  .foot { margin-top:8px; font-size:12px; display:flex; justify-content:space-between; }
  a { color:var(--accent); text-decoration:none; }
  a:hover { text-decoration:underline; }
  .empty { color:var(--muted); text-align:center; padding:60px 0; column-span:all; }
  mark { background:#5a4a12; color:inherit; border-radius:3px; }
  .pop { position:absolute; z-index:20; background:#1b222b; border:1px solid #3a4655; border-radius:10px; padding:10px; width:250px; box-shadow:0 10px 30px rgba(0,0,0,.5); }
  .pop label { display:flex; gap:6px; align-items:center; padding:2px 0; font-size:13px; cursor:pointer; }
  .pop .list { max-height:260px; overflow:auto; margin-bottom:8px; }
  .pop input[type=text] { width:100%; background:#11171d; color:var(--text); border:1px solid var(--line); border-radius:6px; padding:5px 8px; margin-bottom:8px; }
  .pop .acts { display:flex; gap:6px; justify-content:space-between; align-items:center; }
  .pop .reset { color:var(--muted); font-size:12px; background:none; border:0; cursor:pointer; padding:0; }
  dialog { background:#151b22; color:var(--text); border:1px solid #3a4655; border-radius:14px; width:min(900px,95vw); padding:0; }
  dialog::backdrop { background:rgba(0,0,0,.6); }
  .dlg-h { padding:14px 18px; border-bottom:1px solid var(--line); display:flex; align-items:center; gap:10px; }
  .dlg-b { padding:12px 18px; max-height:65vh; overflow:auto; }
  .dlg-f { padding:12px 18px; border-top:1px solid var(--line); display:flex; gap:8px; justify-content:flex-end; align-items:center; }
  .trow { display:grid; grid-template-columns:36px 190px 1fr 46px 32px; gap:8px; align-items:start; padding:6px 0; border-bottom:1px solid #1f2731; }
  .trow input[type=text], .trow textarea { width:100%; background:#11171d; color:var(--text); border:1px solid var(--line); border-radius:6px; padding:5px 8px; font:inherit; font-size:13px; }
  .trow textarea { min-height:34px; resize:vertical; }
  .trow input[type=color] { width:34px; height:30px; border:0; background:none; padding:0; }
  .trow .n { color:var(--muted); font-size:12px; padding-top:6px; text-align:right; }
  .del { background:none; border:0; color:#ef6b6b; cursor:pointer; font-size:18px; line-height:1; padding-top:4px; }
  .hint { color:var(--muted); font-size:12px; }
  #toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:#22303d; border:1px solid #3a4655; padding:8px 14px; border-radius:8px; display:none; z-index:30; }
</style>
</head>
<body>
<header>
  <div class="row">
    <h1>LinkedFetch</h1>
    <span class="count" id="count"></span>
    <span class="ver">v<?php echo esc_html(LF_VERSION); ?></span>
    <span class="spacer"></span>
    <input type="search" id="q" placeholder="Søg i tekst og forfatter…">
    <select id="author"><option value="">Alle forfattere</option></select>
    <select id="sort">
      <option value="nr">Senest gemt</option>
      <option value="date">Nyeste opslag</option>
      <option value="old">Ældste opslag</option>
    </select>
    <button class="btn" id="size" title="Skift kortstørrelse">Store kort</button>
    <button class="btn" id="manage">Administrer tags</button>
  </div>
  <div class="row">
    <span class="lbl">Opslagsdato</span>
    <select id="period">
      <option value="">Alle</option>
      <option value="7">Seneste 7 dage</option>
      <option value="30">Seneste 30 dage</option>
      <option value="90">Seneste 3 måneder</option>
      <option value="365">Seneste år</option>
      <option value="year">I år</option>
      <option value="custom">Vælg datoer…</option>
    </select>
    <span id="customDates" hidden>
      <input type="date" id="from" aria-label="Fra dato"> – <input type="date" id="to" aria-label="Til dato">
    </span>
    <label class="lbl"><input type="checkbox" id="imgOnly"> Kun med billede</label>
    <span class="spacer"></span>
    <span class="lbl">Tags skal</span>
    <select id="tagMode"><option value="any">matche et af de valgte</option><option value="all">matche alle valgte</option></select>
    <button class="btn" id="clear">Ryd filtre</button>
  </div>
  <div class="row"><div class="chips" id="tagChips"></div></div>
</header>
<main id="list"></main>
<div id="toast"></div>

<dialog id="dlg">
  <div class="dlg-h"><strong>Administrer tags</strong><span class="spacer"></span><button class="btn" id="dlgClose">Luk</button></div>
  <div class="dlg-b">
    <p class="hint">Nøgleord adskilles med komma. Et nøgleord matcher begyndelsen af et ord i opslaget, fx matcher "agent" både "agenter" og "agentic". Nøgleord på 3 tegn eller færre skal være et helt ord. Opslag, hvor du selv har ændret tags, røres ikke af de automatiske tags.</p>
    <div id="tagRows"></div>
    <button class="btn" id="addRow" style="margin-top:10px">+ Nyt tag</button>
  </div>
  <div class="dlg-f"><span class="hint" id="dlgMsg"></span><button class="btn primary" id="dlgSave">Gem og opdater automatiske tags</button></div>
</dialog>

<script id="lf-data" type="application/json"><?php echo $json; ?></script>
<script>
(() => {
  const boot = JSON.parse(document.getElementById('lf-data').textContent);
  const all = boot.posts;
  let defs = boot.tags;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const fmt = (d) => d ? new Date(d + 'T12:00:00').toLocaleDateString('da-DK', { day:'numeric', month:'short', year:'numeric' }) : '';
  const color = (name) => (defs.find((d) => d.name === name) || {}).color || '#64748b';
  const store = { get(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
                  set(k, v) { try { localStorage.setItem(k, v); } catch (_) {} } };

  function toast(msg) { const t = $('toast'); t.textContent = msg; t.style.display = 'block'; clearTimeout(t._h); t._h = setTimeout(() => t.style.display = 'none', 2600); }

  async function api(path, body) {
    const r = await fetch(boot.api + path, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.message || ('Fejl ' + r.status));
    return j;
  }

  /* ---- Filtre ---- */
  const selTags = new Set();

  function buildAuthors() {
    const counts = {};
    all.forEach((p) => { if (p.a) counts[p.a] = (counts[p.a] || 0) + 1; });
    Object.entries(counts).sort((x, y) => y[1] - x[1]).forEach(([a, n]) => {
      const o = document.createElement('option'); o.value = a; o.textContent = `${a} (${n})`; $('author').appendChild(o);
    });
  }

  function buildTagChips() {
    const counts = {};
    all.forEach((p) => p.g.forEach((g) => counts[g] = (counts[g] || 0) + 1));
    const untagged = all.filter((p) => !p.g.length).length;
    const box = $('tagChips'); box.innerHTML = '';
    const mk = (key, label, c, n) => {
      const b = document.createElement('button');
      b.className = 'chip' + (selTags.has(key) ? ' on' : ''); b.style.setProperty('--c', c);
      b.innerHTML = `${esc(label)}<span class="n">${n}</span>`;
      b.onclick = () => { selTags.has(key) ? selTags.delete(key) : selTags.add(key); buildTagChips(); apply(); };
      box.appendChild(b);
    };
    [...defs].sort((x, y) => (counts[y.name] || 0) - (counts[x.name] || 0)).forEach((d) => mk(d.name, d.name, d.color, counts[d.name] || 0));
    mk('__none', 'Uden tags', '#475569', untagged);
  }

  function dateRange() {
    const v = $('period').value, today = new Date();
    const iso = (d) => d.toISOString().slice(0, 10);
    if (!v) return [null, null];
    if (v === 'custom') return [$('from').value || null, $('to').value || null];
    if (v === 'year') return [today.getFullYear() + '-01-01', null];
    const d = new Date(today); d.setDate(d.getDate() - Number(v)); return [iso(d), null];
  }

  let shown = [], rendered = 0;
  const PAGE = 40;

  function apply() {
    const q = $('q').value.trim().toLowerCase();
    const a = $('author').value, s = $('sort').value, imgOnly = $('imgOnly').checked;
    const [from, to] = dateRange();
    const tags = [...selTags], mode = $('tagMode').value;
    shown = all.filter((p) => {
      if (a && p.a !== a) return false;
      if (imgOnly && !p.i) return false;
      if (from && (!p.d || p.d < from)) return false;
      if (to && (!p.d || p.d > to)) return false;
      if (q && !(p.t + ' ' + p.a).toLowerCase().includes(q)) return false;
      if (tags.length) {
        const has = (t) => t === '__none' ? !p.g.length : p.g.includes(t);
        if (mode === 'all' ? !tags.every(has) : !tags.some(has)) return false;
      }
      return true;
    });
    if (s === 'date') shown.sort((x, y) => (y.d || '').localeCompare(x.d || ''));
    else if (s === 'old') shown.sort((x, y) => (x.d || '').localeCompare(y.d || ''));
    else shown.sort((x, y) => y.nr - x.nr);
    $('count').textContent = shown.length === all.length ? `${all.length} opslag` : `${shown.length} af ${all.length}`;
    $('list').innerHTML = shown.length ? '' : '<div class="empty">Ingen opslag matcher filtrene.</div>';
    rendered = 0;
    renderMore();
  }

  /* ---- Kort ---- */
  function highlight(text, q) {
    const safe = esc(text);
    if (!q) return safe;
    const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return safe.replace(re, '<mark>$1</mark>');
  }

  function tagHtml(p) {
    return p.g.map((g) => `<span class="chip" style="--c:${esc(color(g))}">${esc(g)}</span>`).join('') +
      `<button class="addtag" title="Rediger tags">${p.g.length ? 'Rediger' : '+ tag'}</button>`;
  }

  function card(p, q) {
    const el = document.createElement('article');
    el.className = 'card';
    el.innerHTML = `
      <div class="head">
        <div class="av">${esc((p.a || '?').trim().charAt(0).toUpperCase())}</div>
        <div><div class="who">${highlight(p.a || 'Ukendt', q)}</div>
        <div class="meta">${fmt(p.d)}${p.nr ? ' · nr. ' + p.nr : ''}</div></div>
      </div>
      <div class="text clamp">${highlight(p.t, q)}</div>
      <button class="more" hidden>Se mere</button>
      ${p.i ? `<img class="img" loading="lazy" src="${esc(p.i)}" alt="">` : ''}
      <div class="tags">${tagHtml(p)}</div>
      <div class="foot"><a href="${esc(p.u)}" target="_blank" rel="noopener">Åbn på LinkedIn</a></div>`;
    const text = el.querySelector('.text'), more = el.querySelector('.more');
    requestAnimationFrame(() => { if (text.scrollHeight > text.clientHeight + 2) more.hidden = false; });
    more.onclick = () => { const c = text.classList.toggle('clamp'); more.textContent = c ? 'Se mere' : 'Se mindre'; };
    const img = el.querySelector('.img'); if (img) img.onclick = () => img.classList.toggle('full');
    const wire = () => el.querySelector('.addtag').onclick = (e) => openTagPop(p, e.currentTarget, () => { el.querySelector('.tags').innerHTML = tagHtml(p); wire(); });
    wire();
    return el;
  }

  function renderMore() {
    const q = $('q').value.trim();
    const frag = document.createDocumentFragment();
    shown.slice(rendered, rendered + PAGE).forEach((p) => frag.appendChild(card(p, q)));
    rendered += PAGE;
    $('list').appendChild(frag);
  }

  /* ---- Tags på ét kort ---- */
  let pop = null;
  function closePop() { if (pop) { pop.remove(); pop = null; } }
  document.addEventListener('click', (e) => { if (pop && !pop.contains(e.target) && !e.target.classList.contains('addtag')) closePop(); });

  function openTagPop(p, anchor, done) {
    closePop();
    pop = document.createElement('div'); pop.className = 'pop';
    const chosen = new Set(p.g);
    const list = () => defs.map((d) => `<label><input type="checkbox" value="${esc(d.name)}" ${chosen.has(d.name) ? 'checked' : ''}>
      <span class="chip" style="--c:${esc(d.color)}">${esc(d.name)}</span></label>`).join('');
    pop.innerHTML = `<input type="text" placeholder="Nyt tag – tryk Enter"><div class="list">${list()}</div>
      <div class="acts"><button class="reset" title="Brug de automatiske tags igen">${p.l ? 'Brug automatiske tags' : 'Automatiske tags'}</button>
      <button class="btn primary">Gem</button></div>`;
    document.body.appendChild(pop);
    const r = anchor.getBoundingClientRect();
    pop.style.top = (scrollY + r.bottom + 6) + 'px';
    pop.style.left = Math.min(scrollX + r.left, scrollX + innerWidth - 270) + 'px';
    const sync = () => pop.querySelectorAll('.list input').forEach((c) => c.onchange = () => c.checked ? chosen.add(c.value) : chosen.delete(c.value));
    sync();
    const inp = pop.querySelector('input[type=text]'); inp.focus();
    inp.onkeydown = async (e) => {
      if (e.key !== 'Enter') return;
      const name = inp.value.trim().replace(/\s+/g, ' ').slice(0, 40);
      if (!name) return;
      if (!defs.some((d) => d.name === name)) {
        const palette = ['#8b5cf6','#ec4899','#10b981','#f97316','#0ea5e9','#eab308','#14b8a6','#6366f1','#a855f7','#22c55e'];
        try {
          const res = await api('tags', { tags: defs.map((d) => ({ ...d, oldName: d.name })).concat([{ name, color: palette[defs.length % palette.length], keywords: [] }]) });
          defs = res.tags; buildTagChips();
        } catch (err) { toast('Kunne ikke oprette tag: ' + err.message); return; }
      }
      chosen.add(name); inp.value = '';
      pop.querySelector('.list').innerHTML = list(); sync();
    };
    pop.querySelector('.btn.primary').onclick = async () => {
      try {
        const res = await api('post-tags', { id: p.id, tags: [...chosen] });
        p.g = res.tags; p.l = res.locked; closePop(); done(); buildTagChips(); toast('Tags gemt');
      } catch (err) { toast('Kunne ikke gemme: ' + err.message); }
    };
    pop.querySelector('.reset').onclick = async () => {
      try {
        const res = await api('post-tags', { id: p.id, auto: true });
        p.g = res.tags; p.l = res.locked; closePop(); done(); buildTagChips(); toast('Automatiske tags sat');
      } catch (err) { toast('Fejl: ' + err.message); }
    };
  }

  /* ---- Administrer tags ---- */
  function tagRow(d) {
    const n = all.filter((p) => p.g.includes(d.name)).length;
    const row = document.createElement('div'); row.className = 'trow';
    row.dataset.old = d.name || '';
    row.innerHTML = `<input type="color" value="${esc(d.color || '#64748b')}" aria-label="Farve">
      <input type="text" class="name" value="${esc(d.name || '')}" placeholder="Navn" maxlength="40">
      <textarea class="kw" placeholder="Nøgleord, adskilt med komma">${esc((d.keywords || []).join(', '))}</textarea>
      <span class="n">${d.name ? n : ''}</span><button class="del" title="Slet tag">×</button>`;
    row.querySelector('.del').onclick = () => row.remove();
    return row;
  }
  $('manage').onclick = () => {
    const box = $('tagRows'); box.innerHTML = '';
    defs.forEach((d) => box.appendChild(tagRow(d)));
    $('dlgMsg').textContent = ''; $('dlg').showModal();
  };
  $('dlgClose').onclick = () => $('dlg').close();
  $('addRow').onclick = () => { const r = tagRow({ color: '#64748b', keywords: [] }); $('tagRows').appendChild(r); r.querySelector('.name').focus(); };
  $('dlgSave').onclick = async () => {
    const tags = [...$('tagRows').querySelectorAll('.trow')].map((r) => ({
      name: r.querySelector('.name').value.trim(),
      oldName: r.dataset.old,
      color: r.querySelector('input[type=color]').value,
      keywords: r.querySelector('.kw').value.split(',').map((s) => s.trim()).filter(Boolean)
    })).filter((t) => t.name);
    $('dlgMsg').textContent = 'Gemmer og opdaterer…';
    try {
      const res = await api('tags', { tags });
      $('dlgMsg').textContent = `Gemt. ${res.retagged} opslag fik opdateret automatiske tags. Siden genindlæses…`;
      setTimeout(() => location.reload(), 900);
    } catch (err) { $('dlgMsg').textContent = 'Fejl: ' + err.message; }
  };

  /* ---- Opsætning ---- */
  function setSize(big) {
    $('list').classList.toggle('big', big);
    $('size').textContent = big ? 'Små kort' : 'Store kort';
    store.set('lf-size', big ? 'big' : 'small');
  }
  $('size').onclick = () => setSize(!$('list').classList.contains('big'));
  setSize(store.get('lf-size') === 'big');

  let timer;
  $('q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(apply, 200); });
  ['author', 'sort', 'tagMode', 'from', 'to', 'imgOnly'].forEach((id) => $(id).addEventListener('change', apply));
  $('period').addEventListener('change', () => { $('customDates').hidden = $('period').value !== 'custom'; apply(); });
  $('clear').onclick = () => {
    $('q').value = ''; $('author').value = ''; $('period').value = ''; $('customDates').hidden = true;
    $('from').value = ''; $('to').value = ''; $('imgOnly').checked = false; selTags.clear(); buildTagChips(); apply();
  };
  window.addEventListener('scroll', () => {
    if (rendered < shown.length && innerHeight + scrollY > document.body.offsetHeight - 1200) renderMore();
  });

  buildAuthors();
  buildTagChips();
  apply();
})();
</script>
</body>
</html>
<?php
}
