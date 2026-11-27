<?php
/**
 * Kører AI-værktøjerne mod en lille SQLite-database med lette WordPress-stubs.
 * Brug: php tests/abilities.php   (skriver også de genererede SQL-sætninger med --sql)
 */
error_reporting(E_ALL);
set_error_handler(function ($s, $m, $f, $l) { throw new ErrorException($m, 0, $s, $f, $l); });
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['actions'] = []; $GLOBALS['filters'] = []; $GLOBALS['abilities'] = []; $GLOBALS['admin'] = true; $GLOBALS['sqllog'] = [];
class WP_Error { public $code, $message, $data; function __construct($c, $m, $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; } }
class WP_REST_Request {}
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function add_action($h, $cb) { $GLOBALS['actions'][$h][] = $cb; }
function add_filter($h, $cb) { $GLOBALS['filters'][$h][] = $cb; }
function apply_filters($h, $v) { foreach ($GLOBALS['filters'][$h] ?? [] as $cb) { $v = $cb($v); } return $v; }
function register_activation_hook() {} function register_deactivation_hook() {}
function current_user_can($c) { return $GLOBALS['admin']; }
function get_option($k) { return $GLOBALS['options'][$k] ?? false; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; }
function home_url($p = '') { return 'https://keha.dk' . $p; }
function wp_strip_all_tags($s) { return strip_tags($s); }
function wp_register_ability_category($n, $a) { $GLOBALS['categories'][$n] = $a; }
function wp_register_ability($n, $a) { $GLOBALS['abilities'][$n] = $a; }
class FakeWpdb {
    public $prefix = 'wp_'; private $pdo;
    function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('regexp', fn($p, $v) => 1); }
    function exec($sql) { $this->pdo->exec($sql); }
    function esc_like($s) { return addcslashes($s, '_%\\'); }
    function prepare($q, ...$a) { if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0; return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $a) { $v = $a[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . str_replace("'", "''", $v) . "'"; }, $q); }
    private function q($sql) { $GLOBALS['sqllog'][] = $sql; $sqlite = preg_replace("/LIKE ('(?:[^']|'')*')/", "LIKE $1 ESCAPE '\\'", $sql); return $this->pdo->query($sqlite); }
    function get_var($sql) { $r = $this->q($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function get_col($sql) { return $this->q($sql)->fetchAll(PDO::FETCH_COLUMN); }
    function get_results($sql, $o = null) { return $this->q($sql)->fetchAll($o === ARRAY_A ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ); }
}
$wpdb = new FakeWpdb();
require __DIR__ . '/../wordpress-plugin/linkedfetch/linkedfetch.php';
$wpdb->exec("CREATE TABLE wp_linkedfetch_posts (id INTEGER PRIMARY KEY, post_urn TEXT, nr INT, author TEXT, text TEXT, url TEXT, posted TEXT, image TEXT, tags TEXT, tags_locked INT DEFAULT 0, fetched TEXT)");
$rows = [
    ['urn:li:activity:1', 1, 'Marc Kluge', 'AI og eksamen. Snyd er ikke problemet.', '2026-02-14', 'a.webp', '["Eksamen og snyd","Didaktik og læringsdesign"]'],
    ['urn:li:activity:2', 2, 'Marc Kluge', 'Om ledelse og drift', '2026-05-16', null, '["Ledelse og strategi"]'],
    ['urn:li:activity:3', 3, 'Anne 50% Hansen', 'Prompting for begyndere_', '2025-12-01', null, '["Prompting"]'],
    ['urn:li:activity:4', 4, 'Ole', 'Uden tags', null, null, '[]'],
];
foreach ($rows as $r) { $wpdb->exec($wpdb->prepare("INSERT INTO wp_linkedfetch_posts (post_urn,nr,author,text,url,posted,image,tags,fetched) VALUES (%s,%d,%s,%s,'u',%s,%s,%s,'2026-11-27 10:00:00')",
    [$r[0], $r[1], $r[2], $r[3], $r[4] ?? '', $r[5] ?? '', $r[6]])); }
$wpdb->exec("UPDATE wp_linkedfetch_posts SET posted = NULL WHERE posted = ''"); $wpdb->exec("UPDATE wp_linkedfetch_posts SET image = NULL WHERE image = ''");
foreach ($GLOBALS['actions']['wp_abilities_api_categories_init'] as $cb) { $cb(); }
foreach ($GLOBALS['actions']['wp_abilities_api_init'] as $cb) { $cb(); }
$checks = 0;
function check($c, $m) { global $checks; $checks++; if (!$c) { throw new RuntimeException('FEJL: ' . $m); } }
function call($n, $in = []) { $a = $GLOBALS['abilities']['linkedfetch/' . $n]; if (!($a['permission_callback'])()) { return new WP_Error('forbidden', ''); } return ($a['execute_callback'])($in); }

check(count($GLOBALS['abilities']) === 4 && isset($GLOBALS['categories']['linkedfetch']), 'fire abilities og kategori registreret');
check(apply_filters('keha_mcp_ability_names', ['keha/site-info']) === array_merge(['keha/site-info'], lf_ability_names()), 'filter tilføjer navne');
foreach ($GLOBALS['abilities'] as $a) { check($a['meta']['annotations']['readonly'] === true && $a['category'] === 'linkedfetch', 'skrivebeskyttet'); }

$o = call('overview'); check($o['total'] === 4 && $o['authors_total'] === 3 && $o['without_tags'] === 1, 'overview tal');
check($o['oldest_posted'] === '2025-12-01' && $o['newest_posted'] === '2026-05-16', 'overview periode');
check($o['top_authors'][0] === ['name' => 'Marc Kluge', 'count' => 2], 'top forfatter');

$s = call('search-posts', ['author' => 'kluge']); check($s['total'] === 2 && $s['items'][0]['posted'] === '2026-05-16', 'forfatter delvis, nyeste først');
check(isset($s['items'][0]['excerpt']) && !isset($s['items'][0]['text']), 'uddrag som standard');
$s = call('search-posts', ['author' => 'Kluge', 'author_exact' => true]); check($s['total'] === 0, 'præcis forfatter');
$s = call('search-posts', ['query' => 'snyd', 'text' => 'full']); check($s['total'] === 1 && isset($s['items'][0]['text']), 'fritekst + fuld tekst');
$s = call('search-posts', ['tags' => ['Prompting', 'Ledelse og strategi']]); check($s['total'] === 2, 'tags any');
$s = call('search-posts', ['tags' => ['Eksamen og snyd', 'Didaktik og læringsdesign'], 'tag_mode' => 'all']); check($s['total'] === 1, 'tags all');
$s = call('search-posts', ['tags' => ['Eksamen']]); check($s['total'] === 0, 'tag matcher kun helt navn');
$s = call('search-posts', ['from' => '2026-01-01', 'to' => '2026-03-01']); check($s['total'] === 1, 'periode');
$s = call('search-posts', ['query' => '50%']); check($s['total'] === 1, '% escapes');
$s = call('search-posts', ['query' => "_"]); check($s['total'] === 1, '_ escapes');
$s = call('search-posts', ['query' => "' OR 1=1 --"]); check($s['total'] === 0, 'citationstegn sikkert');
$s = call("search-posts", ["with_image" => true]); check($s['total'] === 1, 'kun billede');
$s = call('search-posts', ['limit' => 2, 'sort' => 'saved']); check($s['returned'] === 2 && $s['next_offset'] === 2 && $s['items'][0]['nr'] === 4, 'sider + sortering');
$s = call('search-posts', ['sort' => 'oldest']); check($s['items'][0]['posted'] === '2025-12-01' && $s['items'][3]['posted'] === null, 'ældste først, uden dato sidst');
$s = call('search-posts', ['text' => 'full', 'limit' => 100]); check($s['returned'] === 4, 'full-grænse virker uden fejl');

$g = call('get-posts', ['ids' => ['urn:li:activity:2', 'urn:li:activity:99', 'ikke-et-id']]);
check(count($g['items']) === 1 && $g['items'][0]['text'] === 'Om ledelse og drift' && $g['not_found'] === ['urn:li:activity:99'], 'get-posts');
check(is_wp_error(call('get-posts', ['ids' => ['x']])), 'ugyldige id giver fejl');

$l = call('list-authors', ['search' => 'kluge']); check($l['total'] === 1 && $l['items'][0]['count'] === 2 && $l['items'][0]['newest_posted'] === '2026-05-16', 'list-authors');
$l = call('list-authors', ['min_posts' => 2]); check($l['total'] === 1, 'min_posts');

$GLOBALS['admin'] = false; check(is_wp_error(call('overview')), 'kræver administrator');
echo "PASS: $checks kontroller\n";
if (in_array('--sql', $argv, true)) { echo implode("\n", array_unique($GLOBALS['sqllog'])), "\n"; }
