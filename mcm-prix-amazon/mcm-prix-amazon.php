<?php
/**
 * Plugin Name: Prix Amazon Creators
 * Description: Affiche des prix Amazon actualisés via l'API Creators avec le shortcode [prix asin="..."]. Bascule automatiquement vers « Voir l’offre » si le prix n'est plus à jour.
 * Version: 1.0.1
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * License: GPL-2.0-or-later
 * Text Domain: mcm-prix
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MCM_Prix
{
    const OPT_SETTINGS = 'mcm_prix_settings';
    const OPT_ASINS    = 'mcm_prix_asins';   // asin => timestamp d'enregistrement
    const OPT_CACHE    = 'mcm_prix_cache';   // asin => [ts, offer, url, err]
    const OPT_STATUS   = 'mcm_prix_status';
    const CRON_HOOK    = 'mcm_prix_refresh';
    const LOCK         = 'mcm_prix_lock';

    // Règle Amazon (doc Creators API, « Best Programming Practices ») : cache des offres 1 h maximum.
    const TTL       = 3600;
    // Marge de sécurité : un prix plus vieux que ça n'est plus affiché au rendu serveur.
    const FRESH_MAX = 3300;

    const API_BASE = 'https://creatorsapi.amazon';

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
        add_action('init', [__CLASS__, 'ensure_scheduled']);
        add_action('wp_footer', [__CLASS__, 'footer'], 50);
        add_action('save_post', [__CLASS__, 'on_save_post'], 10, 2);

        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'admin_menu']);
            add_action('admin_init', [__CLASS__, 'register_settings']);
            add_action('admin_notices', [__CLASS__, 'admin_notices']);
            add_action('admin_post_mcm_prix_test', [__CLASS__, 'action_test']);
            add_action('admin_post_mcm_prix_refresh_now', [__CLASS__, 'action_refresh_now']);
            add_action('admin_post_mcm_prix_rescan', [__CLASS__, 'action_rescan']);
        }
    }

    public static function activate()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'mcm_prix_30min', self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function cron_schedules($schedules)
    {
        $schedules['mcm_prix_30min'] = [
            'interval' => 1800,
            'display'  => 'Toutes les 30 minutes (Prix Amazon)',
        ];
        return $schedules;
    }

    public static function ensure_scheduled()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'mcm_prix_30min', self::CRON_HOOK);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Réglages                                                             */
    /* ------------------------------------------------------------------ */

    private static function defaults()
    {
        return [
            'client_id'     => '',
            'client_secret' => '',
            'partner_tag'   => '',
            'marketplace'   => 'www.amazon.fr',
            'version'       => '3.2',
            'purge_cache'   => '0',
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

    private static function configured()
    {
        return self::opt('client_id') !== '' && self::opt('client_secret') !== '' && self::opt('partner_tag') !== '';
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
                $cache[$asin] = [
                    'ts'    => time(),
                    'offer' => $res['offers'][$asin] ?? null,
                    'url'   => $res['urls'][$asin] ?? ($cache[$asin]['url'] ?? ''),
                    'err'   => $res['errors'][$asin] ?? '',
                ];
                $ok++;
            }
        }

        // on ne garde que les ASIN encore suivis
        $cache = array_intersect_key($cache, array_flip($asins));
        update_option(self::OPT_CACHE, $cache, false);

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
            get_option('admin_email'),
            '[' . $site . '] Prix Amazon : actualisation en échec',
            "Les prix Amazon ne sont plus actualisés depuis plus de 3 heures.\n" .
            "Le site affiche automatiquement « Voir l’offre » à la place des prix périmés.\n\n" .
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
    /* Suivi des ASIN                                                       */
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
        foreach (self::asins_in_content($post->post_content) as $a) {
            self::register_asin($a);
        }
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
        return '<a class="mcm-prix-repli" href="' . esc_url(self::fallback_url($asin, $entry)) . '" target="_blank" rel="sponsored nofollow noopener">Voir l’offre</a>';
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
            $note = ' <small class="mcm-prix-note">(au ' . esc_html(wp_date('d/m/Y à H\hi', (int) $entry['ts'])) . ' – <a href="#mcm-prix-avis">Précisions</a>)</small>';
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
            echo '<p id="mcm-prix-avis" class="mcm-prix-avis">Les prix et la disponibilité du produit sont exacts à la date et à l\'heure indiqués et peuvent être modifiés. Les informations sur les prix et la disponibilité affichées sur ' . esc_html(self::opt('marketplace') === 'www.amazon.fr' ? 'Amazon.fr' : self::opt('marketplace')) . ' au moment de l\'achat s\'appliqueront à l\'achat de ce produit.</p>';
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
        $rows  = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_content LIKE '%[prix %' AND post_status IN ('publish','draft','pending','private','future')");
        $found = [];
        foreach ($rows as $content) {
            foreach (self::asins_in_content($content) as $a) {
                $found[$a] = time();
            }
        }
        update_option(self::OPT_ASINS, $found, false);
        self::flash('Liste reconstruite : ' . count($found) . ' ASIN suivis.');
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
            echo '<div class="notice notice-warning"><p><strong>Prix Amazon</strong> : identifiants API non renseignés, les prix affichent « Voir l’offre ». <a href="' . $url . '">Réglages</a></p></div>';
            return;
        }
        $st      = (array) get_option(self::OPT_STATUS, []);
        $last_ok = (int) ($st['last_ok'] ?? 0);
        if (!$last_ok || (time() - $last_ok) > 2 * HOUR_IN_SECONDS) {
            echo '<div class="notice notice-error"><p><strong>Prix Amazon</strong> : les prix ne sont plus actualisés' . ($last_ok ? ' depuis ' . esc_html(human_time_diff($last_ok)) : '') . '. Le site affiche « Voir l’offre ». ' . (!empty($st['last_error']) ? esc_html($st['last_error']) . ' ' : '') . '<a href="' . $url . '">Réglages</a></p></div>';
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
        $by_const = defined('MCM_PRIX_CLIENT_SECRET') && MCM_PRIX_CLIENT_SECRET !== '';
        $fmt     = function ($t) {
            return $t ? esc_html(wp_date('d/m/Y H:i', (int) $t)) : '—';
        };
        $next = wp_next_scheduled(self::CRON_HOOK);
        $nonce = function ($a) {
            return wp_nonce_field($a, '_wpnonce', true, false);
        };
        ?>
        <div class="wrap">
            <h1>Prix Amazon (API Creators)</h1>

            <h2>État</h2>
            <table class="widefat striped" style="max-width:720px">
                <tr><th>Dernière actualisation réussie</th><td><?php echo $fmt($st['last_ok'] ?? 0); ?></td></tr>
                <tr><th>Dernière tentative</th><td><?php echo $fmt($st['last_run'] ?? 0); ?><?php if (!empty($st['last_error'])) { echo ' — <span style="color:#b32d2e">' . esc_html($st['last_error']) . '</span>'; } ?></td></tr>
                <tr><th>Prochaine tâche planifiée</th><td><?php echo $fmt($next); ?></td></tr>
                <tr><th>ASIN suivis</th><td><?php echo (int) count($asins); ?></td></tr>
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
            </p>

            <h2>Identifiants</h2>
            <form method="post" action="options.php">
                <?php settings_fields('mcm_prix'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="mcm_cid">Identifiant client</label></th>
                        <td><input id="mcm_cid" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>" placeholder="amzn1.application-oa2-client...." autocomplete="off"></td></tr>
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
                        <td><input id="mcm_tag" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[partner_tag]" value="<?php echo esc_attr($s['partner_tag']); ?>" placeholder="monsite-21" autocomplete="off">
                            <p class="description">Un tag différent par site.</p></td></tr>
                    <tr><th><label for="mcm_mkt">Boutique</label></th>
                        <td><input id="mcm_mkt" class="regular-text" type="text" name="<?php echo self::OPT_SETTINGS; ?>[marketplace]" value="<?php echo esc_attr($s['marketplace']); ?>"></td></tr>
                    <tr><th>Cache de pages</th>
                        <td><label><input type="checkbox" name="<?php echo self::OPT_SETTINGS; ?>[purge_cache]" value="1" <?php checked($s['purge_cache'], '1'); ?>> Vider le cache de pages après chaque actualisation</label>
                            <p class="description">Facultatif. Sans cela, un prix périmé dans une page en cache bascule tout de même sur « Voir l’offre » côté navigateur.</p></td></tr>
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
                <table class="widefat striped" style="max-width:720px">
                    <thead><tr><th>ASIN</th><th>Prix</th><th>Relevé</th><th>État</th></tr></thead>
                    <tbody>
                    <?php foreach (array_keys($asins) as $a) :
                        $e   = $cache[$a] ?? [];
                        $age = !empty($e['ts']) ? time() - (int) $e['ts'] : null;
                        if ($age === null) { $etat = 'pas encore relevé'; }
                        elseif (empty($e['offer'])) { $etat = 'pas d\'offre' . (!empty($e['err']) ? ' (' . $e['err'] . ')' : ''); }
                        elseif ($age < self::FRESH_MAX) { $etat = 'à jour'; }
                        else { $etat = 'périmé : bouton affiché'; }
                        ?>
                        <tr><td><?php echo esc_html($a); ?></td>
                            <td><?php echo !empty($e['offer']) ? esc_html($e['offer']['display']) : '—'; ?></td>
                            <td><?php echo $fmt($e['ts'] ?? 0); ?></td>
                            <td><?php echo esc_html($etat); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

register_activation_hook(__FILE__, ['MCM_Prix', 'activate']);
register_deactivation_hook(__FILE__, ['MCM_Prix', 'deactivate']);
MCM_Prix::init();
