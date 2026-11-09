<?php
// Banc d'essai : simule le strict minimum de WordPress pour tester la logique du plugin.
define('ABSPATH', '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['__opts'] = ['admin_email' => 'admin@example.test'];
$GLOBALS['__http_get'] = [];
$GLOBALS['__trans'] = [];
$GLOBALS['__http'] = [];     // file de réponses simulées
$GLOBALS['__calls'] = [];    // appels HTTP enregistrés

class WP_Error {
    public $code; public $msg;
    function __construct($c, $m) { $this->code = $c; $this->msg = $m; }
    function get_error_message() { return $this->msg; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function add_action(...$a) {} function add_shortcode(...$a) {}
function add_filter($tag, $cb, $prio = 10, $n = 1) { $GLOBALS['__filters'][$tag][] = $cb; }
function register_activation_hook(...$a) {} function register_deactivation_hook(...$a) {}
function is_admin() { return false; }
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['__trans'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__trans'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__trans'][$k]); return true; }
function wp_json_encode($v) { return json_encode($v); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_date($f, $t = null) { $t = $t ?? time(); $d = new DateTime('@' . $t); $d->setTimezone(new DateTimeZone('Europe/Paris')); return $d->format($f); }
function shortcode_atts($d, $a, $s = '') { return array_merge($d, array_intersect_key((array) $a, $d)); }
function current_user_can($c) { return true; }
function wp_next_scheduled($h) { return false; } function wp_schedule_event(...$a) { $GLOBALS['__sched'][] = $a; } function wp_clear_scheduled_hook(...$a) {}
function wp_remote_post($url, $args) {
    $GLOBALS['__calls'][] = ['url' => $url, 'args' => $args];
    $r = array_shift($GLOBALS['__http']);
    return $r ?? new WP_Error('none', 'pas de réponse simulée');
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : false; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return trim((string) $s); }
function esc_textarea($s) { return htmlspecialchars((string) $s); }
function human_time_diff($a, $b = 0) { $b = $b ?: time(); return round(abs($b - $a) / 86400) . ' jours'; }
function wp_is_post_revision($id) { return false; }
function get_post($id) { return (object) ['ID' => $id, 'post_status' => 'publish', 'post_content' => $GLOBALS['__content'][$id] ?? '']; }
function get_permalink($p) { return 'https://example.test/?p=' . $p->ID; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function get_the_title($p) { return 'Titre ' . $p->ID; }
function get_edit_post_link($id, $ctx = '') { return "https://example.test/wp-admin/post.php?post=$id&action=edit"; }
function plugin_basename($f) { return 'mcm-prix-amazon/mcm-prix-amazon.php'; }
function delete_site_transient($k) { return true; }
function wp_remote_get($url, $args = []) { $GLOBALS['__calls'][] = ['url' => $url, 'args' => $args]; $r = array_shift($GLOBALS['__http_get']); return $r ?? new WP_Error('none', 'pas de réponse simulée'); }
function download_url($url, $t = 300) { return $GLOBALS['__dl'] ?? new WP_Error('dl', 'échec téléchargement'); }

function do_action(...$a) {}
function wp_mail(...$a) { $GLOBALS['__mail'] = $a; return true; }
function wp_generate_password($n = 12, ...$a) { return substr(str_repeat('abcdef0123456789', 4), 0, $n); }
function rest_url($p = '') { return 'https://example.test/wp-json/' . $p; }
function add_query_arg($k, $v, $u) { return $u . '?' . $k . '=' . $v; }
function home_url() { return 'https://example.test'; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
class Fake_Resp { public $d; function __construct($d) { $this->d = $d; } function get_data() { return $this->d; } }
function rest_ensure_response($x) { return new Fake_Resp($x); }
class Fake_Req { public $p; function __construct($p) { $this->p = $p; } function get_param($k) { return $this->p[$k] ?? null; } }
function esc_url_raw($u, $protocols = null) { return preg_match('#^https://#', $u) ? $u : ''; }
function get_bloginfo($k) { return 'Mon Choix Malin'; }
function wp_specialchars_decode($s, $q = 0) { return $s; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }

require getenv('PLUGIN_FILE');

$fail = 0;
function check($label, $cond) {
    global $fail;
    echo ($cond ? "OK   " : "FAIL ") . $label . "\n";
    if (!$cond) $fail++;
}

// --- 1. Parsing : les deux noms de conteneur de la documentation -------------------------
$item = fn($asin) => [
    'asin' => $asin,
    'detailPageURL' => "https://www.amazon.fr/dp/$asin?tag=monchoixmalinfr-21&linkCode=ogi&th=1&psc=1",
    'offersV2' => ['listings' => [[
        'availability' => ['type' => 'IN_STOCK', 'maxOrderQuantity' => 5, 'minOrderQuantity' => 1],
        'condition' => ['value' => 'New'],
        'isBuyBoxWinner' => true,
        'price' => ['money' => ['amount' => 379.99, 'currency' => 'EUR', 'displayAmount' => '379,99 €']],
    ]]],
];
foreach (['itemResults', 'itemsResult'] as $container) {
    $r = MCM_Prix::parse_response([$container => ['items' => [$item('B0GHN39ZGJ')]]], ['B0GHN39ZGJ']);
    check("parse ($container) : prix 379,99", ($r['offers']['B0GHN39ZGJ']['display'] ?? '') === '379,99 €');
}

// --- 2. Erreurs partielles + hors stock + occasion --------------------------------------
$oos = $item('B0GQVBWMN8'); $oos['offersV2']['listings'][0]['availability']['type'] = 'OUT_OF_STOCK';
$used = $item('B0GXWTLMJT'); $used['offersV2']['listings'][0]['condition']['value'] = 'Used';
$noOffer = ['asin' => 'B0GD6Z2YG2'];
$data = [
    'errors' => [['code' => 'ItemNotAccessible', 'message' => 'The ItemId B01180YUXS is not accessible through the Creators API.']],
    'itemResults' => ['items' => [$oos, $used, $noOffer]],
];
$asins = ['B0GQVBWMN8', 'B0GXWTLMJT', 'B0GD6Z2YG2', 'B01180YUXS'];
$r = MCM_Prix::parse_response($data, $asins);
check('hors stock => pas de prix', $r['offers']['B0GQVBWMN8'] === null);
check('occasion => pas de prix', $r['offers']['B0GXWTLMJT'] === null);
check('item sans offersV2 => pas de prix', $r['offers']['B0GD6Z2YG2'] === null);
check('ASIN en erreur repéré', ($r['errors']['B01180YUXS'] ?? '') === 'ItemNotAccessible');
check('ASIN absent de la réponse => null', array_key_exists('B01180YUXS', $r['offers']) && $r['offers']['B01180YUXS'] === null);

// --- 3. Cycle complet : réglages, jeton, requête, cache, rendu ---------------------------
update_option('mcm_prix_settings', ['client_id' => 'amzn1.application-oa2-client.TEST', 'client_secret' => 'SECRET_TEST', 'partner_tag' => 'monchoixmalinfr-21', 'marketplace' => 'www.amazon.fr', 'version' => '3.2', 'purge_cache' => '0']);

// rendu avant tout relevé => bouton de repli
$html = MCM_Prix::shortcode(['asin' => 'B0GHN39ZGJ']);
check('avant relevé : bouton « Voir l’offre »', strpos($html, 'Voir l’offre') !== false && strpos($html, '379') === false);
check('repli : lien standard avec le tag', strpos($html, 'amazon.fr/dp/B0GHN39ZGJ?tag=monchoixmalinfr-21') !== false);
check('ASIN enregistré automatiquement', isset(get_option('mcm_prix_asins')['B0GHN39ZGJ']));

// ajoute un 2e ASIN, puis actualisation avec jeton + GetItems simulés
MCM_Prix::shortcode(['asin' => 'B0GQVBWMN8']);
$GLOBALS['__http'] = [
    ['code' => 200, 'body' => json_encode(['access_token' => 'TOK', 'expires_in' => 3600])],
    ['code' => 200, 'body' => json_encode(['itemResults' => ['items' => [$item('B0GHN39ZGJ'), $oos]]])],
];
MCM_Prix::refresh_all();
$calls = $GLOBALS['__calls'];
check('1er appel = jeton sur api.amazon.co.uk (version 3.2)', $calls[0]['url'] === 'https://api.amazon.co.uk/auth/o2/token');
$tok = json_decode($calls[0]['args']['body'], true);
check('jeton : scope creatorsapi::default + client_credentials', $tok['scope'] === 'creatorsapi::default' && $tok['grant_type'] === 'client_credentials');
check('2e appel = getItems sur creatorsapi.amazon', $calls[1]['url'] === 'https://creatorsapi.amazon/catalog/v1/getItems');
check('en-tête Bearer sans suffixe Version', $calls[1]['args']['headers']['Authorization'] === 'Bearer TOK');
check('en-tête x-marketplace', $calls[1]['args']['headers']['x-marketplace'] === 'www.amazon.fr');
$body = json_decode($calls[1]['args']['body'], true);
check('corps : partnerTag + 2 ASIN + resources offersV2', $body['partnerTag'] === 'monchoixmalinfr-21' && count($body['itemIds']) === 2 && in_array('offersV2.listings.price', $body['resources'], true));

$html = MCM_Prix::shortcode(['asin' => 'B0GHN39ZGJ']);
check('après relevé : prix 379,99 € affiché', strpos($html, '379,99 €') !== false);
check('prix lié au lien fourni par l\'API (non modifié)', strpos($html, 'linkCode=ogi') !== false);
check('horodatage + « Précisions » présents', strpos($html, 'Précisions') !== false && preg_match('#\(au \d{2}/\d{2}/\d{4} à \d{2}h\d{2}#u', $html) === 1);
check('data-exp = relevé + 1 h', preg_match('/data-exp="(\d+)"/', $html, $m) && abs($m[1] - (time() + 3600)) < 5);
$html2 = MCM_Prix::shortcode(['asin' => 'B0GQVBWMN8']);
check('hors stock => bouton, pas de prix', strpos($html2, 'Voir l’offre') !== false);

// ASIN périmé (relevé il y a 61 min) => bouton
$c = get_option('mcm_prix_cache'); $c['B0GHN39ZGJ']['ts'] = time() - 3660; update_option('mcm_prix_cache', $c);
$html = MCM_Prix::shortcode(['asin' => 'B0GHN39ZGJ']);
check('relevé de 61 min => bouton (bascule automatique)', strpos($html, 'Voir l’offre') !== false && strpos($html, '379') === false);
$c['B0GHN39ZGJ']['ts'] = time() - 3400; update_option('mcm_prix_cache', $c);   // 56 min : au-delà de la marge de 55 min
$html = MCM_Prix::shortcode(['asin' => 'B0GHN39ZGJ']);
check('relevé de 56 min => bouton (marge de sécurité de 5 min)', strpos($html, '379') === false);

// ASIN invalide
check('ASIN invalide => message éditeur', strpos(MCM_Prix::shortcode(['asin' => 'xx']), 'ASIN invalide') !== false);

// --- 4. Pannes : le cache n'est pas rafraîchi, l'état d'erreur est enregistré ------------
$ts_before = get_option('mcm_prix_cache')['B0GQVBWMN8']['ts'];
$GLOBALS['__http'] = [['code' => 200, 'body' => json_encode(['access_token' => 'TOK', 'expires_in' => 3600])]]; // jeton déjà en cache => 1er appel = getItems
$GLOBALS['__trans'] = []; // vide le jeton et le verrou
$GLOBALS['__http'] = [
    ['code' => 200, 'body' => json_encode(['access_token' => 'TOK2', 'expires_in' => 3600])],
    ['code' => 429, 'body' => '{"message":"TooManyRequests"}'],
];
MCM_Prix::refresh_all();
$st = get_option('mcm_prix_status');
check('429 : erreur enregistrée', strpos($st['last_error'], '429') !== false);
check('429 : le cache n\'est pas écrasé', get_option('mcm_prix_cache')['B0GQVBWMN8']['ts'] === $ts_before);

$GLOBALS['__trans'] = [];
$GLOBALS['__http'] = [['code' => 400, 'body' => '{"error":"invalid_client"}']];
MCM_Prix::refresh_all();
$st = get_option('mcm_prix_status');
check('identifiants refusés : message clair', strpos($st['last_error'], 'Authentification refusée') !== false && strpos($st['last_error'], 'invalid_client') !== false);
check('le secret n\'apparaît dans aucun message d\'erreur', strpos($st['last_error'], 'SECRET_TEST') === false);

$GLOBALS['__trans'] = [];
$GLOBALS['__http'] = [
    ['code' => 200, 'body' => json_encode(['access_token' => 'TOK3', 'expires_in' => 3600])],
    ['code' => 403, 'body' => '{"message":"AssociateNotEligible"}'],
];
MCM_Prix::refresh_all();
check('AssociateNotEligible : message dédié', strpos(get_option('mcm_prix_status')['last_error'], '48 h') !== false);

// --- 5. Alerte e-mail après > 3 h d'échec, une seule par 24 h ----------------------------
$st = get_option('mcm_prix_status'); $st['last_ok'] = time() - 4 * 3600; unset($st['last_alert']); update_option('mcm_prix_status', $st);
$GLOBALS['__trans'] = []; unset($GLOBALS['__mail']);
$GLOBALS['__http'] = [['code' => 400, 'body' => '{"error":"invalid_client"}']];
MCM_Prix::refresh_all();
check('e-mail d\'alerte envoyé après 4 h sans succès', isset($GLOBALS['__mail']));
unset($GLOBALS['__mail']); $GLOBALS['__trans'] = [];
$GLOBALS['__http'] = [['code' => 400, 'body' => '{"error":"invalid_client"}']];
MCM_Prix::refresh_all();
check('pas de 2e e-mail dans les 24 h', !isset($GLOBALS['__mail']));

// --- 6. Pied de page : avis + script ----------------------------------------------------
ob_start(); MCM_Prix::footer(); $f = ob_get_clean();
check('pied de page : script de bascule présent', strpos($f, 'data-exp') !== false);


// --- 7. Suivi : référence, indisponibilité, écarts, articles ---------------------------
$meta = get_option('mcm_prix_meta');
check('prix de référence = premier prix relevé', abs(($meta['B0GHN39ZGJ']['ref'] ?? 0) - 379.99) < 0.001);
check('produit sans offre : début d\'indisponibilité noté', !empty($meta['B0GQVBWMN8']['missing_since']));
$rep = MCM_Prix::watch_report();
check('sans offre depuis < 3 jours : pas encore signalé', empty($rep['missing']));
$meta['B0GQVBWMN8']['missing_since'] = time() - 5 * 86400;
update_option('mcm_prix_meta', $meta);
$rep = MCM_Prix::watch_report();
check('sans offre depuis 5 jours : signalé', isset($rep['missing']['B0GQVBWMN8']));
check('prix stable : pas d\'écart signalé', empty($rep['dev']));
// écart de prix : référence 200 € pour un prix actuel de 379,99 €
$c = get_option('mcm_prix_cache'); $c['B0GHN39ZGJ']['ts'] = time(); update_option('mcm_prix_cache', $c);
$meta['B0GHN39ZGJ']['ref'] = 200.0; update_option('mcm_prix_meta', $meta);
$rep = MCM_Prix::watch_report();
check('écart +90 % signalé', isset($rep['dev']['B0GHN39ZGJ']) && $rep['dev']['B0GHN39ZGJ']['pct'] > 89);
$meta['B0GHN39ZGJ']['ref'] = 340.0; update_option('mcm_prix_meta', $meta);
check('écart +12 % : sous le seuil de 30 %', empty(MCM_Prix::watch_report()['dev']));

// articles liés à l'ASIN (enregistrement à la sauvegarde)
MCM_Prix::on_save_post(42, (object) ['post_content' => 'Texte [prix asin="B0GQVBWMN8"] fin']);
$rep = MCM_Prix::watch_report();
check('article lié à l\'ASIN sans offre', ($rep['missing']['B0GQVBWMN8']['posts'][0]['title'] ?? '') === 'Titre 42' && strpos($rep['missing']['B0GQVBWMN8']['posts'][0]['url'], 'post=42') !== false);

// --- 8. E-mail de synthèse ---------------------------------------------------------------
unset($GLOBALS['__mail']);
MCM_Prix::send_digest();
check('synthèse envoyée (produit sans offre)', isset($GLOBALS['__mail']) && strpos($GLOBALS['__mail'][2], 'B0GQVBWMN8') !== false && strpos($GLOBALS['__mail'][2], 'Titre 42') !== false);
check('synthèse : destinataire = administrateur par défaut', ($GLOBALS['__mail'][0] ?? '') === 'admin@example.test');
$st = get_option('mcm_prix_settings'); $st['alert_email'] = 'alerte@example.test'; update_option('mcm_prix_settings', $st);
unset($GLOBALS['__mail']); MCM_Prix::send_digest();
check('synthèse : adresse d\'alerte personnalisée', ($GLOBALS['__mail'][0] ?? '') === 'alerte@example.test');
$st['digest'] = '0'; update_option('mcm_prix_settings', $st);
unset($GLOBALS['__mail']); MCM_Prix::send_digest();
check('synthèse désactivée : aucun e-mail', !isset($GLOBALS['__mail']));
$st['digest'] = '1'; update_option('mcm_prix_settings', $st);
$meta = get_option('mcm_prix_meta'); $meta['B0GQVBWMN8']['missing_since'] = 0; update_option('mcm_prix_meta', $meta);
unset($GLOBALS['__mail']); MCM_Prix::send_digest();
check('rien à signaler : aucun e-mail', !isset($GLOBALS['__mail']));

// envoi manuel : part même sans rien à signaler, même si la synthèse automatique est désactivée
$st = get_option('mcm_prix_settings'); $st['digest'] = '0'; update_option('mcm_prix_settings', $st);
unset($GLOBALS['__mail']); $r = MCM_Prix::send_digest(true);
check('envoi manuel : e-mail parti sans alerte ni case cochée', isset($GLOBALS['__mail']) && $r['sent'] === true && strpos($GLOBALS['__mail'][1], 'envoi manuel') !== false && strpos($GLOBALS['__mail'][2], 'Aucun produit à surveiller') !== false);
$s2 = get_option('mcm_prix_status');
check('envoi manuel : résultat enregistré', ($s2['last_digest_ok'] ?? null) === 1 && ($s2['last_digest_to'] ?? '') === 'alerte@example.test');
$st['digest'] = '1'; update_option('mcm_prix_settings', $st);
unset($GLOBALS['__mail']); MCM_Prix::send_digest();
check('envoi automatique : toujours rien sans alerte', !isset($GLOBALS['__mail']));

// intervalle d'actualisation réglable
check('intervalle par défaut : 30 minutes', MCM_Prix::refresh_minutes() === 30);
$sch = MCM_Prix::cron_schedules([]);
check('intervalles déclarés (10 à 55 min)', ($sch['mcm_prix_30min']['interval'] ?? 0) === 1800 && ($sch['mcm_prix_55min']['interval'] ?? 0) === 3300 && isset($sch['mcm_prix_10min']));
$st = get_option('mcm_prix_settings'); $st['refresh_min'] = '45'; update_option('mcm_prix_settings', $st);
check('intervalle réglé à 45 minutes', MCM_Prix::refresh_minutes() === 45);
$GLOBALS['__sched'] = []; MCM_Prix::ensure_scheduled();
check('replanification au bon intervalle', ($GLOBALS['__sched'][0][1] ?? '') === 'mcm_prix_45min');
$san = MCM_Prix::sanitize_settings(['refresh_min' => '58']);
check('valeur hors liste (58) ramenée à 30', $san['refresh_min'] === '30');
$san = MCM_Prix::sanitize_settings(['refresh_min' => '20']);
check('valeur valide (20) conservée', $san['refresh_min'] === '20');
$st['refresh_min'] = '30'; update_option('mcm_prix_settings', $st);

// adresse de contrôle
check('clé de contrôle générée', strlen((string) get_option('mcm_prix_key')) === 32);
check('adresse de contrôle : sans clé -> refus 404', is_wp_error(MCM_Prix::rest_allowed(new Fake_Req([]))));
check('adresse de contrôle : mauvaise clé -> refus', is_wp_error(MCM_Prix::rest_allowed(new Fake_Req(['k' => 'faux']))));
check('adresse de contrôle : bonne clé -> accès', MCM_Prix::rest_allowed(new Fake_Req(['k' => get_option('mcm_prix_key')])) === true);
$r = MCM_Prix::rest_status(new Fake_Req([]))->get_data();
check('adresse de contrôle : version et site', ($r['plugin_version'] ?? '') === MCM_Prix::VERSION && ($r['site'] ?? '') === 'example.test');
check('adresse de contrôle : aucun secret exposé', strpos(json_encode($r), (string) (get_option('mcm_prix_settings')['client_secret'] ?? 'SECRET_ABSENT')) === false && !isset($r['client_secret']));
check('adresse de contrôle : URL contient la clé', strpos(MCM_Prix::control_url(), 'k=' . get_option('mcm_prix_key')) !== false);

// alerte quand l'actualisation ne tourne plus du tout
$stt = get_option('mcm_prix_status'); $stt['last_ok'] = time() - 4 * 3600; $stt['last_alert'] = 0; $stt['last_error'] = ''; update_option('mcm_prix_status', $stt);
delete_transient('mcm_prix_stall'); unset($GLOBALS['__mail']);
MCM_Prix::stall_check();
check('cron bloqué depuis 4 h : e-mail d\'alerte', isset($GLOBALS['__mail']) && strpos($GLOBALS['__mail'][1], 'échec') !== false && strpos($GLOBALS['__mail'][2], 'WP-Cron') !== false);
unset($GLOBALS['__mail']); delete_transient('mcm_prix_stall'); MCM_Prix::stall_check();
check('alerte au plus une fois par jour', !isset($GLOBALS['__mail']));
$stt = get_option('mcm_prix_status'); $stt['last_ok'] = time() - 600; $stt['last_alert'] = 0; update_option('mcm_prix_status', $stt);
delete_transient('mcm_prix_stall'); unset($GLOBALS['__mail']); MCM_Prix::stall_check();
check('actualisation récente : pas d\'alerte', !isset($GLOBALS['__mail']));

// site pilote : bilan de tous les sites
$st = get_option('mcm_prix_settings');
$san = MCM_Prix::sanitize_settings(['pilot' => '1', 'pilot_urls' => "https://a.test/wp-json/mcm-prix/v1/status?k=AAA\nhttp://mauvais.test/wp-json/mcm-prix/v1/status?k=B\nhttps://autre.test/ailleurs?k=C\nhttps://b.test/wp-json/mcm-prix/v1/status?k=BBB"]);
check('pilote : seules les adresses https de contrôle sont gardées', substr_count($san['pilot_urls'], "\n") === 1 && strpos($san['pilot_urls'], 'mauvais') === false && strpos($san['pilot_urls'], 'ailleurs') === false);
$st = array_merge($st, $san); update_option('mcm_prix_settings', $st);
$ok1  = ['site' => 'a.test', 'plugin_version' => MCM_Prix::VERSION, 'update_available' => '', 'configured' => true, 'refresh_minutes' => 30, 'last_refresh_ok' => date('c', time() - 600), 'last_error' => '', 'cron_late_minutes' => 0, 'wp_cron_disabled' => false, 'asin_count' => 60, 'missing_count' => 0, 'deviation_count' => 0, 'last_digest_ok' => null];
$GLOBALS['__http_get'] = [['code' => 200, 'body' => json_encode($ok1)]]; // le 2e site ne répond pas
unset($GLOBALS['__mail']);
$r = MCM_Prix::send_overview(true);
check('pilote : bilan envoyé même sans alerte sur les sites OK', $r['sent'] === true && isset($GLOBALS['__mail']) && strpos($GLOBALS['__mail'][2], 'OK — a.test') !== false);
check('pilote : site injoignable signalé', strpos($GLOBALS['__mail'][2], 'À VOIR — b.test : injoignable') !== false && strpos($GLOBALS['__mail'][1], 'à voir') !== false);
check('pilote : le site pilote figure lui-même dans le bilan', strpos($GLOBALS['__mail'][2], 'example.test : v' . MCM_Prix::VERSION) !== false);
check('pilote : aucune clé dans le bilan', strpos($GLOBALS['__mail'][2], 'AAA') === false && strpos($GLOBALS['__mail'][2], 'BBB') === false);
$old = $ok1; $old['plugin_version'] = '1.0.0'; $old['cron_late_minutes'] = 40; $old['missing_count'] = 2;
$GLOBALS['__http_get'] = [['code' => 200, 'body' => json_encode($old)], ['code' => 200, 'body' => json_encode($ok1)]];
unset($GLOBALS['__mail']); MCM_Prix::send_overview();
check('pilote : version ancienne, cron en retard, sans offre', strpos($GLOBALS['__mail'][2], 'plus ancienne') !== false && strpos($GLOBALS['__mail'][2], 'cron en retard de 40') !== false && strpos($GLOBALS['__mail'][2], '2 produit(s) sans offre') !== false);
$st['pilot'] = '0'; update_option('mcm_prix_settings', $st);
unset($GLOBALS['__mail']); $r = MCM_Prix::send_overview();
check('pilote désactivé : aucun bilan', $r['sent'] === false && !isset($GLOBALS['__mail']));
$st['pilot'] = '1'; update_option('mcm_prix_settings', $st);
unset($GLOBALS['__mail']); MCM_Prix::cron_weekly();
check('lundi : synthèse + bilan partent', isset($GLOBALS['__mail']));
$st['pilot'] = '0'; update_option('mcm_prix_settings', $st);

// alerte quotidienne : seulement les nouveaux problèmes, au plus un e-mail par jour
$st = get_option('mcm_prix_settings'); $st['digest'] = '1'; $st['new_alert'] = '1'; $st['pilot'] = '0'; update_option('mcm_prix_settings', $st);
$stt = get_option('mcm_prix_status'); unset($stt['notified'], $stt['last_new_alert']); update_option('mcm_prix_status', $stt);
$meta = get_option('mcm_prix_meta'); $meta['B0GQVBWMN8']['missing_since'] = time() - 5 * 86400; $meta['B0GHN39ZGJ']['missing_since'] = 0; update_option('mcm_prix_meta', $meta);
unset($GLOBALS['__mail']); MCM_Prix::daily_new_alert();
check('quotidien : nouveau produit sans offre -> e-mail', isset($GLOBALS['__mail']) && strpos($GLOBALS['__mail'][1], '1 nouveau') !== false && strpos($GLOBALS['__mail'][2], 'B0GQVBWMN8') !== false);
unset($GLOBALS['__mail']); MCM_Prix::daily_new_alert();
check('quotidien : déjà signalé -> pas de second e-mail', !isset($GLOBALS['__mail']));
$meta = get_option('mcm_prix_meta'); $meta['B0GHN39ZGJ']['missing_since'] = time() - 5 * 86400; update_option('mcm_prix_meta', $meta);
unset($GLOBALS['__mail']); MCM_Prix::daily_new_alert();
check('quotidien : un autre nouveau produit mais moins de 24 h -> attend', !isset($GLOBALS['__mail']));
$stt = get_option('mcm_prix_status'); $stt['last_new_alert'] = time() - 25 * 3600; update_option('mcm_prix_status', $stt);
unset($GLOBALS['__mail']); MCM_Prix::daily_new_alert();
check('quotidien : après 24 h, seul le nouveau produit est signalé', isset($GLOBALS['__mail']) && strpos($GLOBALS['__mail'][2], 'B0GHN39ZGJ') !== false && strpos($GLOBALS['__mail'][2], 'B0GQVBWMN8') === false);
$st['new_alert'] = '0'; update_option('mcm_prix_settings', $st);
$stt = get_option('mcm_prix_status'); unset($stt['notified']); $stt['last_new_alert'] = 0; update_option('mcm_prix_status', $stt);
unset($GLOBALS['__mail']); MCM_Prix::daily_new_alert();
check('quotidien : alerte désactivée -> aucun e-mail', !isset($GLOBALS['__mail']));
$st['new_alert'] = '1'; $st['digest'] = '0'; update_option('mcm_prix_settings', $st);
unset($GLOBALS['__mail']); MCM_Prix::daily_new_alert();
check('quotidien : indépendant de la synthèse du lundi (désactivée)', isset($GLOBALS['__mail']));
unset($GLOBALS['__mail']); MCM_Prix::send_digest();
check('synthèse du lundi désactivée : aucun e-mail du lundi', !isset($GLOBALS['__mail']));
$st['digest'] = '1'; update_option('mcm_prix_settings', $st);
$meta = get_option('mcm_prix_meta'); $meta['B0GQVBWMN8']['missing_since'] = 0; $meta['B0GHN39ZGJ']['missing_since'] = 0; update_option('mcm_prix_meta', $meta);
MCM_Prix::daily_new_alert();
check('quotidien : retour à la normale -> oublié', empty(get_option('mcm_prix_status')['notified']));

// alerte d'écart de prix désactivable
$meta = get_option('mcm_prix_meta'); $meta['B0GHN39ZGJ']['ref'] = 100.0; update_option('mcm_prix_meta', $meta);
$st = get_option('mcm_prix_settings'); $st['dev_alert'] = '1'; update_option('mcm_prix_settings', $st);
check('écart de prix : alerte active par défaut', !empty(MCM_Prix::watch_report()['dev']));
$st['dev_alert'] = '0'; update_option('mcm_prix_settings', $st);
check('écart de prix désactivé : plus aucun écart signalé', empty(MCM_Prix::watch_report()['dev']));
$san = MCM_Prix::sanitize_settings(['digest' => '1']);
check('case écart de prix décochée -> désactivée', $san['dev_alert'] === '0');
$st['dev_alert'] = '1'; update_option('mcm_prix_settings', $st);
$meta = get_option('mcm_prix_meta'); $meta['B0GHN39ZGJ']['ref'] = 340.0; update_option('mcm_prix_meta', $meta);

// --- 9. Textes réglables -----------------------------------------------------------------
$st = get_option('mcm_prix_settings');
$st['fallback_label'] = 'Consulter l’offre'; $st['details_label'] = 'Détails'; $st['legal_text'] = 'Avis perso sur {boutique}.';
update_option('mcm_prix_settings', $st);
$c = get_option('mcm_prix_cache'); $c['B0GHN39ZGJ']['ts'] = time(); update_option('mcm_prix_cache', $c);
$h = MCM_Prix::shortcode(['asin' => 'B0GHN39ZGJ']);
check('libellé d\'avis personnalisé', strpos($h, '– <a href="#mcm-prix-avis">Détails</a>') !== false);
$h2 = MCM_Prix::shortcode(['asin' => 'B0GQVBWMN8']);
check('texte de repli personnalisé', strpos($h2, 'Consulter l’offre') !== false && strpos($h2, 'Voir l’offre') === false);
ob_start(); MCM_Prix::footer(); $f = ob_get_clean();
check('avis légal personnalisé avec {boutique}', strpos($f, 'Avis perso sur Amazon.fr.') !== false);
$st['legal_text'] = ''; update_option('mcm_prix_settings', $st);
ob_start(); MCM_Prix::footer(); $f = ob_get_clean();
$fd = html_entity_decode($f, ENT_QUOTES, 'UTF-8');
check('avis légal vide : texte par défaut (Amazon.fr)', strpos($fd, 'sont exacts à la date et à l\'heure indiqués') !== false && strpos($fd, 'sur Amazon.fr au moment') !== false);

// --- 10. Compteur de requêtes API --------------------------------------------------------
$api = get_option('mcm_prix_api');
check('compteur de requêtes API du jour > 0', ($api['n'] ?? 0) > 0 && $api['day'] === date('Y-m-d') || ($api['n'] ?? 0) > 0);
check('429 mémorisé dans le compteur', ($api['last429'] ?? 0) > 0);

// --- 11. Réglages : valeurs bornées ------------------------------------------------------
$o = MCM_Prix::sanitize_settings(['alert_days' => 999, 'deviation_pct' => 1, 'alert_email' => 'pas-un-mail', 'fallback_label' => '  ', 'auto_update' => '1']);
check('jours bornés à 30', $o['alert_days'] === '30');
check('écart borné à 5 % minimum', $o['deviation_pct'] === '5');
check('e-mail invalide ignoré', $o['alert_email'] === '');
check('texte de repli vide => valeur par défaut', $o['fallback_label'] === 'Voir l’offre');
check('case décochée => 0', $o['digest'] === '0' && $o['auto_update'] === '1');

// --- 12. Mises à jour GitHub -------------------------------------------------------------
$zip = tempnam(sys_get_temp_dir(), 'mcmzip'); file_put_contents($zip, 'contenu-du-zip');
$hash = hash_file('sha256', $zip);
$pkg  = 'https://raw.githubusercontent.com/beefree02-h/mcm-prix-amazon/main/dist/mcm-prix-amazon-9.9.9.zip';
$GLOBALS['__trans'] = [];
$GLOBALS['__http_get'] = [['code' => 200, 'body' => json_encode(['version' => '9.9.9', 'package' => $pkg, 'sha256' => $hash, 'changelog' => 'Test'])]];
$t = MCM_Prix::check_update((object) ['response' => [], 'no_update' => []]);
check('nouvelle version annoncée à WordPress', isset($t->response['mcm-prix-amazon/mcm-prix-amazon.php']) && $t->response['mcm-prix-amazon/mcm-prix-amazon.php']->new_version === '9.9.9');
check('adresse du paquet transmise', $t->response['mcm-prix-amazon/mcm-prix-amazon.php']->package === $pkg);
$GLOBALS['__dl'] = $zip;
$r = MCM_Prix::verify_package(false, $pkg);
check('empreinte correcte : paquet accepté', $r === $zip);
file_put_contents($zip, 'contenu-altere');
$r = MCM_Prix::verify_package(false, $pkg);
check('empreinte différente : mise à jour refusée', is_wp_error($r) && strpos($r->get_error_message(), 'SHA-256') !== false);
check('paquet inconnu du fichier d\'information : refusé', is_wp_error(MCM_Prix::verify_package(false, 'https://raw.githubusercontent.com/beefree02-h/mcm-prix-amazon/main/dist/autre.zip')));
check('paquet d\'une autre origine : ignoré (traité par WordPress)', MCM_Prix::verify_package(false, 'https://example.com/x.zip') === false);
// fichier d'information invalide ou hors dépôt
$GLOBALS['__trans'] = [];
$GLOBALS['__http_get'] = [['code' => 200, 'body' => json_encode(['version' => '9.9.9', 'package' => 'https://evil.example/x.zip', 'sha256' => $hash])]];
$t = MCM_Prix::check_update((object) ['response' => [], 'no_update' => []]);
check('paquet hors du dépôt : aucune mise à jour proposée', empty($t->response));
$GLOBALS['__trans'] = [];
$GLOBALS['__http_get'] = [['code' => 404, 'body' => 'Not found']];
$t = MCM_Prix::check_update((object) ['response' => [], 'no_update' => []]);
check('GitHub injoignable : aucune erreur, aucune mise à jour', empty($t->response));
$GLOBALS['__trans'] = [];
$GLOBALS['__http_get'] = [['code' => 200, 'body' => json_encode(['version' => '1.0.0', 'package' => $pkg, 'sha256' => $hash])]];
$t = MCM_Prix::check_update((object) ['response' => [], 'no_update' => []]);
check('version identique ou plus ancienne : pas de mise à jour', empty($t->response) && !empty($t->no_update));
$st = get_option('mcm_prix_settings');
$st['auto_update'] = '1'; update_option('mcm_prix_settings', $st);
check('mise à jour automatique activée pour ce plugin', MCM_Prix::force_auto_update(false, (object) ['plugin' => 'mcm-prix-amazon/mcm-prix-amazon.php']) === true);
check('autre plugin : décision de WordPress conservée', MCM_Prix::force_auto_update(false, (object) ['plugin' => 'autre/autre.php']) === false);
$st['auto_update'] = '0'; update_option('mcm_prix_settings', $st);
check('mise à jour automatique désactivée : décision conservée', MCM_Prix::force_auto_update(false, (object) ['plugin' => 'mcm-prix-amazon/mcm-prix-amazon.php']) === false);
check('filtres de mise à jour enregistrés', !empty($GLOBALS['__filters']['pre_set_site_transient_update_plugins']) && !empty($GLOBALS['__filters']['upgrader_pre_download']) && !empty($GLOBALS['__filters']['auto_update_plugin']));


// --- 13. Page de réglages : rendu sans erreur PHP ----------------------------------------
function settings_fields($g) {}
function submit_button($t, $type = '', $name = '', $wrap = true) { echo '<button>' . $t . '</button>'; }
function selected($a, $b) { echo $a == $b ? 'selected' : ''; }
function checked($a, $b) { echo $a == $b ? 'checked' : ''; }
function wp_nonce_field($a, $n = '_wpnonce', $r = true, $e = true) { return '<input type="hidden" name="n">'; }
$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) { $warnings[] = "$str ($line)"; return true; });
$meta = get_option('mcm_prix_meta'); $meta['B0GQVBWMN8']['missing_since'] = time() - 6 * 86400; $meta['B0GHN39ZGJ']['ref'] = 150.0; update_option('mcm_prix_meta', $meta);
$GLOBALS['__trans']['mcm_prix_upd'] = ['info' => ['version' => '9.9.9'], 'checked' => time()];
ob_start(); MCM_Prix::render_admin(); $page = ob_get_clean();
restore_error_handler();
check('page de réglages : aucun avertissement PHP', !$warnings);
if ($warnings) { echo '   ' . implode(' | ', array_slice($warnings, 0, 5)) . "\n"; }
check('page de réglages : bloc « À surveiller »', strpos($page, 'À surveiller') !== false && strpos($page, 'B0GQVBWMN8') !== false);
check('page de réglages : sections Textes et Surveillance', strpos($page, 'Textes affichés') !== false && strpos($page, 'Surveillance et mises à jour') !== false);
check('page de réglages : mise à jour 9.9.9 signalée', strpos($page, 'version 9.9.9') !== false);
check('page de réglages : le secret n\'est jamais affiché', strpos($page, 'SECRET_TEST') === false);
check('page de réglages : conseil de sécurité (secret en base)', strpos($page, 'Conseil de sécurité') !== false);

// --- 9. Tableau « Prix suivis » : nom du produit et titres courts (v1.2) ---------------------
$GLOBALS['__content'][42] = '<a class="pap-prod" href="https://www.amazon.fr/dp/B0GQVBWMN8?tag=x"><img src="i.jpg" alt="Bosch MUM S&eacute;rie 2 MUMS2EB01" width="56"></a>';
$ref = new ReflectionClass('MCM_Prix');
$nom = $ref->getMethod('product_name'); $nom->setAccessible(true);
$tit = $ref->getMethod('short_title'); $tit->setAccessible(true);
$meta = (array) get_option('mcm_prix_meta');
check('nom du produit lu dans l\'alt de l\'image du lien', $nom->invoke(null, 'B0GQVBWMN8', $meta) === 'Bosch MUM Série 2 MUMS2EB01');
check('nom du produit : vide si introuvable', $nom->invoke(null, 'B0GHN39ZGJ', $meta) === '');
check('titre coupé au premier « : »', $tit->invoke(null, 'Meilleur grille-pain pas cher : notre top 5 à moins de 40 €') === 'Meilleur grille-pain pas cher');
check('titre sans « : » tronqué à 50 caractères', mb_strlen($tit->invoke(null, str_repeat('abcdefghij ', 8))) <= 50 && substr($tit->invoke(null, str_repeat('abcdefghij ', 8)), -3) === '…');
check('titre court conservé tel quel', $tit->invoke(null, 'Titre 42') === 'Titre 42');
ob_start(); MCM_Prix::render_admin(); $page2 = ob_get_clean();
check('tableau : colonne Produit avec le nom', strpos($page2, '>Produit</th>') !== false && strpos($page2, 'Bosch MUM Série 2 MUMS2EB01') !== false);
check('tableau : lien d\'édition et lien vers l\'article en ligne', strpos($page2, 'post=42') !== false && strpos($page2, 'https://example.test/?p=42') !== false);

// --- 10. Tableau « Prix suivis » : états, couleurs, filtres, tri (v1.2.1) ----------------------
$ss = $ref->getMethod('suivi_state');
$now = time();
check('état : pas encore relevé', MCM_Prix::suivi_state([], [], 3) === 'new');
check('état : offre récente = à jour', MCM_Prix::suivi_state(['ts' => $now, 'offer' => ['amount' => 10]], [], 3) === 'ok');
check('état : offre périmée = à surveiller', MCM_Prix::suivi_state(['ts' => $now - 7200, 'offer' => ['amount' => 10]], [], 3) === 'watch');
check('état : sans offre depuis peu = problème (rouge)', MCM_Prix::suivi_state(['ts' => $now], ['missing_since' => $now - 3600], 3) === 'problem');
check('état : sans offre depuis 3 jours = problème', MCM_Prix::suivi_state(['ts' => $now], ['missing_since' => $now - 4 * 86400], 3) === 'problem');
check('état : erreur API (ItemNotAccessible) = problème', MCM_Prix::suivi_state(['ts' => $now, 'err' => 'ItemNotAccessible'], [], 3) === 'problem');
check('état : un gros écart de prix reste « à jour »', MCM_Prix::suivi_state(['ts' => $now, 'offer' => ['amount' => 379.99]], ['ref' => 100.0], 3) === 'ok');
$meta = (array) get_option('mcm_prix_meta'); $meta['B0GHN39ZGJ']['ref'] = 200.0; update_option('mcm_prix_meta', $meta);
$c = get_option('mcm_prix_cache'); $c['B0GHN39ZGJ']['ts'] = time(); update_option('mcm_prix_cache', $c);
ob_start(); MCM_Prix::render_admin(); $page3 = ob_get_clean();
check('tableau : cellule Écart colorée si écart >= seuil', strpos($page3, 'mcm-ecart-hors') !== false && strpos($page3, '+90 %') !== false);
check('tableau : la ligne à gros écart reste verte', (bool) preg_match('/mcm-e-ok[^>]*data-q="[^"]*b0ghn39zgj/i', $page3));
check('tableau : ligne rouge pour le produit sans offre depuis 5 jours', (bool) preg_match('/mcm-e-problem[^>]*data-q="[^"]*b0gqvbwmn8/i', $page3));
check('tableau : problèmes affichés avant les produits à jour', strpos($page3, 'mcm-e-problem') < strpos($page3, 'mcm-e-ok'));
check('tableau : pastilles de filtre avec compteurs', strpos($page3, 'data-f="problem"') !== false && strpos($page3, 'data-f="ok"') !== false && strpos($page3, 'data-f="all"') !== false);
check('tableau : filtre par article et recherche', strpos($page3, 'id="mcm-art"') !== false && strpos($page3, 'value="42"') !== false && strpos($page3, 'id="mcm-q"') !== false);
check('tableau : en-têtes triables', substr_count($page3, 'data-col=') >= 7);
if (getenv('DUMP_PAGE')) { file_put_contents(getenv('DUMP_PAGE'), $page3); }

echo "\n" . ($fail === 0 ? "TOUS LES TESTS PASSENT" : "$fail ÉCHEC(S)") . "\n";
exit($fail === 0 ? 0 : 1);
