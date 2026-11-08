<?php
/**
 * Plugin Name: Prix Amazon Creators
 * Description: Affiche des prix Amazon actualisés via l'API Creators avec le shortcode [prix asin="..."]. Bascule automatiquement vers un lien simple si le prix n'est plus à jour. Mises à jour automatiques depuis GitHub.
 * Version:     1.2.1
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/beefree02-h/mcm-prix-amazon
 * Text Domain: mcm-prix
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MCM_Prix
{
    const VERSION      = '1.2.1';
    const SLUG         = 'mcm-prix-amazon';

    const OPT_SETTINGS = 'mcm_prix_settings';
    const OPT_ASINS    = 'mcm_prix_asins';   // asin => timestamp d'enregistrement
    const OPT_CACHE    = 'mcm_prix_cache';   // asin => [ts, offer, url, err]
    const OPT_STATUS   = 'mcm_prix_status';
    const OPT_META     = 'mcm_prix_meta';    // asin => [ref, ref_ts, missing_since, posts]
    const OPT_API      = 'mcm_prix_api';     // compteur de requêtes du jour
    const CRON_HOOK    = 'mcm_prix_refresh';
    const CRON_DIGEST  = 'mcm_prix_digest';
    const LOCK         = 'mcm_prix_lock';
    const UPD_CACHE    = 'mcm_prix_upd';

    // Règle Amazon (doc Creators API, « Best Programming Practices ») : cache des offres 1 h maximum.
    const TTL       = 3600;
    // Marge de sécurité : un prix plus vieux que ça n'est plus affiché au rendu serveur.
    const FRESH_MAX = 3300;

    const API_BASE    = 'https://creatorsapi.amazon';
    const DAILY_QUOTA = 8640;

    // Mises à jour : fichier d'information et paquets publiés dans le dépôt GitHub.
    const UPDATE_JSON    = 'https://raw.githubusercontent.com/beefree02-h/mcm-prix-amazon/main/update.json';
    const PACKAGE_PREFIX = 'https://raw.githubusercontent.com/beefree02-h/mcm-prix-amazon/';

    const LEGAL_DEFAULT = 'Les prix et la disponibilité du produit sont exacts à la date et à l\'heure indiqués et peuvent être modifiés. Les informations sur les prix et la disponibilité affichées sur {boutique} au moment de l\'achat s\'appliqueront à l\'achat de ce produit.';

    const TOKEN_ENDPOINTS = [
        '3.1' => 'https://api.amazon.com/auth/o2/token',
        '3.2' => 'https://api.amazon.co.uk/auth/o2/token',
        '3.3' => 'https://api.amazon.co.jp/auth/o2/token',
    ];

    private static $used      = false; // le shortcode a été utilisé sur cette page
    private static $displayed = false; // au moins un prix chiffré affiché

    /* ------------------------------------------------------------------ */
    /* Initialisation                                                       */
    /* ------------------------------------------------------------------ */

    public static function init()
    {
        add_shortcode('prix', [__CLASS__, 'shortcode']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'refresh_all']);
        add_action(self::CRON_DIGEST, [__CLASS__, 'send_digest']);
        add_action('init', [__CLASS__, 'ensure_scheduled']);
        add_action('wp_footer', [__CLASS__, 'footer'], 50);
        add_action('save_post', [__CLASS__, 'on_save_post'], 10, 2);

        // Mises à jour depuis GitHub
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_update']);
        add_filter('plugins_api', [__CLASS__, 'plugins_api'], 20, 3);
        add_filter('upgrader_pre_download', [__CLASS__, 'verify_package'], 10, 3);
        add_filter('auto_update_plugin', [__CLASS__, 'force_auto_update'], 10, 2);

        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'admin_menu']);
            add_action('admin_init', [__CLASS__, 'register_settings']);
            add_action('admin_notices', [__CLASS__, 'admin_notices']);
            add_action('admin_post_mcm_prix_test', [__CLASS__, 'action_test']);
            add_action('admin_post_mcm_prix_refresh_now', [__CLASS__, 'action_refresh_now']);
            add_action('admin_post_mcm_prix_rescan', [__CLASS__, 'action_rescan']);
            add_action('admin_post_mcm_prix_setref', [__CLASS__, 'action_setref']);
            add_action('admin_post_mcm_prix_check_update', [__CLASS__, 'action_check_update']);
        }
    }

    public static function activate()
    {
        self::ensure_scheduled();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_DIGEST);
    }

    public static function cron_schedules($schedules)
    {
        $schedules['mcm_prix_30min'] = [
            'interval' => 1800,
            'display'  => 'Toutes les 30 minutes (Prix Amazon)',
        ];
        $schedules['mcm_prix_weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => 'Une fois par semaine (Prix Amazon)',
        ];
        return $schedules;
    }

    public static function ensure_scheduled()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'mcm_prix_30min', self::CRON_HOOK);
        }
        if (!wp_next_scheduled(self::CRON_DIGEST)) {
            wp_schedule_event(strtotime('next monday 07:00'), 'mcm_prix_weekly', self::CRON_DIGEST);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Réglages                                                             */
    /* ------------------------------------------------------------------ */

    private static function defaults()
    {
        return [
            'client_id'      => '',
            'client_secret'  => '',
            'partner_tag'    => '',
            'marketplace'    => 'www.amazon.fr',
            'version'        => '3.2',
            'purge_cache'    => '0',
            'fallback_label' => 'Voir l’offre',
            'details_label'  => 'Précisions',
            'legal_text'     => '',
            'auto_update'    => '1',
            'digest'         => '1',
            'alert_days'     => '3',
            'deviation_pct'  => '30',
            'alert_email'    => '',
        ];
    }

    public static function opt($key)
    {
        $consts = [
            'client_id'     => 'MCM_PRIX_CLIENT_ID',
            'client_secret' => 'MCM_PRIX_CLIENT_SECRET',
            'partner_tag'   => 'MCM_PRIX_PARTNER_TAG',
        ];
        if (isset($consts[$key]) && defined($consts[$key]) && constant($consts[$key]) !== '') {
            return (string) constant($consts[$key]);
        }
        $s = get_option(self::OPT_SETTINGS, []);
        $s = is_array($s) ? array_merge(self::defaults(), $s) : self::defaults();
        return isset($s[$key]) ? (string) $s[$key] : '';
    }

    private static function from_const($key)
    {
        $consts = [
            'client_id'     => 'MCM_PRIX_CLIENT_ID',
            'client_secret' => 'MCM_PRIX_CLIENT_SECRET',
            'partner_tag'   => 'MCM_PRIX_PARTNER_TAG',
        ];
        return isset($consts[$key]) && defined($consts[$key]) && constant($consts[$key]) !== '';
    }

    private static function configured()
    {
        return self::opt('client_id') !== '' && self::opt('client_secret') !== '' && self::opt('partner_tag') !== '';
    }

    private static function fallback_label()
    {
        $v = self::opt('fallback_label');
        return $v !== '' ? $v : 'Voir l’offre';
    }

    private static function details_label()
    {
        $v = self::opt('details_label');
        return $v !== '' ? $v : 'Précisions';
    }

    private static function boutique_label()
    {
        $m = self::opt('marketplace');
        return $m === 'www.amazon.fr' ? 'Amazon.fr' : $m;
    }

    private static function legal_text()
    {
        $t = self::opt('legal_text');
        if ($t === '') {
            $t = self::LEGAL_DEFAULT;
        }
        return str_replace('{boutique}', self::boutique_label(), $t);
    }

    private static function alert_to()
    {
        $e = self::opt('alert_email');
        return is_email($e) ? $e : get_option('admin_email');
    }

    /* ------------------------------------------------------------------ */
    /* API Creators                                                         */
    /* ------------------------------------------------------------------ */

    private static function get_token()
    {
        $id      = self::opt('client_id');
        $secret  = self::opt('client_secret');
        $version = self::opt('version');
        if ($id === '' || $secret === '') {
            return new WP_Error('config', 'Identifiants API non renseignés.');
        }
        if (!isset(self::TOKEN_ENDPOINTS[$version])) {
            return new WP_Error('config', 'Version d\'identifiant non prise en charge (3.1, 3.2 ou 3.3 attendues).');
        }

        $key    = 'mcm_prix_tok_' . md5($id . '|' . $version);
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $r = wp_remote_post(self::TOKEN_ENDPOINTS[$version], [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'grant_type'    => 'client_credentials',
                'client_id'     => $id,
                'client_secret' => $secret,
                'scope'         => 'creatorsapi::default',
            ]),
        ]);
        if (is_wp_error($r)) {
            return new WP_Error('network', 'Connexion impossible au service d\'authentification : ' . $r->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        $data = json_decode(wp_remote_retrieve_body($r), true);
        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            $detail = is_array($data) && isset($data['error']) ? ' (' . $data['error'] . ')' : '';
            return new WP_Error('auth', 'Authentification refusée, HTTP ' . $code . $detail . '. Vérifiez l\'identifiant, le secret et la version.');
        }
        $ttl = isset($data['expires_in']) ? max(60, (int) $data['expires_in'] - 300) : 3000;
        set_transient($key, $data['access_token'], $ttl);
        return $data['access_token'];
    }

    /** Compteur de requêtes GetItems du jour (quota initial : 8 640 par jour). */
    private static function count_call($throttled = false)
    {
        $s   = (array) get_option(self::OPT_API, []);
        $day = wp_date('Y-m-d');
        if (($s['day'] ?? '') !== $day) {
            $s = ['day' => $day, 'n' => 0, 'last429' => (int) ($s['last429'] ?? 0)];
        }
        $s['n'] = (int) ($s['n'] ?? 0) + 1;
        if ($throttled) {
            $s['last429'] = time();
        }
        update_option(self::OPT_API, $s, false);
    }

    /**
     * Interroge GetItems pour 10 ASIN maximum.
     * Retourne ['offers' => [asin => offre|null], 'urls' => [asin => url], 'errors' => [asin => code]] ou WP_Error.
     */
    public static function api_get_items(array $asins)
    {
        $token = self::get_token();
        if (is_wp_error($token)) {
            return $token;
        }
        $mkt  = self::opt('marketplace');
        $body = [
            'itemIds'    => array_values($asins),
            'itemIdType' => 'ASIN',
            'marketplace' => $mkt,
            'partnerTag' => self::opt('partner_tag'),
            'resources'  => [
                'offersV2.listings.price',
                'offersV2.listings.availability',
                'offersV2.listings.condition',
                'offersV2.listings.isBuyBoxWinner',
            ],
        ];
        $r = wp_remote_post(self::API_BASE . '/catalog/v1/getItems', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'x-marketplace' => $mkt,
            ],
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($r)) {
            return new WP_Error('network', 'Connexion impossible à l\'API : ' . $r->get_error_message());
        }
        $code    = (int) wp_remote_retrieve_response_code($r);
        $raw     = wp_remote_retrieve_body($r);
        $data    = json_decode($raw, true);
        self::count_call($code === 429);

        if ($code === 200 && is_array($data)) {
            return self::parse_response($data, $asins);
        }

        if ($code === 401) {
            delete_transient('mcm_prix_tok_' . md5(self::opt('client_id') . '|' . self::opt('version')));
        }
        if ($code === 429) {
            return new WP_Error('throttle', 'Limite de débit atteinte (HTTP 429) ou accès retiré.');
        }
        if (stripos($raw, 'AssociateNotEligible') !== false) {
            return new WP_Error('eligibility', 'Compte pas encore éligible (« AssociateNotEligible ») : délai d\'attribution jusqu\'à 48 h, ou moins de 10 ventes sur 30 jours.');
        }
        $msg = '';
        if (is_array($data)) {
            $msg = $data['message'] ?? ($data['errors'][0]['message'] ?? '');
        }
        return new WP_Error('http', 'Réponse API HTTP ' . $code . ($msg !== '' ? ' : ' . mb_substr((string) $msg, 0, 200) : '.'));
    }

    /**
     * Analyse une réponse GetItems. La documentation montre deux noms de conteneur
     * (itemResults / itemsResult) : on accepte les deux.
     */
    public static function parse_response(array $data, array $asins)
    {
        $items = $data['itemResults']['items'] ?? ($data['itemsResult']['items'] ?? []);
        $out   = ['offers' => [], 'urls' => [], 'errors' => []];

        foreach ($asins as $a) {
            $out['offers'][$a] = null; // par défaut : pas d'offre vérifiable
        }
        foreach ((array) $items as $item) {
            $asin = isset($item['asin']) ? strtoupper((string) $item['asin']) : '';
            if ($asin === '') {
                continue;
            }
            $out['offers'][$asin] = self::extract_offer($item);
            if (!empty($item['detailPageURL'])) {
                $out['urls'][$asin] = (string) $item['detailPageURL'];
            }
        }
        foreach ((array) ($data['errors'] ?? []) as $err) {
            $code = isset($err['code']) ? (string) $err['code'] : 'Erreur';
            if (isset($err['message']) && preg_match_all('/\b([A-Z0-9]{10})\b/', (string) $err['message'], $m)) {
                foreach ($m[1] as $a) {
                    if (in_array($a, $asins, true)) {
                        $out['errors'][$a] = $code;
                    }
                }
            }
        }
        return $out;
    }

    public static function extract_offer(array $item)
    {
        $listings = $item['offersV2']['listings'] ?? [];
        if (!is_array($listings) || !$listings) {
            return null; // OffersV2 : pas d'offre mise en avant = indisponible
        }
        $chosen = null;
        foreach ($listings as $l) {
            if (!empty($l['isBuyBoxWinner'])) {
                $chosen = $l;
                break;
            }
        }
        if ($chosen === null) {
            $chosen = $listings[0];
        }
        $money = $chosen['price']['money'] ?? null;
        if (!is_array($money) || !isset($money['amount'])) {
            return null;
        }
        $avail = (string) ($chosen['availability']['type'] ?? '');
        if (in_array($avail, ['OUT_OF_STOCK', 'UNAVAILABLE'], true)) {
            return null;
        }
        $cond = (string) ($chosen['condition']['value'] ?? 'New');
        if ($cond !== 'New') {
            return null; // on n'affiche jamais un prix d'occasion comme prix du neuf
        }
        return [
            'amount'   => (float) $money['amount'],
            'currency' => (string) ($money['currency'] ?? 'EUR'),
            'display'  => (string) ($money['displayAmount'] ?? ''),
            'avail'    => $avail,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Actualisation planifiée                                              */
    /* ------------------------------------------------------------------ */

    public static function refresh_all()
    {
        if (!self::configured() || get_transient(self::LOCK)) {
            return;
        }
        set_transient(self::LOCK, 1, 300);

        $start  = time();
        $asins  = array_keys((array) get_option(self::OPT_ASINS, []));
        $cache  = (array) get_option(self::OPT_CACHE, []);
        $meta   = (array) get_option(self::OPT_META, []);
        $status = (array) get_option(self::OPT_STATUS, []);

        // les plus anciens d'abord : si on manque de temps, ils passent en premier au prochain tour
        usort($asins, function ($a, $b) use ($cache) {
            return ($cache[$a]['ts'] ?? 0) <=> ($cache[$b]['ts'] ?? 0);
        });

        $ok    = 0;
        $error = '';
        foreach (array_chunk($asins, 10) as $i => $chunk) {
            if ($i > 0) {
                sleep(1); // limite initiale : 1 requête par seconde
            }
            if (time() - $start > 45) {
                break;
            }
            $res = self::api_get_items($chunk);
            if (is_wp_error($res)) {
                $error = $res->get_error_message();
                break;
            }
            foreach ($chunk as $asin) {
                $offer = $res['offers'][$asin] ?? null;
                $cache[$asin] = [
                    'ts'    => time(),
                    'offer' => $offer,
                    'url'   => $res['urls'][$asin] ?? ($cache[$asin]['url'] ?? ''),
                    'err'   => $res['errors'][$asin] ?? '',
                ];
                // suivi : prix de référence (premier prix relevé) et début de l'indisponibilité
                $m = isset($meta[$asin]) && is_array($meta[$asin]) ? $meta[$asin] : [];
                if ($offer) {
                    $m['missing_since'] = 0;
                    if (empty($m['ref'])) {
                        $m['ref']    = (float) $offer['amount'];
                        $m['ref_ts'] = time();
                    }
                } elseif (empty($m['missing_since'])) {
                    $m['missing_since'] = time();
                }
                $meta[$asin] = $m;
                $ok++;
            }
        }

        // on ne garde que les ASIN encore suivis
        $keep  = array_flip($asins);
        $cache = array_intersect_key($cache, $keep);
        $meta  = array_intersect_key($meta, $keep);
        update_option(self::OPT_CACHE, $cache, false);
        update_option(self::OPT_META, $meta, false);

        $status['last_run']   = time();
        $status['last_count'] = $ok;
        $status['last_error'] = $error;
        if ($ok > 0 && $error === '') {
            $status['last_ok'] = time();
        }
        update_option(self::OPT_STATUS, $status, false);
        delete_transient(self::LOCK);

        if ($ok > 0 && self::opt('purge_cache') === '1') {
            self::purge_page_caches();
        }
        if ($error !== '' && $asins) {
            self::maybe_alert($status, $error);
        }
    }

    private static function maybe_alert(array $status, $error)
    {
        $last_ok    = (int) ($status['last_ok'] ?? 0);
        $last_alert = (int) ($status['last_alert'] ?? 0);
        $reference  = $last_ok ?: (int) ($status['first_run'] ?? time());
        if ((time() - $reference) < 3 * HOUR_IN_SECONDS || (time() - $last_alert) < DAY_IN_SECONDS) {
            return;
        }
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        wp_mail(
            self::alert_to(),
            '[' . $site . '] Prix Amazon : actualisation en échec',
            "Les prix Amazon ne sont plus actualisés depuis plus de 3 heures.\n" .
            'Le site affiche automatiquement « ' . self::fallback_label() . " » à la place des prix périmés.\n\n" .
            'Dernière erreur : ' . $error . "\n\n" .
            'Réglages : ' . admin_url('options-general.php?page=mcm-prix')
        );
        $status['last_alert'] = time();
        update_option(self::OPT_STATUS, $status, false);
    }

    private static function purge_page_caches()
    {
        do_action('litespeed_purge_all');
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        do_action('mcm_prix_refreshed');
    }

    /* ------------------------------------------------------------------ */
    /* Suivi des ASIN et surveillance                                       */
    /* ------------------------------------------------------------------ */

    private static function register_asin($asin)
    {
        $list = (array) get_option(self::OPT_ASINS, []);
        if (!isset($list[$asin])) {
            $list[$asin] = time();
            update_option(self::OPT_ASINS, $list, false);
            $status = (array) get_option(self::OPT_STATUS, []);
            if (empty($status['first_run'])) {
                $status['first_run'] = time();
                update_option(self::OPT_STATUS, $status, false);
            }
        }
    }

    private static function asins_in_content($content)
    {
        $found = [];
        if (preg_match_all('/\[prix\b[^\]]*?\basin\s*=\s*["\']?([A-Za-z0-9]{10})["\']?/i', (string) $content, $m)) {
            foreach ($m[1] as $a) {
                $found[strtoupper($a)] = true;
            }
        }
        return array_keys($found);
    }

    public static function on_save_post($post_id, $post)
    {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        $found = self::asins_in_content($post->post_content);
        if (!$found) {
            return;
        }
        $meta = (array) get_option(self::OPT_META, []);
        foreach ($found as $a) {
            self::register_asin($a);
            $m = isset($meta[$a]) && is_array($meta[$a]) ? $meta[$a] : [];
            $p = isset($m['posts']) ? (array) $m['posts'] : [];
            if (!in_array((int) $post_id, $p, true)) {
                $p[]        = (int) $post_id;
                $m['posts'] = array_slice($p, -10);
                $meta[$a]   = $m;
            }
        }
        update_option(self::OPT_META, $meta, false);
    }

    /** Liste d'articles (titre + lien de modification) pour un ASIN. */
    private static function posts_for($asin, array $meta)
    {
        $out = [];
        foreach ((array) ($meta[$asin]['posts'] ?? []) as $id) {
            $p = get_post((int) $id);
            if (!$p || $p->post_status === 'trash') {
                continue;
            }
            $out[] = ['title' => get_the_title($p), 'id' => (int) $p->ID, 'url' => get_edit_post_link($p->ID, 'raw'), 'view' => get_permalink($p)];
        }
        return $out;
    }

    /** État d'une ligne du tableau « Prix suivis » : problem, watch, new ou ok (l'écart de prix ne change pas l'état). */
    public static function suivi_state(array $e, array $m, $days)
    {
        $age = !empty($e['ts']) ? time() - (int) $e['ts'] : null;
        if ($age === null) {
            return 'new';
        }
        if (empty($e['offer'])) {
            $since = (int) ($m['missing_since'] ?? 0);
            if (!empty($e['err']) || ($since && (time() - $since) >= max(1, (int) $days) * DAY_IN_SECONDS)) {
                return 'problem';
            }
            return 'watch';
        }
        return $age < self::FRESH_MAX ? 'ok' : 'watch';
    }

    /** Titre court : coupé au premier « : », sinon tronqué à 50 caractères. */
    private static function short_title($title)
    {
        $title = trim(wp_strip_all_tags((string) $title));
        $pos   = mb_strpos($title, ':');
        if ($pos !== false && $pos > 0) {
            $title = trim(mb_substr($title, 0, $pos));
        }
        return mb_strlen($title) > 50 ? rtrim(mb_substr($title, 0, 49)) . '…' : $title;
    }

    /** Nom d'un produit : attribut alt de l'image du lien amazon.fr/dp/ASIN dans les articles qui l'utilisent. */
    private static function product_name($asin, array $meta)
    {
        foreach ((array) ($meta[$asin]['posts'] ?? []) as $id) {
            $p = get_post((int) $id);
            if (!$p || $p->post_status === 'trash') {
                continue;
            }
            $re = '/<a\b[^>]*\bdp\/' . preg_quote($asin, '/') . '[^>]*>\s*<img\b[^>]*\balt\s*=\s*"([^"]*)"/i';
            if (preg_match($re, (string) $p->post_content, $m)) {
                $name = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                if ($name !== '') {
                    return mb_strlen($name) > 60 ? rtrim(mb_substr($name, 0, 59)) . '…' : $name;
                }
            }
        }
        return '';
    }

    /** Produits sans offre depuis N jours et prix qui s'écartent de la référence. */
    public static function watch_report()
    {
        $asins = array_keys((array) get_option(self::OPT_ASINS, []));
        $cache = (array) get_option(self::OPT_CACHE, []);
        $meta  = (array) get_option(self::OPT_META, []);
        $days  = max(1, (int) self::opt('alert_days'));
        $pct   = max(5, (int) self::opt('deviation_pct'));

        $missing = [];
        $dev     = [];
        foreach ($asins as $a) {
            $m     = isset($meta[$a]) && is_array($meta[$a]) ? $meta[$a] : [];
            $since = (int) ($m['missing_since'] ?? 0);
            if ($since && (time() - $since) >= $days * DAY_IN_SECONDS) {
                $missing[$a] = ['since' => $since, 'err' => (string) ($cache[$a]['err'] ?? ''), 'posts' => self::posts_for($a, $meta)];
            }
            $e = $cache[$a] ?? [];
            if (!empty($e['offer']) && !empty($m['ref'])) {
                $cur = (float) $e['offer']['amount'];
                $d   = ($cur - (float) $m['ref']) / (float) $m['ref'] * 100;
                if (abs($d) >= $pct) {
                    $dev[$a] = ['ref' => (float) $m['ref'], 'cur' => $cur, 'pct' => $d, 'posts' => self::posts_for($a, $meta)];
                }
            }
        }
        return ['missing' => $missing, 'dev' => $dev];
    }

    private static function money($v)
    {
        return number_format((float) $v, 2, ',', ' ') . ' €';
    }

    /** E-mail hebdomadaire : envoyé seulement s'il y a quelque chose à signaler. */
    public static function send_digest()
    {
        if (self::opt('digest') !== '1' || !self::configured()) {
            return;
        }
        $rep = self::watch_report();
        if (!$rep['missing'] && !$rep['dev']) {
            return;
        }
        $lines = [];
        if ($rep['missing']) {
            $lines[] = 'PRODUITS SANS OFFRE (à remplacer ou à vérifier) :';
            foreach ($rep['missing'] as $a => $x) {
                $lines[] = '- ' . $a . ' : sans offre depuis ' . human_time_diff($x['since']) . ($x['err'] !== '' ? ' (' . $x['err'] . ')' : '');
                foreach ($x['posts'] as $p) {
                    $lines[] = '    article : ' . $p['title'] . ' — ' . $p['url'];
                }
            }
            $lines[] = '';
        }
        if ($rep['dev']) {
            $lines[] = 'PRIX QUI S\'ÉCARTENT DE LA RÉFÉRENCE (relire le texte de l\'article) :';
            foreach ($rep['dev'] as $a => $x) {
                $lines[] = '- ' . $a . ' : ' . self::money($x['ref']) . ' → ' . self::money($x['cur']) . ' (' . ($x['pct'] > 0 ? '+' : '') . round($x['pct']) . ' %)';
                foreach ($x['posts'] as $p) {
                    $lines[] = '    article : ' . $p['title'] . ' — ' . $p['url'];
                }
            }
            $lines[] = '';
        }
        $lines[] = 'Réglages : ' . admin_url('options-general.php?page=mcm-prix');
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        wp_mail(self::alert_to(), '[' . $site . '] Prix Amazon : produits à surveiller', implode("\n", $lines));
        $status                = (array) get_option(self::OPT_STATUS, []);
        $status['last_digest'] = time();
        update_option(self::OPT_STATUS, $status, false);
    }

    /* ------------------------------------------------------------------ */
    /* Mises à jour automatiques (GitHub)                                   */
    /* ------------------------------------------------------------------ */

    /** Lit update.json (mis en cache) : retourne ['version','package','sha256',...] ou null. */
    private static function remote_info($force = false)
    {
        $c = get_transient(self::UPD_CACHE);
        if (!$force && is_array($c)) {
            return $c['info'];
        }
        $info = null;
        $r    = wp_remote_get(self::UPDATE_JSON, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            if (is_array($d)
                && !empty($d['version']) && preg_match('/^\d+(\.\d+){1,3}$/', (string) $d['version'])
                && !empty($d['package']) && strpos((string) $d['package'], self::PACKAGE_PREFIX) === 0
                && !empty($d['sha256']) && preg_match('/^[0-9a-f]{64}$/i', (string) $d['sha256'])) {
                $info = [
                    'version'      => (string) $d['version'],
                    'package'      => (string) $d['package'],
                    'sha256'       => strtolower((string) $d['sha256']),
                    'homepage'     => (string) ($d['homepage'] ?? 'https://github.com/beefree02-h/mcm-prix-amazon'),
                    'requires'     => (string) ($d['requires'] ?? '5.8'),
                    'requires_php' => (string) ($d['requires_php'] ?? '7.4'),
                    'changelog'    => (string) ($d['changelog'] ?? ''),
                ];
            }
        }
        set_transient(self::UPD_CACHE, ['info' => $info, 'checked' => time()], $info ? 3 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS);
        return $info;
    }

    public static function check_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $info   = self::remote_info();
        $plugin = plugin_basename(__FILE__);
        $item   = (object) [
            'id'           => 'github.com/beefree02-h/mcm-prix-amazon',
            'slug'         => self::SLUG,
            'plugin'       => $plugin,
            'new_version'  => self::VERSION,
            'url'          => 'https://github.com/beefree02-h/mcm-prix-amazon',
            'package'      => '',
            'requires_php' => '7.4',
        ];
        if ($info && version_compare($info['version'], self::VERSION, '>')) {
            $item->new_version  = $info['version'];
            $item->url          = $info['homepage'];
            $item->package      = $info['package'];
            $item->requires_php = $info['requires_php'];
            if (isset($transient->no_update[$plugin])) {
                unset($transient->no_update[$plugin]);
            }
            $transient->response[$plugin] = $item;
        } else {
            if (isset($transient->response[$plugin])) {
                unset($transient->response[$plugin]);
            }
            $transient->no_update[$plugin] = $item;
        }
        return $transient;
    }

    public static function plugins_api($res, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::SLUG) {
            return $res;
        }
        $info = self::remote_info();
        if (!$info) {
            return $res;
        }
        return (object) [
            'name'          => 'Prix Amazon Creators',
            'slug'          => self::SLUG,
            'version'       => $info['version'],
            'homepage'      => $info['homepage'],
            'requires'      => $info['requires'],
            'requires_php'  => $info['requires_php'],
            'download_link' => $info['package'],
            'sections'      => [
                'description' => 'Prix Amazon actualisés via l\'API Creators, avec repli automatique.',
                'changelog'   => nl2br(esc_html($info['changelog'])),
            ],
        ];
    }

    /** Télécharge le paquet et vérifie son empreinte SHA-256 avant toute installation. */
    public static function verify_package($reply, $package, $upgrader = null)
    {
        if ($reply !== false || !is_string($package) || strpos($package, self::PACKAGE_PREFIX) !== 0) {
            return $reply;
        }
        $info = self::remote_info();
        if (!$info || $info['package'] !== $package) {
            return new WP_Error('mcm_prix_pkg', 'Paquet de mise à jour non reconnu : installation annulée.');
        }
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = download_url($package, 300);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        if (!hash_equals($info['sha256'], strtolower((string) hash_file('sha256', $tmp)))) {
            @unlink($tmp);
            return new WP_Error('mcm_prix_hash', 'Contrôle d\'intégrité (SHA-256) échoué : mise à jour annulée.');
        }
        return $tmp;
    }

    public static function force_auto_update($update, $item)
    {
        if (is_object($item) && isset($item->plugin) && $item->plugin === plugin_basename(__FILE__) && self::opt('auto_update') === '1') {
            return true;
        }
        return $update;
    }

    /* ------------------------------------------------------------------ */
    /* Affichage                                                            */
    /* ------------------------------------------------------------------ */

    private static function fallback_url($asin, $entry)
    {
        if (!empty($entry['url'])) {
            return $entry['url']; // lien fourni par l'API : à ne jamais modifier
        }
        return 'https://' . self::opt('marketplace') . '/dp/' . $asin . '?tag=' . rawurlencode(self::opt('partner_tag'));
    }

    private static function fallback_html($asin, $entry)
    {
        return '<a class="mcm-prix-repli" href="' . esc_url(self::fallback_url($asin, $entry)) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html(self::fallback_label()) . '</a>';
    }

    public static function shortcode($atts)
    {
        $a    = shortcode_atts(['asin' => '', 'lien' => '1', 'horodatage' => '1'], $atts, 'prix');
        $asin = strtoupper(trim((string) $a['asin']));
        if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
            return current_user_can('edit_posts') ? '<span class="mcm-prix-erreur">[prix : ASIN invalide]</span>' : '';
        }
        self::$used = true;
        self::register_asin($asin);

        $cache = (array) get_option(self::OPT_CACHE, []);
        $entry = $cache[$asin] ?? [];
        $fb    = self::fallback_html($asin, $entry);

        $fresh = !empty($entry['offer']) && !empty($entry['ts']) && (time() - (int) $entry['ts']) < self::FRESH_MAX;
        if (!$fresh) {
            return '<span class="mcm-prix mcm-prix--repli">' . $fb . '</span>';
        }

        self::$displayed = true;
        $offer = $entry['offer'];
        $text  = $offer['display'] !== '' ? $offer['display'] : number_format((float) $offer['amount'], 2, ',', ' ') . ' ' . ($offer['currency'] === 'EUR' ? '€' : $offer['currency']);

        if ($a['lien'] !== '0') {
            $price = '<a class="mcm-prix-montant" href="' . esc_url(self::fallback_url($asin, $entry)) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html($text) . '</a>';
        } else {
            $price = '<span class="mcm-prix-montant">' . esc_html($text) . '</span>';
        }
        $note = '';
        if ($a['horodatage'] !== '0') {
            $note = ' <small class="mcm-prix-note">(au ' . esc_html(wp_date('d/m/Y à H\hi', (int) $entry['ts'])) . ' – <a href="#mcm-prix-avis">' . esc_html(self::details_label()) . '</a>)</small>';
        }
        $exp = (int) $entry['ts'] + self::TTL;
        return '<span class="mcm-prix" data-exp="' . $exp . '" data-fb="' . esc_attr($fb) . '">' . $price . $note . '</span>';
    }

    public static function footer()
    {
        if (!self::$used) {
            return;
        }
        echo '<style id="mcm-prix-css">.mcm-prix-note{font-size:.75em;opacity:.75;white-space:nowrap}.mcm-prix-montant{font-weight:inherit}.mcm-prix-avis{font-size:.8em;opacity:.8;margin:1.5em auto;max-width:60em;padding:0 1em}</style>';
        if (self::$displayed) {
            echo '<p id="mcm-prix-avis" class="mcm-prix-avis">' . esc_html(self::legal_text()) . '</p>';
        }
        // Filet de sécurité si la page est servie depuis un cache de pages : un prix expiré bascule sur le lien.
        echo '<script>(function(){var n=Math.floor(Date.now()/1000);document.querySelectorAll(".mcm-prix[data-exp]").forEach(function(e){if(n>parseInt(e.getAttribute("data-exp"),10)){e.innerHTML=e.getAttribute("data-fb");e.classList.add("mcm-prix--repli");}});})();</script>';
    }

    /* ------------------------------------------------------------------ */
    /* Administration                                                       */
    /* ------------------------------------------------------------------ */

    public static function admin_menu()
    {
        add_options_page('Prix Amazon', 'Prix Amazon', 'manage_options', 'mcm-prix', [__CLASS__, 'render_admin']);
    }

    public static function register_settings()
    {
        register_setting('mcm_prix', self::OPT_SETTINGS, ['sanitize_callback' => [__CLASS__, 'sanitize_settings']]);
    }

    public static function sanitize_settings($in)
    {
        $old = get_option(self::OPT_SETTINGS, []);
        $old = is_array($old) ? array_merge(self::defaults(), $old) : self::defaults();
        $in  = is_array($in) ? $in : [];

        $out = [];
        $out['client_id']   = isset($in['client_id']) ? trim(sanitize_text_field($in['client_id'])) : $old['client_id'];
        $secret             = isset($in['client_secret']) ? trim((string) $in['client_secret']) : '';
        $out['client_secret'] = $secret !== '' ? $secret : $old['client_secret']; // champ vide = on conserve
        $out['partner_tag'] = isset($in['partner_tag']) ? trim(sanitize_text_field($in['partner_tag'])) : $old['partner_tag'];
        $mkt                = isset($in['marketplace']) ? strtolower(trim(sanitize_text_field($in['marketplace']))) : 'www.amazon.fr';
        $out['marketplace'] = preg_match('/^www\.amazon\.[a-z.]+$/', $mkt) ? $mkt : 'www.amazon.fr';
        $ver                = isset($in['version']) ? (string) $in['version'] : '3.2';
        $out['version']     = isset(self::TOKEN_ENDPOINTS[$ver]) ? $ver : '3.2';
        $out['purge_cache'] = !empty($in['purge_cache']) ? '1' : '0';

        $fl = isset($in['fallback_label']) ? trim(sanitize_text_field($in['fallback_label'])) : $old['fallback_label'];
        $out['fallback_label'] = $fl !== '' ? $fl : 'Voir l’offre';
        $dl = isset($in['details_label']) ? trim(sanitize_text_field($in['details_label'])) : $old['details_label'];
        $out['details_label'] = $dl !== '' ? $dl : 'Précisions';
        $out['legal_text']    = isset($in['legal_text']) ? trim(sanitize_textarea_field($in['legal_text'])) : $old['legal_text'];

        $out['auto_update']   = !empty($in['auto_update']) ? '1' : '0';
        $out['digest']        = !empty($in['digest']) ? '1' : '0';
        $out['alert_days']    = (string) min(30, max(1, (int) ($in['alert_days'] ?? 3)));
        $out['deviation_pct'] = (string) min(90, max(5, (int) ($in['deviation_pct'] ?? 30)));
        $mail                 = isset($in['alert_email']) ? sanitize_email($in['alert_email']) : '';
        $out['alert_email']   = is_email($mail) ? $mail : '';
        return $out;
    }

    private static function notice_key()
    {
        return 'mcm_prix_notice_' . get_current_user_id();
    }

    private static function flash($msg, $type = 'success')
    {
        set_transient(self::notice_key(), ['msg' => $msg, 'type' => $type], 120);
    }

    private static function back()
    {
        wp_safe_redirect(admin_url('options-general.php?page=mcm-prix'));
        exit;
    }

    private static function guard($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        check_admin_referer($action);
    }

    public static function action_test()
    {
        self::guard('mcm_prix_test');
        $asin = isset($_POST['asin']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['asin'])))) : '';
        if ($asin === '') {
            $list = array_keys((array) get_option(self::OPT_ASINS, []));
            $asin = $list ? $list[0] : '';
        }
        if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
            self::flash('Indiquez un ASIN valide (10 caractères) pour le test.', 'error');
            self::back();
        }
        $res = self::api_get_items([$asin]);
        if (is_wp_error($res)) {
            self::flash('Échec du test : ' . $res->get_error_message(), 'error');
        } else {
            $o = $res['offers'][$asin] ?? null;
            if ($o) {
                self::flash('Connexion OK. ' . $asin . ' : ' . ($o['display'] ?: $o['amount']) . ' (' . ($o['avail'] ?: 'disponibilité inconnue') . ').');
            } else {
                $why = $res['errors'][$asin] ?? 'aucune offre mise en avant';
                self::flash('Connexion OK, mais pas de prix pour ' . $asin . ' (' . $why . ').', 'warning');
            }
        }
        self::back();
    }

    public static function action_refresh_now()
    {
        self::guard('mcm_prix_refresh_now');
        self::refresh_all();
        $st = (array) get_option(self::OPT_STATUS, []);
        if (!empty($st['last_error'])) {
            self::flash('Actualisation en échec : ' . $st['last_error'], 'error');
        } else {
            self::flash('Actualisation terminée : ' . (int) ($st['last_count'] ?? 0) . ' ASIN mis à jour.');
        }
        self::back();
    }

    public static function action_rescan()
    {
        self::guard('mcm_prix_rescan');
        global $wpdb;
        $rows  = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%[prix %' AND post_status IN ('publish','draft','pending','private','future')");
        $found = [];
        $posts = [];
        foreach ($rows as $row) {
            foreach (self::asins_in_content($row->post_content) as $a) {
                $found[$a]   = time();
                $posts[$a][] = (int) $row->ID;
            }
        }
        update_option(self::OPT_ASINS, $found, false);

        $meta = (array) get_option(self::OPT_META, []);
        $new  = [];
        foreach ($found as $a => $_) {
            $m          = isset($meta[$a]) && is_array($meta[$a]) ? $meta[$a] : [];
            $m['posts'] = array_slice($posts[$a], 0, 10);
            $new[$a]    = $m;
        }
        update_option(self::OPT_META, $new, false);
        self::flash('Liste reconstruite : ' . count($found) . ' ASIN suivis.');
        self::back();
    }

    /** Fixe les prix actuels comme nouveaux prix de référence (après relecture des articles). */
    public static function action_setref()
    {
        self::guard('mcm_prix_setref');
        $cache = (array) get_option(self::OPT_CACHE, []);
        $meta  = (array) get_option(self::OPT_META, []);
        $n     = 0;
        foreach ($cache as $a => $e) {
            if (!empty($e['offer'])) {
                $m           = isset($meta[$a]) && is_array($meta[$a]) ? $meta[$a] : [];
                $m['ref']    = (float) $e['offer']['amount'];
                $m['ref_ts'] = time();
                $meta[$a]    = $m;
                $n++;
            }
        }
        update_option(self::OPT_META, $meta, false);
        self::flash($n . ' prix de référence mis à jour avec les prix actuels.');
        self::back();
    }

    public static function action_check_update()
    {
        self::guard('mcm_prix_check_update');
        $info = self::remote_info(true);
        delete_site_transient('update_plugins');
        if (!$info) {
            self::flash('Impossible de lire les informations de mise à jour (GitHub injoignable ou fichier invalide).', 'error');
        } elseif (version_compare($info['version'], self::VERSION, '>')) {
            self::flash('Version ' . $info['version'] . ' disponible (installée : ' . self::VERSION . '). Rendez-vous dans Extensions pour l\'installer, ou elle le sera automatiquement.', 'warning');
        } else {
            self::flash('Le plugin est à jour (version ' . self::VERSION . ').');
        }
        self::back();
    }

    public static function admin_notices()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $flash = get_transient(self::notice_key());
        if ($flash) {
            delete_transient(self::notice_key());
            echo '<div class="notice notice-' . esc_attr($flash['type']) . ' is-dismissible"><p>' . esc_html($flash['msg']) . '</p></div>';
        }
        $asins = (array) get_option(self::OPT_ASINS, []);
        if (!$asins) {
            return;
        }
        $url = esc_url(admin_url('options-general.php?page=mcm-prix'));
        if (!self::configured()) {
            echo '<div class="notice notice-warning"><p><strong>Prix Amazon</strong> : identifiants API non renseignés, les prix affichent « ' . esc_html(self::fallback_label()) . ' ». <a href="' . $url . '">Réglages</a></p></div>';
            return;
        }
        $st      = (array) get_option(self::OPT_STATUS, []);
        $last_ok = (int) ($st['last_ok'] ?? 0);
        if (!$last_ok || (time() - $last_ok) > 2 * HOUR_IN_SECONDS) {
            echo '<div class="notice notice-error"><p><strong>Prix Amazon</strong> : les prix ne sont plus actualisés' . ($last_ok ? ' depuis ' . esc_html(human_time_diff($last_ok)) : '') . '. Le site affiche « ' . esc_html(self::fallback_label()) . ' ». ' . (!empty($st['last_error']) ? esc_html($st['last_error']) . ' ' : '') . '<a href="' . $url . '">Réglages</a></p></div>';
        }
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s       = array_merge(self::defaults(), (array) get_option(self::OPT_SETTINGS, []));
        $st      = (array) get_option(self::OPT_STATUS, []);
        $asins   = (array) get_option(self::OPT_ASINS, []);
        $cache   = (array) get_option(self::OPT_CACHE, []);
        $meta    = (array) get_option(self::OPT_META, []);
        $api     = (array) get_option(self::OPT_API, []);
        $by_const = self::from_const('client_secret');
        $fmt     = function ($t) {
            return $t ? esc_html(wp_date('d/m/Y H:i', (int) $t)) : '—';
        };
        $next    = wp_next_scheduled(self::CRON_HOOK);
        $nonce   = function ($a) {
            return wp_nonce_field($a, '_wpnonce', true, false);
        };
        $cron_off   = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $cron_late  = $next && !$cron_off && (time() - $next) > 15 * MINUTE_IN_SECONDS;
        $calls_today = (($api['day'] ?? '') === wp_date('Y-m-d')) ? (int) ($api['n'] ?? 0) : 0;
        $per_day    = 48 * (int) ceil(max(1, count($asins)) / 10);
        $upd        = get_transient(self::UPD_CACHE);
        $rep        = self::watch_report();
        $cfg_secret_db = !self::from_const('client_secret') && $s['client_secret'] !== '';
        ?>
        <div class="wrap">
            <h1>Prix Amazon (API Creators) <small style="font-weight:normal;color:#646970">v<?php echo esc_html(self::VERSION); ?></small></h1>

            <?php if ($cfg_secret_db) : ?>
                <div class="notice notice-info inline"><p><strong>Conseil de sécurité.</strong> Le secret est enregistré dans la base de données. Il est plus sûr de le placer dans <code>wp-config.php</code> :
                    <code>define('MCM_PRIX_CLIENT_SECRET', '…');</code> (idem <code>MCM_PRIX_CLIENT_ID</code> et <code>MCM_PRIX_PARTNER_TAG</code>). Le même secret sert pour tous vos sites : s'il change, il faut le mettre à jour partout.</p></div>
            <?php endif; ?>

            <?php if ($rep['missing'] || $rep['dev']) : ?>
                <div class="notice notice-warning inline" style="padding:8px 12px">
                    <p><strong>À surveiller</strong></p>
                    <?php if ($rep['missing']) : ?>
                        <p>Sans offre depuis au moins <?php echo (int) $s['alert_days']; ?> jour(s) : à remplacer ou à vérifier.</p>
                        <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ($rep['missing'] as $a => $x) : ?>
                            <li><code><?php echo esc_html($a); ?></code> — depuis <?php echo esc_html(human_time_diff($x['since'])); ?><?php echo $x['err'] !== '' ? ' (' . esc_html($x['err']) . ')' : ''; ?>
                                <?php foreach ($x['posts'] as $p) : ?> · <a href="<?php echo esc_url($p['url']); ?>"><?php echo esc_html($p['title']); ?></a><?php endforeach; ?></li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($rep['dev']) : ?>
                        <p>Prix éloigné de la référence d'au moins <?php echo (int) $s['deviation_pct']; ?> % : relire le texte de l'article.</p>
                        <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ($rep['dev'] as $a => $x) : ?>
                            <li><code><?php echo esc_html($a); ?></code> — <?php echo esc_html(self::money($x['ref'])); ?> → <?php echo esc_html(self::money($x['cur'])); ?> (<?php echo ($x['pct'] > 0 ? '+' : '') . (int) round($x['pct']); ?> %)
                                <?php foreach ($x['posts'] as $p) : ?> · <a href="<?php echo esc_url($p['url']); ?>"><?php echo esc_html($p['title']); ?></a><?php endforeach; ?></li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2>État</h2>
            <table class="widefat striped" style="max-width:760px">
                <tr><th>Dernière actualisation réussie</th><td><?php echo $fmt($st['last_ok'] ?? 0); ?></td></tr>
                <tr><th>Dernière tentative</th><td><?php echo $fmt($st['last_run'] ?? 0); ?><?php if (!empty($st['last_error'])) { echo ' — <span style="color:#b32d2e">' . esc_html($st['last_error']) . '</span>'; } ?></td></tr>
                <tr><th>Prochaine tâche planifiée</th><td><?php echo $fmt($next); ?>
                    <?php if ($cron_off) : ?> <span style="color:#996800">— WP-Cron est désactivé : une tâche du serveur doit appeler wp-cron.php</span>
                    <?php elseif ($cron_late) : ?> <span style="color:#b32d2e">— en retard : WP-Cron semble bloqué (site peu visité ?). Voir LISEZ-MOI, point 6.</span><?php endif; ?></td></tr>
                <tr><th>ASIN suivis</th><td><?php echo (int) count($asins); ?></td></tr>
                <tr><th>Requêtes API aujourd'hui</th><td><?php echo (int) $calls_today; ?> sur <?php echo (int) self::DAILY_QUOTA; ?> (quota initial, partagé par tous vos sites) — environ <?php echo (int) $per_day; ?> par jour pour ce site<?php if (!empty($api['last429'])) { echo ' — <span style="color:#b32d2e">limite atteinte le ' . $fmt($api['last429']) . '</span>'; } ?></td></tr>
                <tr><th>Identifiants</th><td><?php echo self::from_const('client_secret') ? 'dans wp-config.php' : 'dans la base de données'; ?></td></tr>
                <tr><th>Version du plugin</th><td>
                    <?php echo esc_html(self::VERSION); ?>
                    <?php if (is_array($upd) && !empty($upd['info'])) :
                        echo version_compare($upd['info']['version'], self::VERSION, '>') ? ' — <strong>version ' . esc_html($upd['info']['version']) . ' disponible</strong>' : ' — à jour';
                        echo ' <span class="description">(vérifié le ' . $fmt($upd['checked'] ?? 0) . ')</span>';
                    elseif (is_array($upd)) :
                        echo ' — <span style="color:#996800">vérification impossible (GitHub injoignable)</span>';
                    else :
                        echo ' <span class="description">(pas encore vérifié)</span>';
                    endif; ?>
                    — mise à jour automatique : <?php echo $s['auto_update'] === '1' ? 'activée' : 'désactivée'; ?></td></tr>
                <tr><th>Dernier e-mail de synthèse</th><td><?php echo $fmt($st['last_digest'] ?? 0); ?></td></tr>
            </table>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <input type="hidden" name="action" value="mcm_prix_refresh_now">
                    <?php echo $nonce('mcm_prix_refresh_now'); ?>
                    <?php submit_button('Actualiser maintenant', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px">
                    <input type="hidden" name="action" value="mcm_prix_rescan">
                    <?php echo $nonce('mcm_prix_rescan'); ?>
                    <?php submit_button('Reconstruire la liste des ASIN', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px">
                    <input type="hidden" name="action" value="mcm_prix_check_update">
                    <?php echo $nonce('mcm_prix_check_update'); ?>
                    <?php submit_button('Vérifier les mises à jour', 'secondary', 'submit', false); ?>
                </form>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('mcm_prix'); ?>

                <h2>Identifiants</h2>
                <table class="form-table" role="presentation">
                    <tr><th><label for="mcm_cid">Identifiant client</label></th>
                        <td><?php if (self::from_const('client_id')) : ?><em>Défini dans wp-config.php (MCM_PRIX_CLIENT_ID).</em><?php else : ?>
                            <input id="mcm_cid" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>" placeholder="amzn1.application-oa2-client...." autocomplete="off"><?php endif; ?></td></tr>
                    <tr><th><label for="mcm_sec">Secret</label></th>
                        <td><?php if ($by_const) : ?>
                            <em>Défini dans wp-config.php (MCM_PRIX_CLIENT_SECRET).</em>
                        <?php else : ?>
                            <input id="mcm_sec" class="regular-text" type="password" name="<?php echo self::OPT_SETTINGS; ?>[client_secret]" value="" placeholder="<?php echo $s['client_secret'] !== '' ? '•••••••• (laisser vide pour conserver)' : ''; ?>" autocomplete="new-password">
                        <?php endif; ?></td></tr>
                    <tr><th><label for="mcm_ver">Version de l'identifiant</label></th>
                        <td><select id="mcm_ver" name="<?php echo self::OPT_SETTINGS; ?>[version]">
                            <?php foreach (['3.1' => '3.1 (Amérique du Nord)', '3.2' => '3.2 (Europe)', '3.3' => '3.3 (Extrême-Orient)'] as $v => $l) : ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($s['version'], $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select> <span class="description">Indiquée à côté de l'identifiant dans Associates Central.</span></td></tr>
                    <tr><th><label for="mcm_tag">Tag Partenaires de ce site</label></th>
                        <td><?php if (self::from_const('partner_tag')) : ?><em>Défini dans wp-config.php (MCM_PRIX_PARTNER_TAG).</em><?php else : ?>
                            <input id="mcm_tag" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[partner_tag]" value="<?php echo esc_attr($s['partner_tag']); ?>" placeholder="monsite-21" autocomplete="off">
                            <p class="description">Un tag différent par site.</p><?php endif; ?></td></tr>
                    <tr><th><label for="mcm_mkt">Boutique</label></th>
                        <td><input id="mcm_mkt" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[marketplace]" value="<?php echo esc_attr($s['marketplace']); ?>"></td></tr>
                    <tr><th>Cache de pages</th>
                        <td><label><input type="checkbox" name="<?php echo self::OPT_SETTINGS; ?>[purge_cache]" value="1" <?php checked($s['purge_cache'], '1'); ?>> Vider le cache de pages après chaque actualisation</label>
                            <p class="description">Facultatif. Sans cela, un prix périmé dans une page en cache bascule tout de même sur le lien de repli côté navigateur.</p></td></tr>
                </table>

                <h2>Textes affichés</h2>
                <table class="form-table" role="presentation">
                    <tr><th><label for="mcm_fl">Texte de repli</label></th>
                        <td><input id="mcm_fl" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[fallback_label]" value="<?php echo esc_attr($s['fallback_label']); ?>" placeholder="Voir l’offre">
                            <p class="description">Affiché (avec le lien affilié) quand le prix n'est pas disponible ou plus à jour.</p></td></tr>
                    <tr><th><label for="mcm_dl">Libellé du lien d'avis</label></th>
                        <td><input id="mcm_dl" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[details_label]" value="<?php echo esc_attr($s['details_label']); ?>" placeholder="Précisions">
                            <p class="description">Dans « (au 08/11/2026 à 12h04 – Précisions) ». Le lien mène à l'avis en bas de page.</p></td></tr>
                    <tr><th><label for="mcm_lt">Avis en bas de page</label></th>
                        <td><textarea id="mcm_lt" class="large-text" rows="3" name="<?php echo self::OPT_SETTINGS; ?>[legal_text]" placeholder="<?php echo esc_attr(self::LEGAL_DEFAULT); ?>"><?php echo esc_textarea($s['legal_text']); ?></textarea>
                            <p class="description">Laissez vide pour le texte par défaut. <code>{boutique}</code> est remplacé par Amazon.fr. Gardez le sens du texte d'origine : il répond à une obligation du programme Partenaires.</p></td></tr>
                </table>

                <h2>Surveillance et mises à jour</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Mises à jour automatiques</th>
                        <td><label><input type="checkbox" name="<?php echo self::OPT_SETTINGS; ?>[auto_update]" value="1" <?php checked($s['auto_update'], '1'); ?>> Installer automatiquement les nouvelles versions du plugin (depuis GitHub)</label>
                            <p class="description">Le paquet est vérifié (empreinte SHA-256) avant installation. Vous gardez la main depuis la page Extensions.</p></td></tr>
                    <tr><th>E-mail de synthèse</th>
                        <td><label><input type="checkbox" name="<?php echo self::OPT_SETTINGS; ?>[digest]" value="1" <?php checked($s['digest'], '1'); ?>> Envoyer chaque lundi les produits à surveiller (seulement s'il y en a)</label></td></tr>
                    <tr><th><label for="mcm_ad">Produit sans offre</label></th>
                        <td>signalé après <input id="mcm_ad" type="number" min="1" max="30" style="width:70px" name="<?php echo self::OPT_SETTINGS; ?>[alert_days]" value="<?php echo esc_attr($s['alert_days']); ?>"> jour(s)</td></tr>
                    <tr><th><label for="mcm_dp">Écart de prix</label></th>
                        <td>signalé à partir de <input id="mcm_dp" type="number" min="5" max="90" style="width:70px" name="<?php echo self::OPT_SETTINGS; ?>[deviation_pct]" value="<?php echo esc_attr($s['deviation_pct']); ?>"> % par rapport au prix de référence
                            <p class="description">Le prix de référence est le premier prix relevé pour chaque produit. Après avoir relu les articles, vous pouvez le remettre à jour avec le bouton plus bas.</p></td></tr>
                    <tr><th><label for="mcm_em">Adresse d'alerte</label></th>
                        <td><input id="mcm_em" class="regular-text" type="email" name="<?php echo self::OPT_SETTINGS; ?>[alert_email]" value="<?php echo esc_attr($s['alert_email']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                            <p class="description">Vide : l'adresse de l'administrateur du site.</p></td></tr>
                </table>
                <?php submit_button('Enregistrer'); ?>
            </form>

            <h2>Tester la connexion</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mcm_prix_test">
                <?php echo $nonce('mcm_prix_test'); ?>
                <input type="text" name="asin" maxlength="10" placeholder="ASIN (facultatif)" class="regular-text">
                <?php submit_button('Tester', 'secondary', 'submit', false); ?>
            </form>

            <h2>Prix suivis</h2>
            <?php if (!$asins) : ?>
                <p>Aucun ASIN pour l'instant : ils sont ajoutés automatiquement dès qu'un article contient <code>[prix asin="..."]</code>.</p>
            <?php else : ?>
                <?php
                $jours  = max(1, (int) self::opt('alert_days'));
                $seuil  = max(5, (int) self::opt('deviation_pct'));
                $rang   = ['problem' => 0, 'watch' => 1, 'new' => 2, 'ok' => 3];
                $labels = ['problem' => 'Problème', 'watch' => 'À surveiller', 'new' => 'Pas encore relevé', 'ok' => 'À jour'];
                $icones = ['problem' => 'dismiss', 'watch' => 'warning', 'new' => 'clock', 'ok' => 'yes-alt'];
                $comptes = ['problem' => 0, 'watch' => 0, 'new' => 0, 'ok' => 0];
                $arts    = [];
                $lignes  = [];
                foreach (array_keys($asins) as $a) {
                    $e   = $cache[$a] ?? [];
                    $m   = isset($meta[$a]) && is_array($meta[$a]) ? $meta[$a] : [];
                    $age = !empty($e['ts']) ? time() - (int) $e['ts'] : null;
                    if ($age === null) { $etat = 'pas encore relevé'; }
                    elseif (empty($e['offer'])) {
                        $etat = 'pas d\'offre' . (!empty($e['err']) ? ' (' . $e['err'] . ')' : '');
                        if (!empty($m['missing_since'])) { $etat .= ' depuis ' . human_time_diff((int) $m['missing_since']); }
                    }
                    elseif ($age < self::FRESH_MAX) { $etat = 'à jour'; }
                    else { $etat = 'périmé : lien de repli affiché'; }
                    $cle   = self::suivi_state($e, $m, $jours);
                    $d     = null;
                    $ecart = '—';
                    if (!empty($e['offer']) && !empty($m['ref'])) {
                        $d     = ((float) $e['offer']['amount'] - (float) $m['ref']) / (float) $m['ref'] * 100;
                        $ecart = ($d > 0 ? '+' : '') . (int) round($d) . ' %';
                    }
                    $liste = self::posts_for($a, $meta);
                    foreach ($liste as $p) { $arts[(int) $p['id']] = self::short_title($p['title']); }
                    $comptes[$cle]++;
                    $lignes[] = [
                        'a' => $a, 'e' => $e, 'm' => $m, 'cle' => $cle, 'etat' => $etat, 'd' => $d, 'ecart' => $ecart,
                        'hors' => $d !== null && abs($d) >= $seuil, 'nom' => self::product_name($a, $meta), 'liste' => $liste,
                    ];
                }
                // Par défaut : les problèmes d'abord, puis les produits à surveiller.
                usort($lignes, function ($x, $y) use ($rang) {
                    return [$rang[$x['cle']], strtolower($x['nom'] !== '' ? $x['nom'] : $x['a'])] <=> [$rang[$y['cle']], strtolower($y['nom'] !== '' ? $y['nom'] : $y['a'])];
                });
                asort($arts);
                ?>
                <style>
                    .mcm-bar { display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; margin: 0 0 10px; max-width: 1100px; }
                    .mcm-bar .button.is-on { background: #2271b1; border-color: #2271b1; color: #fff; }
                    .mcm-bar .mcm-n { opacity: .75; margin-left: 2px; }
                    .mcm-bar .mcm-info { margin-left: auto; color: #646970; }
                    .mcm-prix-suivis th[data-col] { cursor: pointer; white-space: nowrap; }
                    .mcm-prix-suivis th[data-col]::after { content: ' \2195'; opacity: .35; }
                    .mcm-prix-suivis th[aria-sort="ascending"]::after { content: ' \25B2'; opacity: 1; }
                    .mcm-prix-suivis th[aria-sort="descending"]::after { content: ' \25BC'; opacity: 1; }
                    .mcm-prix-suivis tr.mcm-e-problem td:first-child { box-shadow: inset 4px 0 0 #d63638; }
                    .mcm-prix-suivis tr.mcm-e-watch td:first-child { box-shadow: inset 4px 0 0 #dba617; }
                    .mcm-prix-suivis tr.mcm-e-ok td:first-child { box-shadow: inset 4px 0 0 #00a32a; }
                    .mcm-prix-suivis tr.mcm-e-new td:first-child { box-shadow: inset 4px 0 0 #a7aaad; }
                    .mcm-pill { display: inline-block; padding: 1px 8px 1px 4px; border-radius: 10px; white-space: nowrap; }
                    .mcm-pill .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; }
                    .mcm-p-problem { background: #fcf0f1; color: #8a1f21; }
                    .mcm-p-watch { background: #fcf9e8; color: #6e4e00; }
                    .mcm-p-ok { background: #edfaef; color: #0a5b1e; }
                    .mcm-p-new { background: #f0f0f1; color: #50575e; }
                    .mcm-ecart-hors { background: #fcf0d4; color: #8a4b00; font-weight: 600; padding: 1px 6px; border-radius: 3px; }
                    .mcm-prix-suivis td.mcm-nom, .mcm-prix-suivis td.mcm-art { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                    .mcm-prix-suivis td.mcm-art a { text-decoration: none; }
                    .mcm-prix-suivis td.mcm-art .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: middle; }
                    .mcm-prix-suivis td.mcm-art details { display: inline; }
                    .mcm-prix-suivis td.mcm-art summary { display: inline; cursor: pointer; color: #2271b1; }
                    .mcm-prix-suivis td.mcm-art details[open] { display: block; white-space: normal; }
                </style>
                <div class="mcm-bar" id="mcm-bar">
                    <button type="button" class="button is-on" data-f="all">Tous <span class="mcm-n">(<?php echo (int) count($lignes); ?>)</span></button>
                    <?php foreach (['problem', 'watch', 'ok', 'new'] as $k) : if ($k === 'new' && !$comptes[$k]) { continue; } ?>
                        <button type="button" class="button" data-f="<?php echo esc_attr($k); ?>"><?php echo esc_html($labels[$k]); ?> <span class="mcm-n">(<?php echo (int) $comptes[$k]; ?>)</span></button>
                    <?php endforeach; ?>
                    <select id="mcm-art" aria-label="Filtrer par article">
                        <option value="">Tous les articles</option>
                        <?php foreach ($arts as $id => $t) : ?><option value="<?php echo (int) $id; ?>"><?php echo esc_html($t); ?></option><?php endforeach; ?>
                    </select>
                    <input type="search" id="mcm-q" placeholder="Produit ou ASIN" aria-label="Rechercher un produit">
                    <span class="mcm-info" id="mcm-info" aria-live="polite"></span>
                </div>
                <table class="widefat striped mcm-prix-suivis" id="mcm-tab" style="max-width:1100px">
                    <thead><tr><th data-col="nom" data-t="s">Produit</th><th data-col="asin" data-t="s">ASIN</th><th data-col="prix" data-t="n">Prix</th><th data-col="ref" data-t="n">Référence</th><th data-col="ecart" data-t="n">Écart</th><th data-col="ts" data-t="n">Relevé</th><th data-col="etat" data-t="n">État</th><th>Articles</th></tr></thead>
                    <tbody>
                    <?php foreach ($lignes as $r) :
                        $a = $r['a']; $e = $r['e']; $m = $r['m']; $cle = $r['cle'];
                        $ids = ',' . implode(',', array_map(function ($p) { return (int) $p['id']; }, $r['liste'])) . ',';
                        ?>
                        <tr class="mcm-r mcm-e-<?php echo esc_attr($cle); ?>" data-etat="<?php echo esc_attr($cle); ?>" data-arts="<?php echo esc_attr($ids); ?>" data-q="<?php echo esc_attr(strtolower($r['nom'] . ' ' . $a)); ?>">
                            <td class="mcm-nom" data-v="<?php echo esc_attr(strtolower($r['nom'])); ?>"<?php echo $r['nom'] !== '' ? ' title="' . esc_attr($r['nom']) . '"' : ''; ?>><?php echo $r['nom'] !== '' ? esc_html($r['nom']) : '—'; ?></td>
                            <td data-v="<?php echo esc_attr(strtolower($a)); ?>"><?php echo esc_html($a); ?></td>
                            <td data-v="<?php echo !empty($e['offer']) ? esc_attr((string) (float) $e['offer']['amount']) : ''; ?>"><?php echo !empty($e['offer']) ? esc_html($e['offer']['display']) : '—'; ?></td>
                            <td data-v="<?php echo !empty($m['ref']) ? esc_attr((string) (float) $m['ref']) : ''; ?>"><?php echo !empty($m['ref']) ? esc_html(self::money($m['ref'])) : '—'; ?></td>
                            <td data-v="<?php echo $r['d'] !== null ? esc_attr((string) round($r['d'], 2)) : ''; ?>"><?php
                                if ($r['hors']) { echo '<span class="mcm-ecart-hors" title="Écart supérieur ou égal au seuil de ' . (int) $seuil . ' %">' . esc_html($r['ecart']) . '</span>'; }
                                else { echo esc_html($r['ecart']); }
                            ?></td>
                            <td data-v="<?php echo (int) ($e['ts'] ?? 0) ?: ''; ?>"><?php echo $fmt($e['ts'] ?? 0); ?></td>
                            <td data-v="<?php echo (int) $rang[$cle]; ?>"><span class="mcm-pill mcm-p-<?php echo esc_attr($cle); ?>" title="<?php echo esc_attr($labels[$cle]); ?>"><span class="dashicons dashicons-<?php echo esc_attr($icones[$cle]); ?>"></span> <?php echo esc_html($r['etat']); ?></span></td>
                            <td class="mcm-art"><?php
                                $liste = $r['liste'];
                                $ligne = function ($p) {
                                    return '<a href="' . esc_url($p['url']) . '" title="' . esc_attr($p['title']) . '">' . esc_html(self::short_title($p['title'])) . '</a> '
                                        . '<a href="' . esc_url($p['view']) . '" target="_blank" rel="noopener" title="Voir l\'article en ligne"><span class="dashicons dashicons-external"></span></a>';
                                };
                                foreach (array_slice($liste, 0, 2) as $p) { echo $ligne($p) . '<br>'; }
                                if (count($liste) > 2) {
                                    echo '<details><summary>+' . (count($liste) - 2) . ' autre' . (count($liste) > 3 ? 's' : '') . '</summary>';
                                    foreach (array_slice($liste, 2) as $p) { echo $ligne($p) . '<br>'; }
                                    echo '</details>';
                                }
                            ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <script>
                (function () {
                    var tab = document.getElementById('mcm-tab'), bar = document.getElementById('mcm-bar');
                    if (!tab || !bar) { return; }
                    var body = tab.tBodies[0], rows = Array.prototype.slice.call(body.rows);
                    var sel = document.getElementById('mcm-art'), q = document.getElementById('mcm-q'), info = document.getElementById('mcm-info');
                    var etat = 'all';
                    function apply() {
                        var term = q.value.toLowerCase().trim(), art = sel.value, n = 0;
                        rows.forEach(function (r) {
                            var ok = (etat === 'all' || r.getAttribute('data-etat') === etat)
                                && (!art || r.getAttribute('data-arts').indexOf(',' + art + ',') !== -1)
                                && (!term || r.getAttribute('data-q').indexOf(term) !== -1);
                            r.style.display = ok ? '' : 'none';
                            if (ok) { n++; }
                        });
                        info.textContent = n + ' produit' + (n > 1 ? 's' : '') + ' affiché' + (n > 1 ? 's' : '') + ' sur ' + rows.length;
                    }
                    bar.addEventListener('click', function (ev) {
                        var b = ev.target.closest ? ev.target.closest('button[data-f]') : null;
                        if (!b) { return; }
                        etat = b.getAttribute('data-f');
                        Array.prototype.forEach.call(bar.querySelectorAll('button[data-f]'), function (x) { x.classList.toggle('is-on', x === b); });
                        apply();
                    });
                    sel.addEventListener('change', apply);
                    q.addEventListener('input', apply);
                    Array.prototype.forEach.call(tab.tHead.rows[0].cells, function (th, i) {
                        if (!th.hasAttribute('data-col')) { return; }
                        th.addEventListener('click', function () {
                            var asc = th.getAttribute('aria-sort') !== 'ascending', num = th.getAttribute('data-t') === 'n';
                            Array.prototype.forEach.call(tab.tHead.rows[0].cells, function (x) { x.removeAttribute('aria-sort'); });
                            th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
                            rows.sort(function (x, y) {
                                var a = x.cells[i].getAttribute('data-v'), b = y.cells[i].getAttribute('data-v');
                                if (a === '' && b === '') { return 0; }
                                if (a === '') { return 1; }
                                if (b === '') { return -1; }
                                var c = num ? parseFloat(a) - parseFloat(b) : (a < b ? -1 : (a > b ? 1 : 0));
                                return asc ? c : -c;
                            });
                            rows.forEach(function (r) { body.appendChild(r); });
                        });
                    });
                    apply();
                })();
                </script>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px">
                    <input type="hidden" name="action" value="mcm_prix_setref">
                    <?php echo $nonce('mcm_prix_setref'); ?>
                    <?php submit_button('Prendre les prix actuels comme références', 'secondary', 'submit', false); ?>
                    <span class="description">À utiliser après avoir relu les articles : les écarts repartent de zéro.</span>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

register_activation_hook(__FILE__, ['MCM_Prix', 'activate']);
register_deactivation_hook(__FILE__, ['MCM_Prix', 'deactivate']);
MCM_Prix::init();
