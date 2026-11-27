<?php
/**
 * Plugin Name: LinkedFetch – gemte LinkedIn-opslag
 * Description: Modtager gemte LinkedIn-opslag fra Chrome-udvidelsen "LinkedIn til Notion" og viser dem i et dashboard på /linkedfetch (kun for administratorer).
 * Version: 0.1.0
 * Author: Kenneth Hansen
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

const LF_VERSION = '0.1.0';
const LF_DB_VERSION = '1';

function lf_table() {
    global $wpdb;
    return $wpdb->prefix . 'linkedfetch_posts';
}

/* ---------- Installation ---------- */

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
        fetched datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY post_urn (post_urn),
        KEY nr (nr),
        KEY posted (posted)
    ) $c;");
    update_option('linkedfetch_db_version', LF_DB_VERSION);
    lf_image_dir();
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

/* ---------- REST-API til Chrome-udvidelsen ---------- */

add_action('rest_api_init', function () {
    $admin = function () {
        return current_user_can('manage_options');
    };

    // Hvilke opslag findes allerede, og hvad er det højeste Nr?
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

    // Modtag opslag. Nye indsættes; eksisterende uden billede får billedet.
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

                $posted = sanitize_text_field($p['date'] ?? '');
                $file = $imageUrl ? lf_save_image($imageUrl, $urn) : null;
                $ok = $wpdb->insert($t, [
                    'post_urn' => $urn,
                    'nr' => isset($p['nr']) ? (int) $p['nr'] : null,
                    'author' => mb_substr(sanitize_text_field($p['author'] ?? ''), 0, 255),
                    'text' => sanitize_textarea_field($p['text'] ?? ''),
                    'url' => esc_url_raw($p['url'] ?? ''),
                    'posted' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted) ? $posted : null,
                    'image' => $file,
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
    $rows = $wpdb->get_results("SELECT post_urn, nr, author, text, url, posted, image FROM $t ORDER BY nr DESC, id DESC", ARRAY_A);
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
        ];
    }, $rows);
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $count = count($data);
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
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
  header { position:sticky; top:0; z-index:5; background:rgba(15,19,24,.95); backdrop-filter:blur(6px);
           border-bottom:1px solid var(--line); padding:14px 24px; display:flex; gap:14px; align-items:center; flex-wrap:wrap; }
  h1 { font-size:22px; margin:0; font-weight:650; }
  .count { background:#222b35; border-radius:99px; padding:2px 10px; color:var(--muted); font-size:13px; }
  .spacer { flex:1; }
  input, select { background:#11171d; color:var(--text); border:1px solid var(--line); border-radius:8px; padding:8px 12px; font:inherit; }
  input[type=search] { width:min(320px,100%); }
  main { max-width:1200px; margin:0 auto; padding:24px; columns:2 360px; column-gap:20px; }
  .card { break-inside:avoid; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px 20px; margin:0 0 20px; }
  .head { display:flex; gap:10px; align-items:center; margin-bottom:10px; }
  .av { width:38px; height:38px; border-radius:50%; background:#2a3542; display:flex; align-items:center; justify-content:center; font-weight:600; color:#b8c4d0; flex:none; }
  .who { font-weight:600; }
  .meta { color:var(--muted); font-size:13px; }
  .text { white-space:pre-wrap; word-wrap:break-word; }
  .text.clamp { display:-webkit-box; -webkit-line-clamp:7; -webkit-box-orient:vertical; overflow:hidden; }
  .more { background:none; border:0; color:var(--accent); padding:4px 0; cursor:pointer; font:inherit; font-weight:600; }
  .img { margin-top:12px; width:100%; border-radius:10px; display:block; background:#0b0f13; }
  .foot { margin-top:10px; display:flex; justify-content:space-between; align-items:center; }
  a { color:var(--accent); text-decoration:none; }
  a:hover { text-decoration:underline; }
  .empty { color:var(--muted); text-align:center; padding:60px 0; column-span:all; }
  mark { background:#5a4a12; color:inherit; border-radius:3px; }
</style>
</head>
<body>
<header>
  <h1>LinkedFetch</h1>
  <span class="count" id="count"><?php echo (int) $count; ?></span>
  <span class="spacer"></span>
  <input type="search" id="q" placeholder="Søg i tekst og forfatter…" autofocus>
  <select id="author"><option value="">Alle forfattere</option></select>
  <select id="sort">
    <option value="nr">Senest gemt</option>
    <option value="date">Nyeste opslag</option>
    <option value="old">Ældste opslag</option>
  </select>
</header>
<main id="list"></main>
<script id="lf-data" type="application/json"><?php echo $json; ?></script>
<script>
(() => {
  const all = JSON.parse(document.getElementById('lf-data').textContent);
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const fmt = (d) => d ? new Date(d + 'T12:00:00').toLocaleDateString('da-DK', { day:'numeric', month:'short', year:'numeric' }) : '';

  // Forfatterliste efter antal opslag
  const counts = {};
  all.forEach((p) => { if (p.a) counts[p.a] = (counts[p.a] || 0) + 1; });
  Object.entries(counts).sort((x, y) => y[1] - x[1]).forEach(([a, n]) => {
    const o = document.createElement('option'); o.value = a; o.textContent = `${a} (${n})`; $('author').appendChild(o);
  });

  let shown = [], rendered = 0;
  const PAGE = 30;

  function highlight(text, q) {
    const safe = esc(text);
    if (!q) return safe;
    const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return safe.replace(re, '<mark>$1</mark>');
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
      <div class="foot"><a href="${esc(p.u)}" target="_blank" rel="noopener">Åbn på LinkedIn</a></div>`;
    const text = el.querySelector('.text'), more = el.querySelector('.more');
    requestAnimationFrame(() => { if (text.scrollHeight > text.clientHeight + 2) more.hidden = false; });
    more.onclick = () => {
      const open = text.classList.toggle('clamp');
      more.textContent = open ? 'Se mere' : 'Se mindre';
    };
    return el;
  }

  function renderMore() {
    const q = $('q').value.trim();
    const frag = document.createDocumentFragment();
    shown.slice(rendered, rendered + PAGE).forEach((p) => frag.appendChild(card(p, q)));
    rendered += PAGE;
    $('list').appendChild(frag);
  }

  function apply() {
    const q = $('q').value.trim().toLowerCase();
    const a = $('author').value;
    const s = $('sort').value;
    shown = all.filter((p) => (!a || p.a === a) && (!q || (p.t + ' ' + p.a).toLowerCase().includes(q)));
    if (s === 'date') shown.sort((x, y) => (y.d || '').localeCompare(x.d || ''));
    else if (s === 'old') shown.sort((x, y) => (x.d || '').localeCompare(y.d || ''));
    else shown.sort((x, y) => y.nr - x.nr);
    $('count').textContent = shown.length === all.length ? all.length : `${shown.length} af ${all.length}`;
    $('list').innerHTML = shown.length ? '' : '<div class="empty">Ingen opslag matcher.</div>';
    rendered = 0;
    renderMore();
  }

  let timer;
  $('q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(apply, 200); });
  $('author').addEventListener('change', apply);
  $('sort').addEventListener('change', apply);
  window.addEventListener('scroll', () => {
    if (rendered < shown.length && innerHeight + scrollY > document.body.offsetHeight - 1200) renderMore();
  });
  apply();
})();
</script>
</body>
</html>
<?php
}
