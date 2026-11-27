<?php
/**
 * Plugin Name: LinkedFetch – gemte LinkedIn-opslag
 * Description: Modtager gemte LinkedIn-opslag fra Chrome-udvidelsen "LinkedIn til Notion" og viser dem i et dashboard på /linkedfetch (kun for administratorer). Med automatiske og egne tags samt filtre, og skrivebeskyttede AI-værktøjer via KEHA MCP.
 * Version: 0.4.3
 * Author: Kenneth Hansen
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

const LF_VERSION = '0.4.3';
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

/* ---------- Værktøjer til AI (Abilities API → KEHA MCP) ---------- */

// Værktøjerne er kun læsende og kræver administrator. De vises i KEHA MCP-serveren
// via filteret keha_mcp_ability_names (KEHA MCP 0.4.0 eller nyere).

function lf_ability_names() {
    return [
        'linkedfetch/overview',
        'linkedfetch/search-posts',
        'linkedfetch/get-posts',
        'linkedfetch/list-authors',
    ];
}

function lf_ai_input($input) {
    if (is_array($input)) {
        return $input;
    }
    if (is_object($input)) {
        return json_decode(wp_json_encode($input), true) ?: [];
    }
    return [];
}

function lf_ai_date($v) {
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

// Fælles WHERE-del til søgning. Returnerer [sql, args].
function lf_ai_where(array $in) {
    global $wpdb;
    $w = [];
    $a = [];
    if (!empty($in['query'])) {
        $like = '%' . $wpdb->esc_like(trim((string) $in['query'])) . '%';
        $w[] = '(text LIKE %s OR author LIKE %s)';
        $a[] = $like;
        $a[] = $like;
    }
    if (!empty($in['author'])) {
        $author = trim((string) $in['author']);
        if (!empty($in['author_exact'])) {
            $w[] = 'author = %s';
            $a[] = $author;
        } else {
            $w[] = 'author LIKE %s';
            $a[] = '%' . $wpdb->esc_like($author) . '%';
        }
    }
    $tags = array_values(array_filter(array_map('lf_clean_tag_name', (array) ($in['tags'] ?? []))));
    if ($tags) {
        $parts = [];
        foreach ($tags as $tag) {
            // Tags gemmes som JSON-liste, fx ["Prompting","Feedback"]
            $parts[] = 'tags LIKE %s';
            $a[] = '%' . $wpdb->esc_like(substr(lf_json([$tag]), 1, -1)) . '%'; // "Navn" med citationstegn
        }
        $w[] = '(' . implode(($in['tag_mode'] ?? 'any') === 'all' ? ' AND ' : ' OR ', $parts) . ')';
    }
    if ($from = lf_ai_date($in['from'] ?? '')) {
        $w[] = 'posted >= %s';
        $a[] = $from;
    }
    if ($to = lf_ai_date($in['to'] ?? '')) {
        $w[] = 'posted <= %s';
        $a[] = $to;
    }
    if (!empty($in['with_image'])) {
        $w[] = 'image IS NOT NULL';
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}

function lf_ai_format($r, $text_mode) {
    $text = (string) $r->text;
    $out = [
        'id' => $r->post_urn,
        'nr' => $r->nr !== null ? (int) $r->nr : null,
        'author' => $r->author,
        'posted' => $r->posted,
        'url' => $r->url,
        'tags' => lf_decode_tags($r->tags),
        'tags_set_by_user' => (bool) $r->tags_locked,
        'has_image' => !empty($r->image),
        'length' => mb_strlen($text),
    ];
    if ($text_mode === 'full') {
        $out['text'] = $text;
    } elseif ($text_mode === 'excerpt') {
        $flat = trim(preg_replace('/\s+/u', ' ', $text));
        $out['excerpt'] = mb_strlen($flat) > 300 ? mb_substr($flat, 0, 300) . '…' : $flat;
    }
    return $out;
}

function lf_ai_error($code, $message, $status = 400) {
    return new WP_Error('lf_' . $code, $message, ['status' => $status]);
}

add_action('wp_abilities_api_categories_init', function () {
    if (function_exists('wp_register_ability_category')) {
        wp_register_ability_category('linkedfetch', [
            'label' => 'LinkedFetch',
            'description' => 'Søgning i Kenneths gemte LinkedIn-opslag på keha.dk.',
        ]);
    }
});

add_action('wp_abilities_api_init', function () {
    if (!function_exists('wp_register_ability')) {
        return;
    }
    $admin = function () {
        return current_user_can('manage_options');
    };
    $common = function ($label, $description, $schema, $callback) use ($admin) {
        return [
            'label' => $label,
            'description' => $description,
            'category' => 'linkedfetch',
            'input_schema' => $schema + ['additionalProperties' => false],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => $callback,
            'permission_callback' => $admin,
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ];
    };
    $filters = [
        'query' => ['type' => 'string', 'description' => 'Fritekst. Søger i opslagets tekst og forfatternavn (del af ord er nok, ikke versalfølsom).'],
        'author' => ['type' => 'string', 'description' => 'Forfatter. Del af navnet er nok, medmindre author_exact=true.'],
        'author_exact' => ['type' => 'boolean', 'default' => false, 'description' => 'Kræv præcist forfatternavn (brug navnet fra list-authors).'],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag-navne præcis som i overview, fx "Prompting".'],
        'tag_mode' => ['type' => 'string', 'enum' => ['any', 'all'], 'default' => 'any', 'description' => 'any: mindst ét af tags. all: alle tags.'],
        'from' => ['type' => 'string', 'description' => 'Opslået fra og med dato, ÅÅÅÅ-MM-DD.'],
        'to' => ['type' => 'string', 'description' => 'Opslået til og med dato, ÅÅÅÅ-MM-DD.'],
        'with_image' => ['type' => 'boolean', 'default' => false, 'description' => 'Kun opslag med billede.'],
    ];

    // Overblik: antal, periode, tags og de mest gemte forfattere
    wp_register_ability('linkedfetch/overview', $common(
        'Overblik over gemte LinkedIn-opslag',
        'Giver et overblik over Kenneths gemte LinkedIn-opslag på keha.dk: antal i alt, ældste og nyeste opslagsdato, alle tags med antal og de forfattere, der er gemt flest opslag fra. Godt første kald.',
        ['type' => 'object', 'properties' => [
            'top_authors' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 15, 'description' => 'Antal forfattere i toplisten.'],
        ]],
        function ($input) {
            global $wpdb;
            $in = lf_ai_input($input);
            $t = lf_table();
            $counts = [];
            $none = 0;
            foreach ($wpdb->get_col("SELECT tags FROM $t") as $json) {
                $g = lf_decode_tags($json);
                if (!$g) {
                    $none++;
                }
                foreach ($g as $name) {
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                }
            }
            $tags = array_map(function ($d) use ($counts) {
                return ['name' => $d['name'], 'count' => $counts[$d['name']] ?? 0];
            }, lf_get_tags());
            usort($tags, function ($x, $y) { return $y['count'] <=> $x['count']; });
            $n = max(0, min(100, (int) ($in['top_authors'] ?? 15)));
            $authors = $n ? $wpdb->get_results($wpdb->prepare(
                "SELECT author AS name, COUNT(*) AS count FROM $t WHERE author <> '' GROUP BY author ORDER BY count DESC, author ASC LIMIT %d", $n
            ), ARRAY_A) : [];
            foreach ($authors as &$a) {
                $a['count'] = (int) $a['count'];
            }
            return [
                'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t"),
                'authors_total' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT author) FROM $t WHERE author <> ''"),
                'oldest_posted' => $wpdb->get_var("SELECT MIN(posted) FROM $t"),
                'newest_posted' => $wpdb->get_var("SELECT MAX(posted) FROM $t"),
                'last_fetched' => $wpdb->get_var("SELECT MAX(fetched) FROM $t"),
                'tags' => $tags,
                'without_tags' => $none,
                'top_authors' => $authors,
                'dashboard' => home_url('/linkedfetch/'),
            ];
        }
    ));

    // Søgning med filtre
    wp_register_ability('linkedfetch/search-posts', $common(
        'Søg i gemte LinkedIn-opslag',
        'Søger i Kenneths gemte LinkedIn-opslag med fritekst, forfatter, tags og periode. Returnerer antal træf og en side med opslag. Som standard kun et uddrag af teksten; sæt text="full" for hele teksten (højst 25 pr. side) eller brug get-posts bagefter.',
        ['type' => 'object', 'properties' => $filters + [
            'sort' => ['type' => 'string', 'enum' => ['saved', 'newest', 'oldest'], 'default' => 'newest', 'description' => 'saved: senest gemt. newest/oldest: efter opslagsdato.'],
            'text' => ['type' => 'string', 'enum' => ['none', 'excerpt', 'full'], 'default' => 'excerpt', 'description' => 'Hvor meget af teksten der kommer med.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]],
        function ($input) {
            global $wpdb;
            $in = lf_ai_input($input);
            $t = lf_table();
            $mode = in_array($in['text'] ?? 'excerpt', ['none', 'excerpt', 'full'], true) ? ($in['text'] ?? 'excerpt') : 'excerpt';
            $limit = max(1, min($mode === 'full' ? 25 : 100, (int) ($in['limit'] ?? 20)));
            $offset = max(0, (int) ($in['offset'] ?? 0));
            $order = ['saved' => 'nr DESC, id DESC', 'oldest' => 'posted IS NULL, posted ASC, id ASC'][$in['sort'] ?? ''] ?? 'posted DESC, id DESC';
            list($where, $args) = lf_ai_where($in);
            $count_sql = "SELECT COUNT(*) FROM $t $where";
            $total = (int) $wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);
            $sql = "SELECT post_urn, nr, author, text, url, posted, image, tags, tags_locked FROM $t $where ORDER BY $order LIMIT %d OFFSET %d";
            $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, [$limit, $offset])));
            $next = $offset + count($rows);
            return [
                'total' => $total,
                'offset' => $offset,
                'returned' => count($rows),
                'next_offset' => $next < $total ? $next : null,
                'items' => array_map(function ($r) use ($mode) { return lf_ai_format($r, $mode); }, $rows),
            ];
        }
    ));

    // Hent hele teksten for udvalgte opslag
    wp_register_ability('linkedfetch/get-posts', $common(
        'Hent gemte LinkedIn-opslag',
        'Henter op til 25 gemte LinkedIn-opslag med hele teksten ud fra deres id (urn:li:…), som search-posts returnerer.',
        ['type' => 'object', 'required' => ['ids'], 'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 25, 'description' => 'Opslagenes id, fx "urn:li:activity:7532081287134638081".'],
        ]],
        function ($input) {
            global $wpdb;
            $in = lf_ai_input($input);
            $t = lf_table();
            $ids = array_values(array_unique(array_filter((array) ($in['ids'] ?? []), function ($id) {
                return is_string($id) && preg_match('/^urn:li:(activity|share|ugcPost):\d+$/', $id);
            })));
            if (!$ids) {
                return lf_ai_error('bad_ids', 'Angiv mindst ét gyldigt id på formen urn:li:activity:123…');
            }
            if (count($ids) > 25) {
                return lf_ai_error('too_many', 'Højst 25 id\'er pr. kald.');
            }
            $ph = implode(',', array_fill(0, count($ids), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_urn, nr, author, text, url, posted, image, tags, tags_locked FROM $t WHERE post_urn IN ($ph)", $ids
            ));
            $found = [];
            foreach ($rows as $r) {
                $found[$r->post_urn] = lf_ai_format($r, 'full');
            }
            return [
                'items' => array_values(array_filter(array_map(function ($id) use ($found) { return $found[$id] ?? null; }, $ids))),
                'not_found' => array_values(array_diff($ids, array_keys($found))),
            ];
        }
    ));

    // Forfattere med antal
    wp_register_ability('linkedfetch/list-authors', $common(
        'List forfattere af gemte LinkedIn-opslag',
        'Lister forfattere med antal gemte opslag og datoen for deres nyeste opslag. Kan filtreres på navn, så man finder den præcise stavemåde.',
        ['type' => 'object', 'properties' => [
            'search' => ['type' => 'string', 'description' => 'Del af forfatternavnet.'],
            'min_posts' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]],
        function ($input) {
            global $wpdb;
            $in = lf_ai_input($input);
            $t = lf_table();
            $where = "WHERE author <> ''";
            $args = [];
            if (!empty($in['search'])) {
                $where .= ' AND author LIKE %s';
                $args[] = '%' . $wpdb->esc_like(trim((string) $in['search'])) . '%';
            }
            $min = max(1, (int) ($in['min_posts'] ?? 1));
            $limit = max(1, min(200, (int) ($in['limit'] ?? 50)));
            $offset = max(0, (int) ($in['offset'] ?? 0));
            $base = "FROM $t $where GROUP BY author HAVING COUNT(*) >= %d";
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT author $base) x", array_merge($args, [$min])));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT author AS name, COUNT(*) AS count, MAX(posted) AS newest_posted $base ORDER BY count DESC, name ASC LIMIT %d OFFSET %d",
                array_merge($args, [$min, $limit, $offset])
            ), ARRAY_A);
            foreach ($rows as &$r) {
                $r['count'] = (int) $r['count'];
            }
            $next = $offset + count($rows);
            return ['total' => $total, 'next_offset' => $next < $total ? $next : null, 'items' => $rows];
        }
    ));
});

// Læg værktøjerne på KEHA MCP-serveren (kræver KEHA MCP 0.4.0+)
add_filter('keha_mcp_ability_names', function ($names) {
    return array_merge((array) $names, lf_ability_names());
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
    ];
    $json = wp_json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $user = wp_get_current_user();
    $logout = wp_logout_url(home_url('/linkedfetch/'));
    ?><!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>LinkedFetch – gemte opslag</title>
<style>
  :root {
    --page:#e9edf1; --surface:#ffffff; --raised:#f6f8fa; --ink:#18212b; --muted:#5b6876; --faint:#8a96a3;
    --line:#d4dbe2; --accent:#0d6b72; --accent-ink:#ffffff; --sel:#e2f0f0; --mark:#fbe8a6;
    --ui: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    --read: "Iowan Old Style", Charter, "Bitstream Charter", Cambria, Georgia, serif;
  }
  @media (prefers-color-scheme: dark) {
    :root { --page:#12171d; --surface:#1a2129; --raised:#202832; --ink:#e4e9ee; --muted:#9aa7b4; --faint:#6f7c89;
            --line:#2c3642; --accent:#4fb3b9; --accent-ink:#0c1a1c; --sel:#1e3438; --mark:#5c4b13; }
  }
  * { box-sizing:border-box; }
  [hidden] { display:none !important; }
  html, body { height:100%; }
  body { margin:0; background:var(--page); color:var(--ink); font:14px/1.45 var(--ui); }
  button, input, select, textarea { font:inherit; color:inherit; }
  :focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
  a { color:var(--accent); }

  .app { display:grid; grid-template-columns:250px minmax(0,1fr); height:100vh; }
  .app.reading { grid-template-columns:250px minmax(340px,1fr) minmax(420px,1.15fr); }

  /* Venstre: filtre */
  .side { overflow-y:auto; overflow-x:hidden; padding:18px 16px 24px; border-right:1px solid var(--line); }
  .brand { display:flex; align-items:baseline; gap:8px; margin:0 0 18px; }
  .brand h1 { font-size:17px; margin:0; letter-spacing:-.01em; }
  .brand span { color:var(--faint); font-size:12px; }
  .side h2 { font-size:12.5px; font-weight:650; color:var(--muted); margin:20px 0 6px; }
  .opt { display:flex; align-items:center; gap:8px; width:100%; text-align:left; background:none; border:0; border-radius:6px;
         padding:4px 6px; cursor:pointer; color:var(--ink); }
  .opt:hover { background:var(--raised); }
  .opt.on { background:var(--sel); font-weight:600; }
  .opt .n { margin-left:auto; color:var(--faint); font-size:12px; font-variant-numeric:tabular-nums; }
  .dot { width:9px; height:9px; border-radius:50%; flex:none; background:var(--c,#888); }
  .side select, .side input[type=date], .side input[type=search] { width:100%; background:var(--surface); border:1px solid var(--line); border-radius:6px; padding:5px 8px; }
  .dates { display:grid; grid-template-columns:1fr; gap:6px; margin-top:6px; }
  .mode { display:flex; gap:4px; margin:2px 0 6px; }
  .mode button { flex:1; background:var(--surface); border:1px solid var(--line); border-radius:6px; padding:3px 0; font-size:12px; cursor:pointer; }
  .mode button.on { background:var(--accent); border-color:var(--accent); color:var(--accent-ink); }
  .linkbtn { background:none; border:0; color:var(--accent); cursor:pointer; padding:4px 6px; font-size:13px; }
  .check { display:flex; gap:8px; align-items:center; padding:4px 6px; cursor:pointer; }
  .account { margin-top:28px; padding-top:14px; border-top:1px solid var(--line); display:flex; flex-direction:column; gap:8px; align-items:flex-start; color:var(--muted); font-size:13px; }
  .account b { color:var(--ink); font-weight:600; }
  .account .iconbtn { color:var(--ink); text-decoration:none; }

  /* Midten: resultater */
  .results { display:flex; flex-direction:column; min-width:0; height:100vh; }
  .bar { padding:14px 18px 10px; border-bottom:1px solid var(--line); background:var(--page); }
  .searchrow { display:flex; gap:8px; align-items:center; }
  .searchrow input { flex:1; min-width:0; background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:8px 12px; font-size:15px; }
  .searchrow select { background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:7px 8px; }
  .seg { display:flex; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
  .seg button { background:var(--surface); border:0; padding:7px 10px; cursor:pointer; }
  .seg button + button { border-left:1px solid var(--line); }
  .seg button.on { background:var(--accent); color:var(--accent-ink); }
  .summary { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:10px; min-height:24px; }
  .summary .count { color:var(--muted); margin-right:4px; font-variant-numeric:tabular-nums; }
  .pill { display:inline-flex; gap:6px; align-items:center; background:var(--surface); border:1px solid var(--line); border-radius:99px; padding:1px 4px 1px 10px; font-size:12.5px; }
  .pill button { background:none; border:0; cursor:pointer; color:var(--muted); width:20px; height:20px; border-radius:50%; }
  .pill button:hover { background:var(--raised); color:var(--ink); }
  .scroll { overflow:auto; flex:1; padding:12px 18px 40px; }
  .empty { color:var(--muted); padding:48px 8px; text-align:center; }

  /* Liste */
  .list .item { display:grid; grid-template-columns:minmax(0,1fr) 84px; gap:14px; padding:11px 12px; border-radius:8px;
                cursor:pointer; border:1px solid transparent; background:var(--surface); margin-bottom:6px; }
  .list .thumb { width:84px; height:64px; border-radius:6px; object-fit:cover; background:var(--raised); }
  /* Gitter */
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:12px; align-items:stretch; }
  .grid .item { background:var(--surface); border:1px solid transparent; border-radius:10px; overflow:hidden; cursor:pointer; display:flex; flex-direction:column; }
  .grid .thumb { width:100%; aspect-ratio:16/9; object-fit:cover; display:block; background:var(--raised); }
  .grid .body { padding:10px 12px 12px; }
  /* Pladsholder, når opslaget ikke har billede, så alle kort er lige store */
  .thumb.ph { display:flex; align-items:center; justify-content:center; color:var(--faint); font-size:22px; font-weight:600; letter-spacing:.02em; }
  .list .thumb.ph { font-size:16px; }
  /* Gitter: tags lagt hen over billedet (højst to linjer, resten som +n) */
  .media { position:relative; }
  .grid .thumb.ph { padding-bottom:28px; }
  .ovtags { position:absolute; left:0; right:0; bottom:0; padding:22px 8px 8px; display:flex; flex-wrap:wrap; align-content:flex-start; gap:4px;
            max-height:76px; overflow:hidden; background:linear-gradient(to top, rgba(10,15,20,.55), rgba(10,15,20,0)); pointer-events:none; }
  .ovtags:empty { display:none; }
  .chip { display:inline-flex; align-items:center; gap:5px; max-width:100%; padding:2px 8px; border-radius:99px; font-size:11.5px; line-height:16px;
          color:#fff; background:rgba(12,18,24,.78); backdrop-filter:blur(3px); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .chip .dot { width:7px; height:7px; }
  .chip.more { pointer-events:auto; }
  .item:hover { border-color:var(--line); }
  .item.on { border-color:var(--accent); box-shadow:0 0 0 1px var(--accent) inset; }
  .who { display:flex; gap:8px; align-items:baseline; min-width:0; }
  .who b { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .who time { color:var(--faint); font-size:12px; white-space:nowrap; margin-left:auto; }
  .ex { color:var(--muted); margin-top:3px; display:-webkit-box; -webkit-box-orient:vertical; overflow:hidden; -webkit-line-clamp:2; height:2.9em; line-height:1.45; }
  .grid .ex { -webkit-line-clamp:3; height:4.35em; }
  .tagline { display:flex; gap:10px; flex-wrap:nowrap; overflow:hidden; margin-top:6px; height:18px; line-height:18px; font-size:12px; color:var(--muted); }
  .tagline span { display:inline-flex; align-items:center; gap:5px; flex:none; }
  .tagline span em { font-style:normal; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .tagline .more { flex:none; }
  mark { background:var(--mark); color:inherit; border-radius:2px; }

  /* Højre: læsepanel */
  .reader { display:none; overflow:auto; background:var(--surface); border-left:1px solid var(--line); height:100vh; }
  .app.reading .reader { display:block; }
  .rtop { position:sticky; top:0; display:flex; gap:6px; align-items:center; padding:10px 16px; background:var(--surface); border-bottom:1px solid var(--line); z-index:2; }
  .rtop .sp { flex:1; }
  .iconbtn { background:none; border:1px solid var(--line); border-radius:6px; padding:4px 10px; cursor:pointer; }
  .iconbtn:hover { background:var(--raised); }
  .article { max-width:66ch; margin:0 auto; padding:24px 28px 60px; }
  .article .by { font-size:18px; font-weight:650; margin:0; }
  .article .when { color:var(--muted); margin-top:2px; }
  .article .text { font:17px/1.62 var(--read); white-space:pre-wrap; word-wrap:break-word; margin-top:18px; }
  .article img { width:100%; border-radius:8px; margin-top:18px; display:block; }
  .article .src { display:inline-block; margin-top:18px; }
  .tagedit { margin-top:26px; padding-top:16px; border-top:1px solid var(--line); }
  .tagedit h3 { font-size:13px; margin:0 0 8px; color:var(--muted); font-weight:650; }
  .tchips { display:flex; flex-wrap:wrap; gap:6px; }
  .tchip { --c:#888; display:inline-flex; gap:6px; align-items:center; border:1px solid var(--line); background:var(--raised); border-radius:99px;
           padding:3px 10px; cursor:pointer; font-size:13px; }
  .tchip.on { background:color-mix(in srgb, var(--c) 18%, var(--surface)); border-color:var(--c); font-weight:600; }
  .tagnew { display:flex; gap:6px; margin-top:10px; }
  .tagnew input { flex:1; background:var(--surface); border:1px solid var(--line); border-radius:6px; padding:5px 8px; }
  .note { color:var(--faint); font-size:12.5px; margin-top:8px; }

  /* Tag-administration */
  dialog { background:var(--surface); color:var(--ink); border:1px solid var(--line); border-radius:12px; width:min(900px,95vw); padding:0; }
  dialog::backdrop { background:rgba(10,15,20,.55); }
  .dlg-h, .dlg-f { padding:14px 18px; display:flex; gap:10px; align-items:center; }
  .dlg-h { border-bottom:1px solid var(--line); }
  .dlg-f { border-top:1px solid var(--line); justify-content:flex-end; }
  .dlg-b { padding:12px 18px; max-height:65vh; overflow:auto; }
  .trow { display:grid; grid-template-columns:36px 190px 1fr 46px 32px; gap:8px; align-items:start; padding:6px 0; border-bottom:1px solid var(--line); }
  .trow input[type=text], .trow textarea { width:100%; background:var(--page); border:1px solid var(--line); border-radius:6px; padding:5px 8px; font-size:13px; }
  .trow textarea { min-height:34px; resize:vertical; }
  .trow input[type=color] { width:34px; height:30px; border:0; background:none; padding:0; }
  .trow .n { color:var(--muted); font-size:12px; padding-top:6px; text-align:right; }
  .del { background:none; border:0; color:#d14b4b; cursor:pointer; font-size:18px; padding-top:4px; }
  .primary { background:var(--accent); color:var(--accent-ink); border:0; border-radius:8px; padding:7px 14px; cursor:pointer; font-weight:600; }
  .hint { color:var(--muted); font-size:12.5px; }
  #toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:var(--ink); color:var(--page); padding:8px 14px; border-radius:8px; display:none; z-index:30; }

  /* Smalle skærme */
  .filterbtn { display:none; }
  @media (max-width: 1100px) {
    .app.reading { grid-template-columns:250px minmax(0,1fr); }
    .app.reading .reader { position:fixed; inset:0 0 0 auto; width:min(640px,100vw); z-index:10; box-shadow:-10px 0 30px rgba(0,0,0,.25); }
  }
  @media (max-width: 760px) {
    .app, .app.reading { grid-template-columns:minmax(0,1fr); }
    .side { display:none; position:fixed; inset:0; z-index:12; background:var(--page); }
    .side.open { display:block; }
    .filterbtn { display:inline-block; }
  }
  @media (prefers-reduced-motion: no-preference) { .reader { animation:none; } }
</style>
</head>
<body>
<div class="app" id="app">
  <aside class="side" id="side" aria-label="Filtre">
    <div class="brand"><h1>LinkedFetch</h1><span>v<?php echo esc_html(LF_VERSION); ?></span></div>

    <h2>Opslagsdato</h2>
    <div id="periods"></div>
    <div class="dates" id="dates" hidden>
      <input type="date" id="from" aria-label="Fra dato"><input type="date" id="to" aria-label="Til dato">
    </div>

    <h2>Tags</h2>
    <div class="mode" role="group" aria-label="Hvordan valgte tags skal matche">
      <button data-mode="any" class="on" title="Vis opslag med mindst ét af de valgte tags">Et af dem</button>
      <button data-mode="all" title="Vis kun opslag med alle valgte tags">Alle</button>
    </div>
    <div id="tagList"></div>
    <button class="linkbtn" id="manage">Administrer tags</button>

    <h2>Forfatter</h2>
    <input type="search" id="authorSearch" placeholder="Find forfatter">
    <div id="authorList"></div>

    <h2>Andet</h2>
    <label class="check"><input type="checkbox" id="imgOnly"> Kun med billede</label>
    <button class="linkbtn filterbtn" id="closeSide">Vis opslag</button>

    <div class="account">
      <span>Logget ind som <b><?php echo esc_html($user->display_name ?: $user->user_login); ?></b></span>
      <a class="iconbtn" href="<?php echo esc_url($logout); ?>">Log ud</a>
    </div>
  </aside>

  <section class="results">
    <div class="bar">
      <div class="searchrow">
        <button class="iconbtn filterbtn" id="openSide">Filtre</button>
        <input type="search" id="q" placeholder="Søg i opslag" title="Genvej: tryk / for at hoppe hertil" aria-label="Søg i opslag">
        <select id="sort" aria-label="Sortering">
          <option value="nr">Senest gemt</option>
          <option value="date">Nyeste opslag</option>
          <option value="old">Ældste opslag</option>
        </select>
        <div class="seg" role="group" aria-label="Visning">
          <button data-view="list" class="on">Liste</button><button data-view="grid">Gitter</button>
        </div>
      </div>
      <div class="summary" id="summary"></div>
    </div>
    <div class="scroll" id="scroll"><div id="out" class="list"></div></div>
  </section>

  <article class="reader" id="reader" aria-label="Opslag"></article>
</div>

<dialog id="dlg">
  <div class="dlg-h"><strong>Administrer tags</strong><span style="flex:1"></span><button class="iconbtn" id="dlgClose">Luk</button></div>
  <div class="dlg-b">
    <p class="hint">Nøgleord adskilles med komma. Et nøgleord matcher begyndelsen af et ord i opslaget, fx matcher "agent" både "agenter" og "agentic". Nøgleord på 3 tegn eller færre skal være et helt ord. Opslag, hvor du selv har valgt tags, ændres ikke af de automatiske tags.</p>
    <div id="tagRows"></div>
    <button class="iconbtn" id="addRow" style="margin-top:10px">Nyt tag</button>
  </div>
  <div class="dlg-f"><span class="hint" id="dlgMsg" style="margin-right:auto"></span><button class="primary" id="dlgSave">Gem og opdater automatiske tags</button></div>
</dialog>
<div id="toast" role="status"></div>

<script id="lf-data" type="application/json"><?php echo $json; ?></script>
<script>
(() => {
  const boot = JSON.parse(document.getElementById('lf-data').textContent);
  const all = boot.posts;
  let defs = boot.tags;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const fmt = (d, long) => d ? new Date(d + 'T12:00:00').toLocaleDateString('da-DK', long ? { day:'numeric', month:'long', year:'numeric' } : { day:'numeric', month:'short', year:'numeric' }) : '';
  const color = (name) => (defs.find((d) => d.name === name) || {}).color || '#8a96a3';
  const store = { get(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
                  set(k, v) { try { localStorage.setItem(k, v); } catch (_) {} } };
  const toast = (m) => { const t = $('toast'); t.textContent = m; t.style.display = 'block'; clearTimeout(t._h); t._h = setTimeout(() => t.style.display = 'none', 2400); };

  async function api(path, body) {
    const r = await fetch(boot.api + path, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.message || ('fejl ' + r.status));
    return j;
  }

  /* ---------- Tilstand ---------- */
  const st = { q: '', period: '', from: '', to: '', tags: new Set(), mode: 'any', author: '', imgOnly: false,
               sort: 'nr', view: store.get('lf-view') || 'list', open: null };
  let shown = [], rendered = 0;
  const PAGE = 50;

  const PERIODS = [['', 'Alle'], ['7', 'Seneste 7 dage'], ['30', 'Seneste 30 dage'], ['90', 'Seneste 3 måneder'],
                   ['365', 'Seneste år'], ['year', 'I år'], ['custom', 'Vælg datoer']];

  function range() {
    const today = new Date(), iso = (d) => d.toISOString().slice(0, 10);
    if (!st.period) return [null, null];
    if (st.period === 'custom') return [st.from || null, st.to || null];
    if (st.period === 'year') return [today.getFullYear() + '-01-01', null];
    const d = new Date(today); d.setDate(d.getDate() - Number(st.period)); return [iso(d), null];
  }

  function matches(p, skip) {
    const [from, to] = range();
    if (skip !== 'author' && st.author && p.a !== st.author) return false;
    if (st.imgOnly && !p.i) return false;
    if (from && (!p.d || p.d < from)) return false;
    if (to && (!p.d || p.d > to)) return false;
    if (st.q && !(p.t + ' ' + p.a).toLowerCase().includes(st.q.toLowerCase())) return false;
    if (skip !== 'tags' && st.tags.size) {
      const has = (t) => t === '__none' ? !p.g.length : p.g.includes(t);
      const sel = [...st.tags];
      if (st.mode === 'all' ? !sel.every(has) : !sel.some(has)) return false;
    }
    return true;
  }

  /* ---------- Venstre kolonne ---------- */
  function renderSide() {
    $('periods').innerHTML = PERIODS.map(([v, l]) =>
      `<button class="opt${st.period === v ? ' on' : ''}" data-period="${v}">${l}</button>`).join('');
    $('dates').hidden = st.period !== 'custom';

    // Tag-tal regnes ud fra de øvrige filtre, så tallene viser, hvad et klik giver
    const base = all.filter((p) => matches(p, 'tags'));
    const counts = {}; let none = 0;
    base.forEach((p) => { p.g.forEach((g) => counts[g] = (counts[g] || 0) + 1); if (!p.g.length) none++; });
    const tagBtn = (key, label, c, n) => `<button class="opt${st.tags.has(key) ? ' on' : ''}" data-tag="${esc(key)}" aria-pressed="${st.tags.has(key)}">
        <span class="dot" style="--c:${esc(c)}"></span>${esc(label)}<span class="n">${n}</span></button>`;
    $('tagList').innerHTML = [...defs].sort((x, y) => (counts[y.name] || 0) - (counts[x.name] || 0) || x.name.localeCompare(y.name, 'da'))
      .map((d) => tagBtn(d.name, d.name, d.color, counts[d.name] || 0)).join('') + tagBtn('__none', 'Uden tags', 'transparent', none);
    document.querySelectorAll('.mode button').forEach((b) => b.classList.toggle('on', b.dataset.mode === st.mode));

    const aBase = all.filter((p) => matches(p, 'author'));
    const ac = {}; aBase.forEach((p) => { if (p.a) ac[p.a] = (ac[p.a] || 0) + 1; });
    const term = $('authorSearch').value.trim().toLowerCase();
    let authors = Object.entries(ac).sort((x, y) => y[1] - x[1]);
    authors = term ? authors.filter(([a]) => a.toLowerCase().includes(term)) : authors.slice(0, 12);
    if (st.author && !authors.some(([a]) => a === st.author)) authors.unshift([st.author, ac[st.author] || 0]);
    $('authorList').innerHTML = authors.map(([a, n]) =>
      `<button class="opt${st.author === a ? ' on' : ''}" data-author="${esc(a)}">${esc(a)}<span class="n">${n}</span></button>`).join('')
      || '<div class="note">Ingen forfattere matcher.</div>';
  }

  /* ---------- Resultater ---------- */
  function highlight(text) {
    const safe = esc(text);
    if (!st.q) return safe;
    const re = new RegExp('(' + st.q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return safe.replace(re, '<mark>$1</mark>');
  }

  function excerpt(t) {
    const flat = t.replace(/\s+/g, ' ').trim();
    if (!st.q) return flat.slice(0, 320);
    const i = flat.toLowerCase().indexOf(st.q.toLowerCase());
    return i > 80 ? '…' + flat.slice(i - 60, i + 260) : flat.slice(0, 320);
  }

  function itemHtml(p) {
    const initials = (p.a || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
    const img = p.i ? `<img class="thumb" loading="lazy" src="${esc(p.i)}" alt="">` : `<div class="thumb ph" aria-hidden="true">${esc(initials)}</div>`;
    const who = `<div class="who"><b>${highlight(p.a || 'Ukendt')}</b><time datetime="${esc(p.d || '')}">${fmt(p.d)}</time></div>
      <div class="ex">${highlight(excerpt(p.t))}</div>`;
    if (st.view === 'grid') {
      // Alle tags lægges på billedet; fitChips() fjerner dem, der ikke kan være på to linjer
      const chips = p.g.map((g) => `<span class="chip" title="${esc(g)}"><i class="dot" style="--c:${esc(color(g))}"></i>${esc(g)}</span>`).join('');
      return `<div class="media">${img}<div class="ovtags">${chips}</div></div><div class="body">${who}</div>`;
    }
    // Tag-linjen er altid med (også tom), så kortene får samme højde. fitTags() fjerner dem, der ikke kan være på linjen.
    const tags = `<div class="tagline">${p.g.map((g) => `<span class="tg" title="${esc(g)}"><i class="dot" style="--c:${esc(color(g))}"></i><em>${esc(g)}</em></span>`).join('')}</div>`;
    return `<div>${who}${tags}</div>${img}`;
  }

  // Fjerner tags fra billedet, til de kan være på to linjer, og samler resten i "+n"
  function fitChips(el) {
    const box = el.querySelector('.ovtags');
    if (!box || !box.children.length) return;
    const chips = [...box.querySelectorAll('.chip')];
    chips.forEach((c) => { c.hidden = false; });
    box.querySelectorAll('.more').forEach((m) => m.remove());
    if (box.scrollHeight <= box.clientHeight) return;
    const more = document.createElement('span'); more.className = 'chip more';
    box.appendChild(more);
    let n = chips.length;
    do {
      chips[--n].hidden = true;
      more.textContent = '+' + (chips.length - n);
      more.title = chips.slice(n).map((c) => c.title).join(', ');
    } while (n > 1 && box.scrollHeight > box.clientHeight);
  }
  // Liste: så mange tags som kan være på én linje, resten som "+n"
  function fitTags(el) {
    const box = el.querySelector('.tagline');
    if (!box || !box.children.length) return;
    const tags = [...box.querySelectorAll('.tg')];
    tags.forEach((t) => { t.hidden = false; });
    box.querySelectorAll('.more').forEach((m) => m.remove());
    if (box.scrollWidth <= box.clientWidth) return;
    const more = document.createElement('span'); more.className = 'more';
    box.appendChild(more);
    let n = tags.length;
    do {
      tags[--n].hidden = true;
      more.textContent = '+' + (tags.length - n);
      more.title = tags.slice(n).map((t) => t.title).join(', ');
    } while (n > 1 && box.scrollWidth > box.clientWidth);
  }
  const fit = (el) => (st.view === 'grid' ? fitChips(el) : fitTags(el));
  function fitAll() { document.querySelectorAll('#out .item').forEach(fit); }

  function renderMore() {
    const frag = document.createDocumentFragment();
    shown.slice(rendered, rendered + PAGE).forEach((p) => {
      const el = document.createElement('div');
      el.className = 'item' + (st.open === p ? ' on' : '');
      el.tabIndex = 0; el.dataset.id = p.id;
      el.innerHTML = itemHtml(p);
      el.onclick = () => openPost(p);
      el.onkeydown = (e) => { if (e.key === 'Enter') openPost(p); };
      frag.appendChild(el);
    });
    rendered += PAGE;
    const added = [...frag.children];
    $('out').appendChild(frag);
    added.forEach(fit);
  }

  function renderSummary() {
    const pills = [];
    const add = (label, clear) => pills.push({ label, clear });
    if (st.q) add(`Søgning: ${st.q}`, () => { st.q = ''; $('q').value = ''; });
    if (st.period) add(st.period === 'custom' ? `Datoer: ${st.from || '…'} til ${st.to || '…'}` : PERIODS.find(([v]) => v === st.period)[1], () => { st.period = ''; });
    st.tags.forEach((t) => add(t === '__none' ? 'Uden tags' : t, () => st.tags.delete(t)));
    if (st.author) add(st.author, () => { st.author = ''; });
    if (st.imgOnly) add('Kun med billede', () => { st.imgOnly = false; $('imgOnly').checked = false; });
    const box = $('summary');
    box.innerHTML = `<span class="count">${shown.length === all.length ? all.length + ' opslag' : shown.length + ' af ' + all.length + ' opslag'}</span>` +
      pills.map((p, i) => `<span class="pill">${esc(p.label)}<button data-i="${i}" aria-label="Fjern filter ${esc(p.label)}">×</button></span>`).join('') +
      (pills.length > 1 ? '<button class="linkbtn" data-clear="all">Ryd alle</button>' : '');
    box.querySelectorAll('.pill button').forEach((b) => b.onclick = () => { pills[b.dataset.i].clear(); update(); });
    const ca = box.querySelector('[data-clear]');
    if (ca) ca.onclick = () => { pills.forEach((p) => p.clear()); update(); };
  }

  function update() {
    shown = all.filter((p) => matches(p));
    if (st.sort === 'date') shown.sort((x, y) => (y.d || '').localeCompare(x.d || ''));
    else if (st.sort === 'old') shown.sort((x, y) => (x.d || '').localeCompare(y.d || ''));
    else shown.sort((x, y) => y.nr - x.nr);
    $('out').className = st.view;
    $('out').innerHTML = shown.length ? '' : '<div class="empty">Ingen opslag matcher. Fjern et filter eller ret søgningen.</div>';
    rendered = 0;
    renderMore();
    $('scroll').scrollTop = 0;
    renderSide();
    renderSummary();
    document.querySelectorAll('.seg button').forEach((b) => b.classList.toggle('on', b.dataset.view === st.view));
  }

  /* ---------- Læsepanel ---------- */
  function openPost(p) {
    st.open = p;
    $('app').classList.add('reading');
    document.querySelectorAll('.item.on').forEach((e) => e.classList.remove('on'));
    const el = document.querySelector(`.item[data-id="${CSS.escape(p.id)}"]`);
    if (el) { el.classList.add('on'); el.scrollIntoView({ block: 'nearest' }); }
    renderReader();
  }

  function closeReader() {
    st.open = null; $('app').classList.remove('reading');
    document.querySelectorAll('.item.on').forEach((e) => e.classList.remove('on'));
  }

  function step(dir) {
    if (!shown.length) return;
    let i = st.open ? shown.indexOf(st.open) : -1;
    i = Math.max(0, Math.min(shown.length - 1, i + dir));
    while (i >= rendered) renderMore();
    openPost(shown[i]);
  }

  function renderReader() {
    const p = st.open; if (!p) return;
    const i = shown.indexOf(p);
    $('reader').innerHTML = `
      <div class="rtop">
        <button class="iconbtn" data-act="prev" ${i <= 0 ? 'disabled' : ''} aria-label="Forrige opslag">Forrige</button>
        <button class="iconbtn" data-act="next" ${i < 0 || i >= shown.length - 1 ? 'disabled' : ''} aria-label="Næste opslag">Næste</button>
        <span class="sp"></span>
        <a class="iconbtn" href="${esc(p.u)}" target="_blank" rel="noopener">Åbn på LinkedIn</a>
        <button class="iconbtn" data-act="close" aria-label="Luk opslag">Luk</button>
      </div>
      <div class="article">
        <p class="by">${esc(p.a || 'Ukendt')}</p>
        <div class="when">${p.d ? 'Opslået ' + fmt(p.d, true) : 'Dato ukendt'}${p.nr ? `, gemt som nr. ${p.nr}` : ''}</div>
        <div class="text">${highlight(p.t)}</div>
        ${p.i ? `<img src="${esc(p.i)}" alt="Billede fra opslaget">` : ''}
        <div class="tagedit">
          <h3>Tags</h3>
          <div class="tchips">${defs.map((d) => `<button class="tchip${p.g.includes(d.name) ? ' on' : ''}" style="--c:${esc(d.color)}" data-t="${esc(d.name)}" aria-pressed="${p.g.includes(d.name)}">
            <span class="dot"></span>${esc(d.name)}</button>`).join('')}</div>
          <div class="tagnew"><input type="text" id="newTag" placeholder="Nyt tag" maxlength="40"><button class="iconbtn" id="newTagBtn">Opret og sæt på</button></div>
          <div class="note">${p.l ? 'Du har selv valgt tags til dette opslag. <button class="linkbtn" data-act="auto">Brug automatiske tags igen</button>' : 'Tags er sat automatisk ud fra teksten. Når du ændrer dem, bliver dit valg stående.'}</div>
        </div>
      </div>`;
    const r = $('reader');
    r.scrollTop = 0;
    r.querySelector('[data-act=prev]').onclick = () => step(-1);
    r.querySelector('[data-act=next]').onclick = () => step(1);
    r.querySelector('[data-act=close]').onclick = closeReader;
    r.querySelectorAll('.tchip').forEach((b) => b.onclick = () => {
      const set = new Set(p.g); set.has(b.dataset.t) ? set.delete(b.dataset.t) : set.add(b.dataset.t);
      saveTags(p, [...set]);
    });
    const auto = r.querySelector('[data-act=auto]');
    if (auto) auto.onclick = async () => {
      try { const res = await api('post-tags', { id: p.id, auto: true }); p.g = res.tags; p.l = res.locked; afterTagChange(p); toast('Automatiske tags sat'); }
      catch (e) { toast('Tags blev ikke gemt: ' + e.message); }
    };
    const create = async () => {
      const name = $('newTag').value.trim().replace(/\s+/g, ' ').slice(0, 40);
      if (!name) return;
      if (!defs.some((d) => d.name === name)) {
        const palette = ['#7c5cd6','#d64f8f','#1f9d74','#e07a2e','#1f8fc2','#c9a227','#16a3a0','#5560d6','#9b4fd6','#3aa655'];
        try {
          const res = await api('tags', { tags: defs.map((d) => ({ ...d, oldName: d.name })).concat([{ name, color: palette[defs.length % palette.length], keywords: [] }]) });
          defs = res.tags;
        } catch (e) { toast('Tagget blev ikke oprettet: ' + e.message); return; }
      }
      saveTags(p, [...new Set([...p.g, name])]);
    };
    $('newTagBtn').onclick = create;
    $('newTag').onkeydown = (e) => { if (e.key === 'Enter') create(); };
  }

  async function saveTags(p, tags) {
    try {
      const res = await api('post-tags', { id: p.id, tags });
      p.g = res.tags; p.l = res.locked; afterTagChange(p); toast('Tags gemt');
    } catch (e) { toast('Tags blev ikke gemt: ' + e.message); }
  }

  function afterTagChange(p) {
    const el = document.querySelector(`.item[data-id="${CSS.escape(p.id)}"]`);
    if (el) { el.innerHTML = itemHtml(p); fit(el); }
    renderSide(); renderReader();
  }

  /* ---------- Tag-administration ---------- */
  function tagRow(d) {
    const n = all.filter((p) => p.g.includes(d.name)).length;
    const row = document.createElement('div'); row.className = 'trow'; row.dataset.old = d.name || '';
    row.innerHTML = `<input type="color" value="${esc(d.color || '#64748b')}" aria-label="Farve">
      <input type="text" class="name" value="${esc(d.name || '')}" placeholder="Navn" maxlength="40" aria-label="Navn">
      <textarea class="kw" placeholder="Nøgleord, adskilt med komma" aria-label="Nøgleord">${esc((d.keywords || []).join(', '))}</textarea>
      <span class="n">${d.name ? n : ''}</span><button class="del" title="Slet tag" aria-label="Slet tag">×</button>`;
    row.querySelector('.del').onclick = () => row.remove();
    return row;
  }
  $('manage').onclick = () => { $('tagRows').innerHTML = ''; defs.forEach((d) => $('tagRows').appendChild(tagRow(d))); $('dlgMsg').textContent = ''; $('dlg').showModal(); };
  $('dlgClose').onclick = () => $('dlg').close();
  $('addRow').onclick = () => { const r = tagRow({ color: '#64748b', keywords: [] }); $('tagRows').appendChild(r); r.querySelector('.name').focus(); };
  $('dlgSave').onclick = async () => {
    const tags = [...$('tagRows').querySelectorAll('.trow')].map((r) => ({
      name: r.querySelector('.name').value.trim(), oldName: r.dataset.old,
      color: r.querySelector('input[type=color]').value,
      keywords: r.querySelector('.kw').value.split(',').map((s) => s.trim()).filter(Boolean)
    })).filter((t) => t.name);
    $('dlgMsg').textContent = 'Gemmer…';
    try {
      const res = await api('tags', { tags });
      $('dlgMsg').textContent = `Gemt. ${res.retagged} opslag fik opdateret automatiske tags. Siden genindlæses.`;
      setTimeout(() => location.reload(), 900);
    } catch (e) { $('dlgMsg').textContent = 'Tags blev ikke gemt: ' + e.message; }
  };

  /* ---------- Hændelser ---------- */
  $('side').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    if ('period' in b.dataset) { st.period = b.dataset.period; update(); }
    else if ('tag' in b.dataset) { st.tags.has(b.dataset.tag) ? st.tags.delete(b.dataset.tag) : st.tags.add(b.dataset.tag); update(); }
    else if ('mode' in b.dataset) { st.mode = b.dataset.mode; update(); }
    else if ('author' in b.dataset) { st.author = st.author === b.dataset.author ? '' : b.dataset.author; update(); }
  });
  $('authorSearch').addEventListener('input', renderSide);
  $('from').addEventListener('change', (e) => { st.from = e.target.value; update(); });
  $('to').addEventListener('change', (e) => { st.to = e.target.value; update(); });
  $('imgOnly').addEventListener('change', (e) => { st.imgOnly = e.target.checked; update(); });
  $('sort').addEventListener('change', (e) => { st.sort = e.target.value; update(); });
  let timer;
  $('q').addEventListener('input', (e) => { clearTimeout(timer); timer = setTimeout(() => { st.q = e.target.value.trim().replace(/^\/+/, '').trim(); update(); }, 180); });
  document.querySelectorAll('.seg button').forEach((b) => b.onclick = () => { st.view = b.dataset.view; store.set('lf-view', st.view); update(); });
  $('openSide').onclick = () => $('side').classList.add('open');
  $('closeSide').onclick = () => $('side').classList.remove('open');
  $('scroll').addEventListener('scroll', () => {
    const s = $('scroll');
    if (rendered < shown.length && s.scrollTop + s.clientHeight > s.scrollHeight - 800) renderMore();
  });
  document.addEventListener('keydown', (e) => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
    if (e.key === '/' && !typing) { e.preventDefault(); $('q').focus(); return; }
    if (e.key === 'Escape') { if ($('dlg').open) return; if (typing) { document.activeElement.blur(); return; } closeReader(); return; }
    if (typing || $('dlg').open) return;
    if (e.key === 'ArrowDown' || e.key === 'j') { e.preventDefault(); step(1); }
    if (e.key === 'ArrowUp' || e.key === 'k') { e.preventDefault(); step(-1); }
  });

  let resizeTimer;
  window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(fitAll, 150); });

  update();
})();
</script>
</body>
</html>
<?php
}
