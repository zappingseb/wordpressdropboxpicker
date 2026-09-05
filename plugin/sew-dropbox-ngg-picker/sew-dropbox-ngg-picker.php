<?php
/**
 * Plugin Name:       SEW Dropbox to NextGEN Picker
 * Plugin URI:        https://github.com/zappingseb/wordpressdropboxpicker
 * Description:       Pick photos from a Dropbox folder by date range, select them in a full-width grid, and create a NextGEN gallery from them (download, resize to max 2048 px / 500 KB, register, thumbnails). Menu: "Dropbox Picker".
 * Version:           1.0.0
 * Author:            Sebastian Engel-Wolf
 * License:           GPL-2.0-or-later
 * Requires at least: 5.5
 * Requires PHP:      7.4
 *
 * Source: https://github.com/zappingseb/wordpressdropboxpicker -> plugin/
 * Deploy: python3 deploy/deploy.py (in that repo). Do not edit on the server.
 *
 * How it hangs together
 * ---------------------
 *  - Settings page: Dropbox app key/secret, "Connect" (OAuth2 code flow with a
 *    refresh token, redirect URI <home>/oauth/dropbox/callback), diagnostics.
 *  - Picker page: folder + FROM/TO date -> grid of Dropbox thumbnails -> select
 *    -> right panel -> "Create NGG gallery" (confirm) -> the browser drives the
 *    import one image per AJAX call so shared-hosting time limits never bite:
 *      gallery_create  -> wp-content/gallery/<slug>/
 *      gallery_add     -> download original, resize <= 2048 px & <= 500 KB
 *      gallery_finish  -> nggAdmin::import_gallery + thumbnails
 *  - The OAuth callback URL is intercepted on `init`, before WordPress's
 *    canonical redirect can touch it, so it works without pretty permalinks.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEW_DNP_VERSION', '1.0.0');
define('SEW_DNP_FILE', __FILE__);
define('SEW_DNP_DIR', plugin_dir_path(__FILE__));
define('SEW_DNP_URL', plugin_dir_url(__FILE__));
define('SEW_DNP_OPTION_SETTINGS', 'sew_dnp_settings');
define('SEW_DNP_OPTION_TOKENS', 'sew_dnp_tokens');
define('SEW_DNP_CALLBACK_PATH', '/oauth/dropbox/callback');
define('SEW_DNP_CAP', 'manage_options');
define('SEW_DNP_PAGE', 'sew-dropbox-picker');
define('SEW_DNP_SETTINGS_PAGE', 'sew-dropbox-picker-settings');

// Optional, deploy-rendered credentials (gitignored, written by deploy/deploy.py from
// the repo's .env). Defines SEW_DNP_APP_KEY / SEW_DNP_APP_SECRET and optionally
// SEW_DNP_TEST_TOKEN; the same constants may also be put into wp-config.php.
if (file_exists(SEW_DNP_DIR . 'sew-dnp-config.php')) {
    require_once SEW_DNP_DIR . 'sew-dnp-config.php';
}

require_once SEW_DNP_DIR . 'includes/class-dropbox-client.php';
require_once SEW_DNP_DIR . 'includes/class-image-processor.php';
require_once SEW_DNP_DIR . 'includes/class-ngg-importer.php';

final class SEW_Dropbox_NGG_Picker {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_handle_oauth_callback'), 0);
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('admin_notices', array($this, 'notices'));
        add_action('admin_post_sew_dnp_connect', array($this, 'handle_connect'));
        add_action('admin_post_sew_dnp_disconnect', array($this, 'handle_disconnect'));
        add_action('admin_post_sew_dnp_selftest', array($this, 'handle_selftest'));

        foreach (array('status', 'folders', 'scan', 'thumbs', 'gallery_create', 'gallery_add', 'gallery_finish') as $action) {
            add_action('wp_ajax_sew_dnp_' . $action, array($this, 'ajax_' . $action));
        }
    }

    // ================================================================== helpers

    public static function redirect_uri() {
        return home_url(SEW_DNP_CALLBACK_PATH);
    }

    public static function picker_url() {
        return admin_url('admin.php?page=' . SEW_DNP_PAGE);
    }

    public static function settings_url() {
        return admin_url('admin.php?page=' . SEW_DNP_SETTINGS_PAGE);
    }

    /** Stored settings, with constants from the config file taking precedence. */
    public static function settings() {
        $settings = (array) get_option(SEW_DNP_OPTION_SETTINGS, array());
        if (defined('SEW_DNP_APP_KEY') && SEW_DNP_APP_KEY !== '') {
            $settings['app_key'] = SEW_DNP_APP_KEY;
            $settings['app_key_from_config'] = true;
        }
        if (defined('SEW_DNP_APP_SECRET') && SEW_DNP_APP_SECRET !== '') {
            $settings['app_secret'] = SEW_DNP_APP_SECRET;
            $settings['app_secret_from_config'] = true;
        }
        if (empty($settings['manual_token']) && defined('SEW_DNP_TEST_TOKEN') && SEW_DNP_TEST_TOKEN !== '' && !empty($settings['use_test_token'])) {
            $settings['manual_token'] = SEW_DNP_TEST_TOKEN;
            $settings['manual_token_from_config'] = true;
        }
        return $settings;
    }

    private function client() {
        return new SEW_DNP_Dropbox_Client(self::settings(), (array) get_option(SEW_DNP_OPTION_TOKENS, array()));
    }

    private function redirect_with_notice($url, $type, $message) {
        set_transient('sew_dnp_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
        wp_safe_redirect($url);
        exit;
    }

    // ================================================================== oauth

    /** Intercept <home>/oauth/dropbox/callback before WordPress routes it. */
    public function maybe_handle_oauth_callback() {
        if (empty($_SERVER['REQUEST_URI'])) {
            return;
        }
        $path = rtrim((string) wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($path !== $home . SEW_DNP_CALLBACK_PATH) {
            return;
        }
        nocache_headers();
        if (!is_user_logged_in() || !current_user_can(SEW_DNP_CAP)) {
            wp_die('You must be logged in to WordPress as an administrator (in this browser) to connect Dropbox.', 'Dropbox connect', array('response' => 403));
        }
        if (isset($_GET['error'])) {
            $desc = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash($_GET['error_description'])) : sanitize_text_field(wp_unslash($_GET['error']));
            $this->redirect_with_notice(self::settings_url(), 'error', 'Dropbox refused the connection: ' . $desc);
        }
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $expected_user = $state ? get_transient('sew_dnp_oauth_' . $state) : false;
        if (!$state || !$code || (int) $expected_user !== get_current_user_id()) {
            $this->redirect_with_notice(self::settings_url(), 'error', 'Dropbox connect: state mismatch or missing code. Please try "Connect Dropbox" again.');
        }
        delete_transient('sew_dnp_oauth_' . $state);
        try {
            $client = $this->client();
            $tokens = $client->exchange_code($code, self::redirect_uri());
            $account = '';
            try {
                $info = $client->current_account();
                $account = isset($info['name']['display_name']) ? $info['name']['display_name'] : '';
                if (!empty($info['email'])) {
                    $account .= ' (' . $info['email'] . ')';
                }
                $tokens['account_label'] = $account;
                update_option(SEW_DNP_OPTION_TOKENS, $tokens, false);
            } catch (Exception $ignored) {
            }
            $this->redirect_with_notice(self::picker_url(), 'success', 'Dropbox connected' . ($account ? ' as ' . $account : '') . '.');
        } catch (Exception $exc) {
            $this->redirect_with_notice(self::settings_url(), 'error', $exc->getMessage());
        }
    }

    public function handle_connect() {
        check_admin_referer('sew_dnp_connect');
        if (!current_user_can(SEW_DNP_CAP)) {
            wp_die('forbidden', '', array('response' => 403));
        }
        $client = $this->client();
        if (!$client->is_configured()) {
            $this->redirect_with_notice(self::settings_url(), 'error', 'Enter the Dropbox app key and app secret first.');
        }
        $state = wp_generate_password(32, false, false);
        set_transient('sew_dnp_oauth_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS);
        wp_redirect($client->authorize_url(self::redirect_uri(), $state));
        exit;
    }

    public function handle_disconnect() {
        check_admin_referer('sew_dnp_disconnect');
        if (!current_user_can(SEW_DNP_CAP)) {
            wp_die('forbidden', '', array('response' => 403));
        }
        delete_option(SEW_DNP_OPTION_TOKENS);
        $this->redirect_with_notice(self::settings_url(), 'success', 'Dropbox disconnected. The stored tokens were deleted.');
    }

    // ================================================================== admin ui

    public function register_menu() {
        add_menu_page(
            'Dropbox Picker', 'Dropbox Picker', SEW_DNP_CAP, SEW_DNP_PAGE,
            array($this, 'render_picker'), 'dashicons-cloud-upload', 26
        );
        add_submenu_page(SEW_DNP_PAGE, 'Pick photos', 'Pick photos', SEW_DNP_CAP, SEW_DNP_PAGE, array($this, 'render_picker'));
        add_submenu_page(SEW_DNP_PAGE, 'Dropbox settings', 'Settings', SEW_DNP_CAP, SEW_DNP_SETTINGS_PAGE, array($this, 'render_settings'));
    }

    public function register_settings() {
        register_setting('sew_dnp', SEW_DNP_OPTION_SETTINGS, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default'           => array(),
        ));
    }

    public function sanitize_settings($input) {
        $old = (array) get_option(SEW_DNP_OPTION_SETTINGS, array());
        $input = (array) $input;
        $out = array(
            'app_key'      => isset($input['app_key']) ? sanitize_text_field($input['app_key']) : '',
            'app_secret'   => isset($input['app_secret']) ? trim((string) $input['app_secret']) : '',
            'manual_token' => isset($input['manual_token']) ? trim((string) $input['manual_token']) : (isset($old['manual_token']) ? $old['manual_token'] : ''),
            'thumb_size'   => isset($input['thumb_size']) && in_array($input['thumb_size'], array('w256h256', 'w480h320', 'w640h480'), true) ? $input['thumb_size'] : 'w480h320',
            'use_test_token' => !empty($input['use_test_token']) ? 1 : 0,
        );
        // Leaving the secret blank keeps the stored one (the field is never pre-filled).
        if ($out['app_secret'] === '' && !empty($old['app_secret'])) {
            $out['app_secret'] = $old['app_secret'];
        }
        if (!empty($input['clear_secret'])) {
            $out['app_secret'] = '';
        }
        return $out;
    }

    public function notices() {
        $notice = get_transient('sew_dnp_notice_' . get_current_user_id());
        if (!$notice) {
            return;
        }
        delete_transient('sew_dnp_notice_' . get_current_user_id());
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($notice['type'] === 'error' ? 'error' : 'success'),
            esc_html($notice['message']));
    }

    public function enqueue($hook) {
        if (strpos((string) $hook, SEW_DNP_PAGE) === false) {
            return;
        }
        // File mtimes as cache busters, so every deploy invalidates the browser cache.
        $css_ver = SEW_DNP_VERSION . '.' . (int) @filemtime(SEW_DNP_DIR . 'admin/picker.css');
        $js_ver  = SEW_DNP_VERSION . '.' . (int) @filemtime(SEW_DNP_DIR . 'admin/picker.js');
        wp_enqueue_style('sew-dnp', SEW_DNP_URL . 'admin/picker.css', array(), $css_ver);
        if ($hook === 'toplevel_page_' . SEW_DNP_PAGE) {
            $client = $this->client();
            $settings = self::settings();
            wp_enqueue_script('sew-dnp', SEW_DNP_URL . 'admin/picker.js', array(), $js_ver, true);
            wp_localize_script('sew-dnp', 'SEW_DNP', array(
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce('sew_dnp_ajax'),
                'connected'     => $client->is_connected(),
                'settingsUrl'   => self::settings_url(),
                'thumbBatch'    => SEW_DNP_Dropbox_Client::THUMB_BATCH_MAX,
                'thumbSize'     => !empty($settings['thumb_size']) ? $settings['thumb_size'] : 'w480h320',
                'maxDim'        => SEW_DNP_Image_Processor::MAX_DIM,
                'maxKb'         => (int) (SEW_DNP_Image_Processor::MAX_BYTES / 1024),
                'galleryBase'   => SEW_DNP_NGG_Importer::gallery_basedir(),
                'imageExts'     => SEW_DNP_Dropbox_Client::IMAGE_EXTENSIONS,
            ));
        }
    }

    public function render_picker() {
        if (!current_user_can(SEW_DNP_CAP)) {
            return;
        }
        $client = $this->client();
        $connected = $client->is_connected();
        ?>
        <div class="wrap sew-dnp-wrap" id="sew-dnp-app">
            <h1 class="wp-heading-inline">Dropbox Picker <span class="sew-dnp-sub">select photos, create a NextGEN gallery</span></h1>
            <?php if (!$connected) : ?>
                <div class="notice notice-warning inline"><p>
                    Dropbox is not connected. <a href="<?php echo esc_url(self::settings_url()); ?>">Open the settings</a> to enter the app credentials and connect.
                </p></div>
            <?php endif; ?>
            <?php if (!SEW_DNP_NGG_Importer::is_available()) : ?>
                <div class="notice notice-error inline"><p>NextGEN Gallery does not seem to be active. Galleries cannot be created.</p></div>
            <?php endif; ?>

            <div class="sew-dnp-toolbar" <?php echo $connected ? '' : 'data-disabled="1"'; ?>>
                <div class="sew-dnp-field sew-dnp-field-folder">
                    <label for="sew-dnp-folder">Folder</label>
                    <div class="sew-dnp-folder-row">
                        <input type="text" id="sew-dnp-folder" placeholder="/Camera Uploads" autocomplete="off" spellcheck="false">
                        <button type="button" class="button" id="sew-dnp-browse">Browse&hellip;</button>
                    </div>
                    <label class="sew-dnp-check"><input type="checkbox" id="sew-dnp-recursive"> include subfolders</label>
                    <div class="sew-dnp-browser" id="sew-dnp-browser" hidden>
                        <div class="sew-dnp-browser-head">
                            <span class="sew-dnp-crumbs" id="sew-dnp-crumbs"></span>
                            <button type="button" class="button button-small" id="sew-dnp-browser-close" aria-label="Close">&times;</button>
                        </div>
                        <ul class="sew-dnp-browser-list" id="sew-dnp-browser-list"><li class="sew-dnp-muted">Loading&hellip;</li></ul>
                        <div class="sew-dnp-browser-foot">
                            <button type="button" class="button button-primary" id="sew-dnp-browser-use">Use this folder</button>
                        </div>
                    </div>
                </div>
                <div class="sew-dnp-field">
                    <label for="sew-dnp-from">From</label>
                    <input type="date" id="sew-dnp-from">
                </div>
                <div class="sew-dnp-field">
                    <label for="sew-dnp-to">To</label>
                    <input type="date" id="sew-dnp-to">
                </div>
                <div class="sew-dnp-field">
                    <label for="sew-dnp-columns">Columns</label>
                    <select id="sew-dnp-columns">
                        <?php for ($c = 2; $c <= 10; $c++) : ?>
                            <option value="<?php echo $c; ?>" <?php selected($c, 5); ?>><?php echo $c; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="sew-dnp-field sew-dnp-field-actions">
                    <button type="button" class="button button-primary" id="sew-dnp-load" disabled>Load pictures</button>
                    <span class="sew-dnp-hint" id="sew-dnp-load-hint">Choose a folder and both dates.</span>
                </div>
            </div>

            <div class="sew-dnp-body">
                <div class="sew-dnp-main">
                    <div class="sew-dnp-status" id="sew-dnp-status" hidden></div>
                    <div class="sew-dnp-gridbar" id="sew-dnp-gridbar" hidden>
                        <span id="sew-dnp-count"></span>
                        <span class="sew-dnp-gridbar-actions">
                            <button type="button" class="button button-small" id="sew-dnp-select-all">Select all</button>
                            <button type="button" class="button button-small" id="sew-dnp-select-none">Clear selection</button>
                            <span class="sew-dnp-muted">Click to select, Shift+click for a range.</span>
                        </span>
                    </div>
                    <div class="sew-dnp-grid" id="sew-dnp-grid" style="--sew-dnp-cols: 5"></div>
                </div>
                <aside class="sew-dnp-panel" id="sew-dnp-panel">
                    <h2>Selected <span class="sew-dnp-badge" id="sew-dnp-selected-count">0</span></h2>
                    <ol class="sew-dnp-selected" id="sew-dnp-selected"><li class="sew-dnp-muted">Nothing selected yet.</li></ol>
                    <div class="sew-dnp-panel-form">
                        <label for="sew-dnp-gallery-name">Gallery name</label>
                        <input type="text" id="sew-dnp-gallery-name" placeholder="e.g. Hot 8 Brass Band live - Conrad Sohm" autocomplete="off">
                        <div class="sew-dnp-muted sew-dnp-slug">Folder: <code id="sew-dnp-slug"><?php echo esc_html(SEW_DNP_NGG_Importer::gallery_basedir()); ?>/&hellip;</code></div>
                        <button type="button" class="button button-primary button-hero" id="sew-dnp-create" disabled>Create NGG gallery</button>
                    </div>
                    <div class="sew-dnp-progress" id="sew-dnp-progress" hidden>
                        <div class="sew-dnp-progress-bar"><span id="sew-dnp-progress-fill"></span></div>
                        <div class="sew-dnp-progress-text" id="sew-dnp-progress-text"></div>
                        <ul class="sew-dnp-log" id="sew-dnp-log"></ul>
                        <button type="button" class="button" id="sew-dnp-cancel" hidden>Cancel</button>
                    </div>
                    <div class="sew-dnp-result" id="sew-dnp-result" hidden></div>
                </aside>
            </div>

            <div class="sew-dnp-modal" id="sew-dnp-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sew-dnp-modal-title">
                <div class="sew-dnp-modal-box">
                    <h2 id="sew-dnp-modal-title">Create this gallery?</h2>
                    <div id="sew-dnp-modal-body"></div>
                    <div class="sew-dnp-modal-actions">
                        <button type="button" class="button" id="sew-dnp-modal-cancel">Cancel</button>
                        <button type="button" class="button button-primary" id="sew-dnp-modal-ok">Yes, create gallery</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        if (!current_user_can(SEW_DNP_CAP)) {
            return;
        }
        $settings = self::settings();
        $client   = $this->client();
        $tokens   = $client->tokens();
        $diag     = $this->diagnostics();
        $key_locked    = !empty($settings['app_key_from_config']);
        $secret_locked = !empty($settings['app_secret_from_config']);
        ?>
        <div class="wrap sew-dnp-wrap sew-dnp-settings">
            <h1>Dropbox Picker &rsaquo; Settings</h1>

            <h2>1. Dropbox app</h2>
            <p>Create an app at <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">dropbox.com/developers/apps</a> (scoped access, full Dropbox).
               On its <strong>Permissions</strong> tab enable <code><?php echo esc_html(SEW_DNP_Dropbox_Client::SCOPES); ?></code> and submit.
               On its <strong>Settings</strong> tab add this redirect URI:</p>
            <p><code class="sew-dnp-copy"><?php echo esc_html(self::redirect_uri()); ?></code></p>
            <form method="post" action="options.php">
                <?php settings_fields('sew_dnp'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sew-dnp-app-key">App key</label></th>
                        <td><?php if ($key_locked) : ?>
                                <code><?php echo esc_html($settings['app_key']); ?></code> <span class="description">(from sew-dnp-config.php)</span>
                            <?php else : ?>
                                <input type="text" class="regular-text code" id="sew-dnp-app-key" name="<?php echo SEW_DNP_OPTION_SETTINGS; ?>[app_key]" value="<?php echo esc_attr(isset($settings['app_key']) ? $settings['app_key'] : ''); ?>" autocomplete="off">
                            <?php endif; ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sew-dnp-app-secret">App secret</label></th>
                        <td><?php if ($secret_locked) : ?>
                                <code>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code> <span class="description">(from sew-dnp-config.php)</span>
                            <?php else : ?>
                            <input type="password" class="regular-text code" id="sew-dnp-app-secret" name="<?php echo SEW_DNP_OPTION_SETTINGS; ?>[app_secret]" value="" autocomplete="new-password" placeholder="<?php echo !empty($settings['app_secret']) ? '(stored -- leave blank to keep)' : ''; ?>">
                            <?php if (!empty($settings['app_secret'])) : ?>
                                <label class="sew-dnp-inline"><input type="checkbox" name="<?php echo SEW_DNP_OPTION_SETTINGS; ?>[clear_secret]" value="1"> clear stored secret</label>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sew-dnp-thumb-size">Grid thumbnail size</label></th>
                        <td>
                            <select id="sew-dnp-thumb-size" name="<?php echo SEW_DNP_OPTION_SETTINGS; ?>[thumb_size]">
                                <?php foreach (array('w256h256' => '256 px (fast)', 'w480h320' => '480 px (default)', 'w640h480' => '640 px (sharp, slower)') as $value => $label) : ?>
                                    <option value="<?php echo $value; ?>" <?php selected(isset($settings['thumb_size']) ? $settings['thumb_size'] : 'w480h320', $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sew-dnp-manual-token">Access token (testing only)</label></th>
                        <td>
                            <?php if (defined('SEW_DNP_TEST_TOKEN') && SEW_DNP_TEST_TOKEN !== '') : ?>
                                <label class="sew-dnp-inline"><input type="checkbox" name="<?php echo SEW_DNP_OPTION_SETTINGS; ?>[use_test_token]" value="1" <?php checked(!empty($settings['use_test_token'])); ?>> use the test token from sew-dnp-config.php</label>
                                <p class="description">A token generated in the App Console is short-lived (about 4 hours) and <em>overrides</em> the OAuth connection while this is ticked.</p>
                            <?php else : ?>
                            <input type="password" class="regular-text code" id="sew-dnp-manual-token" name="<?php echo SEW_DNP_OPTION_SETTINGS; ?>[manual_token]" value="<?php echo esc_attr(isset($settings['manual_token']) ? $settings['manual_token'] : ''); ?>" autocomplete="off">
                            <p class="description">A token generated in the App Console. Short-lived (about 4 hours) and it <em>overrides</em> the OAuth connection while set. Leave empty for normal use.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>2. Connection</h2>
            <?php if ($client->uses_manual_token()) : ?>
                <p><span class="sew-dnp-dot sew-dnp-dot-warn"></span> Using the pasted test access token.</p>
            <?php elseif (!empty($tokens['refresh_token'])) : ?>
                <p><span class="sew-dnp-dot sew-dnp-dot-ok"></span> Connected<?php echo !empty($tokens['account_label']) ? ' as <strong>' . esc_html($tokens['account_label']) . '</strong>' : ''; ?>
                   <?php if (!empty($tokens['connected_at'])) : ?>since <?php echo esc_html(wp_date('Y-m-d H:i', (int) $tokens['connected_at'])); ?><?php endif; ?>.
                   Scopes: <code><?php echo esc_html(isset($tokens['scope']) ? $tokens['scope'] : '?'); ?></code></p>
            <?php else : ?>
                <p><span class="sew-dnp-dot sew-dnp-dot-off"></span> Not connected.</p>
            <?php endif; ?>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sew_dnp_connect'), 'sew_dnp_connect')); ?>" <?php echo $client->is_configured() ? '' : 'disabled aria-disabled="true" onclick="return false"'; ?>><?php echo !empty($tokens['refresh_token']) ? 'Reconnect Dropbox' : 'Connect Dropbox'; ?></a>
                <?php if (!empty($tokens['refresh_token'])) : ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sew_dnp_disconnect'), 'sew_dnp_disconnect')); ?>">Disconnect</a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url(self::picker_url()); ?>">Go to the picker</a>
            </p>

            <h2>3. Diagnostics</h2>
            <table class="widefat striped sew-dnp-diag">
                <tbody>
                <?php foreach ($diag as $label => $value) : ?>
                    <tr><th scope="row"><?php echo esc_html($label); ?></th><td><?php echo esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value)); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">The Dropbox connection is tested live when you open the picker. Plugin version <?php echo SEW_DNP_VERSION; ?>.</p>

            <h2>4. Import self-test (no Dropbox needed)</h2>
            <p>Copies the newest photo already in <code><?php echo esc_html($diag['Gallery directory']); ?></code> into a throw-away gallery, runs the resize (&le; <?php echo SEW_DNP_Image_Processor::MAX_DIM; ?> px, &le; <?php echo (int) (SEW_DNP_Image_Processor::MAX_BYTES / 1024); ?> KB), registers it with NextGEN, builds the thumbnail, and removes the gallery again.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sew_dnp_selftest'); ?>
                <input type="hidden" name="action" value="sew_dnp_selftest">
                <label class="sew-dnp-inline"><input type="checkbox" name="keep" value="1"> keep the test gallery (inspect it in NextGEN, delete it yourself)</label><br>
                <?php submit_button('Run self-test', 'secondary', 'submit', false); ?>
            </form>
            <?php $report = get_transient('sew_dnp_selftest_' . get_current_user_id()); if ($report) : delete_transient('sew_dnp_selftest_' . get_current_user_id()); ?>
                <pre class="sew-dnp-report"><?php echo esc_html($report); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function diagnostics() {
        $basedir = SEW_DNP_NGG_Importer::gallery_basedir();
        $abspath = rtrim(ABSPATH, '/') . '/' . $basedir;
        list($tw, $th, $crop) = SEW_DNP_NGG_Importer::thumb_settings();
        return array(
            'WordPress'             => get_bloginfo('version'),
            'PHP'                   => PHP_VERSION,
            'Image editor'          => SEW_DNP_Image_Processor::editor_class() ?: 'none available!',
            'memory_limit'          => ini_get('memory_limit') . ' (WP_MAX_MEMORY_LIMIT ' . (defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : '?') . ')',
            'max_execution_time'    => ini_get('max_execution_time'),
            'NextGEN Gallery'       => SEW_DNP_NGG_Importer::ngg_version() ?: 'NOT FOUND',
            'Gallery directory'     => $basedir . (is_dir($abspath) ? (wp_is_writable($abspath) ? ' (writable)' : ' (NOT writable)') : ' (missing)'),
            'NGG thumbnail setting' => $tw . 'x' . $th . ($crop ? ' cropped' : ' fit'),
            'Redirect URI'          => self::redirect_uri(),
            'Output limits'         => 'longest edge ' . SEW_DNP_Image_Processor::MAX_DIM . ' px, <= ' . (int) (SEW_DNP_Image_Processor::MAX_BYTES / 1024) . ' KB',
        );
    }

    // ================================================================== self-test

    /** Exercise download-free parts of the pipeline: resize, import, thumbnails, cleanup. */
    public function handle_selftest() {
        check_admin_referer('sew_dnp_selftest');
        if (!current_user_can(SEW_DNP_CAP)) {
            wp_die('forbidden', '', array('response' => 403));
        }
        @set_time_limit(300);
        $keep = !empty($_POST['keep']);
        $lines = array();
        $slug = 'sew-dnp-selftest-' . strtolower(wp_generate_password(6, false, false));
        $abspath = SEW_DNP_NGG_Importer::gallery_abspath($slug);
        $started = microtime(true);
        try {
            $basedir = rtrim(ABSPATH, '/') . '/' . SEW_DNP_NGG_Importer::gallery_basedir();
            // Test with the largest photo of the most recently changed real
            // gallery (not articles/, which holds the small header images).
            $newest = null;
            $newest_dir = null;
            $newest_time = 0;
            foreach ((array) glob($basedir . '/*', GLOB_ONLYDIR) as $dir) {
                $name = basename($dir);
                if (strpos($name, 'sew-dnp-selftest') === 0 || $name === 'articles' || $name === 'thumbs') {
                    continue;
                }
                $mtime = filemtime($dir);
                if ($mtime > $newest_time && glob($dir . '/*.[jJ][pP][gG]')) {
                    $newest_dir = $dir;
                    $newest_time = $mtime;
                }
            }
            if ($newest_dir) {
                $largest = 0;
                foreach ((array) glob($newest_dir . '/*.[jJ][pP][gG]') as $file) {
                    $size = filesize($file);
                    if ($size > $largest && strpos(basename($file), 'thumbs_') !== 0) {
                        $newest = $file;
                        $largest = $size;
                    }
                }
            }
            if (!$newest) {
                throw new RuntimeException('no existing JPEG found under ' . $basedir . ' to test with');
            }
            $lines[] = 'source: ' . str_replace(ABSPATH, '', $newest) . ' (' . size_format(filesize($newest)) . ')';
            $lines[] = 'editor: ' . SEW_DNP_Image_Processor::editor_class();
            $lines[] = 'memory headroom: ' . size_format(SEW_DNP_Image_Processor::memory_headroom());
            if (!wp_mkdir_p($abspath)) {
                throw new RuntimeException('cannot create ' . $abspath);
            }
            $lines[] = 'folder: ' . SEW_DNP_NGG_Importer::gallery_basedir() . '/' . $slug;
            $t = microtime(true);
            $meta = SEW_DNP_Image_Processor::process($newest, $abspath . '/001-selftest.jpg');
            $lines[] = sprintf('resized: %dx%d, %s, quality %d, %.1fs%s', $meta['width'], $meta['height'], size_format($meta['bytes']), $meta['quality'], microtime(true) - $t, !empty($meta['over_budget']) ? ' (STILL OVER BUDGET)' : '');
            $t = microtime(true);
            $result = SEW_DNP_NGG_Importer::import($slug, 'SEW DNP self-test');
            $lines[] = sprintf('ngg import: gallery id %d, %d image(s), %.1fs', $result['gallery_id'], $result['images'], microtime(true) - $t);
            $lines[] = 'thumbnails: ' . wp_json_encode($result['thumbnails']);
            $lines[] = 'notes: ' . implode(' | ', $result['notes']);
            $lines[] = 'manage url: ' . $result['manage_url'];
            if ($keep) {
                $lines[] = 'kept -- delete gallery ' . $result['gallery_id'] . ' in NextGEN when done';
            } else {
                $lines[] = 'cleanup: ' . SEW_DNP_NGG_Importer::delete_gallery($result['gallery_id']);
            }
            $lines[] = sprintf('OK in %.1fs', microtime(true) - $started);
        } catch (Throwable $exc) {
            $lines[] = 'FAILED: ' . get_class($exc) . ': ' . $exc->getMessage() . ' @ ' . basename($exc->getFile()) . ':' . $exc->getLine();
            if (!$keep && is_dir($abspath)) {
                $found = SEW_DNP_NGG_Importer::find_gallery($slug);
                $lines[] = 'cleanup: ' . ($found ? SEW_DNP_NGG_Importer::delete_gallery((int) $found->gid) : SEW_DNP_NGG_Importer::delete_folder($abspath));
            }
        }
        set_transient('sew_dnp_selftest_' . get_current_user_id(), implode("\n", $lines), 300);
        wp_safe_redirect(self::settings_url() . '#selftest');
        exit;
    }

    // ================================================================== ajax

    private function ajax_guard() {
        check_ajax_referer('sew_dnp_ajax', 'nonce');
        if (!current_user_can(SEW_DNP_CAP)) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }
        @set_time_limit(300);
    }

    private function ajax_fail(Exception $exc, $status = 500) {
        $code = $exc instanceof SEW_DNP_Dropbox_Exception && $exc->http_status ? $exc->http_status : $status;
        wp_send_json_error(array('message' => $exc->getMessage(), 'type' => get_class($exc)), $code >= 400 && $code < 600 ? $code : 500);
    }

    private function post($key, $default = '') {
        return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
    }

    public function ajax_status() {
        $this->ajax_guard();
        try {
            $client = $this->client();
            $account = $client->is_connected() ? $client->current_account() : null;
            wp_send_json_success(array(
                'connected' => (bool) $account,
                'account'   => $account ? (isset($account['name']['display_name']) ? $account['name']['display_name'] : '') . (isset($account['email']) ? ' (' . $account['email'] . ')' : '') : '',
                'ngg'       => SEW_DNP_NGG_Importer::ngg_version(),
            ));
        } catch (Exception $exc) {
            $this->ajax_fail($exc);
        }
    }

    /** Subfolders of a path, for the folder browser. */
    public function ajax_folders() {
        $this->ajax_guard();
        $path = trim((string) $this->post('path'));
        if ($path === '/') {
            $path = '';
        }
        try {
            $client = $this->client();
            $folders = array();
            $files = 0;
            $cursor = '';
            do {
                $page = $client->list_folder($path, false, $cursor);
                foreach ((array) $page['entries'] as $entry) {
                    if ($entry['.tag'] === 'folder') {
                        $folders[] = array('name' => $entry['name'], 'path_lower' => $entry['path_lower'], 'path_display' => $entry['path_display']);
                    } elseif ($entry['.tag'] === 'file') {
                        $files++;
                    }
                }
                $cursor = !empty($page['has_more']) ? $page['cursor'] : '';
            } while ($cursor !== '');
            usort($folders, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
            wp_send_json_success(array('path' => $path, 'folders' => $folders, 'files' => $files));
        } catch (Exception $exc) {
            $this->ajax_fail($exc);
        }
    }

    /**
     * One page of the folder scan, filtered to images inside the date range.
     * The browser loops while has_more, passing the cursor back.
     */
    public function ajax_scan() {
        $this->ajax_guard();
        $path      = trim((string) $this->post('path'));
        $cursor    = (string) $this->post('cursor');
        $recursive = $this->post('recursive') === '1';
        $from      = (string) $this->post('from');
        $to        = (string) $this->post('to');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            wp_send_json_error(array('message' => 'FROM and TO must be dates (YYYY-MM-DD).'), 400);
        }
        $tz = wp_timezone();
        $start = new DateTimeImmutable($from . ' 00:00:00', $tz);
        $end   = new DateTimeImmutable($to . ' 23:59:59', $tz);
        if ($start > $end) {
            wp_send_json_error(array('message' => 'FROM must not be after TO.'), 400);
        }
        $start_ts = $start->getTimestamp();
        $end_ts   = $end->getTimestamp();

        try {
            $client = $this->client();
            $path = $path === '/' ? '' : $path;
            // Let Dropbox filter by date: "after:" / "before:" are exclusive day
            // boundaries in Dropbox's search syntax, so widen by one day on each
            // side and apply the exact range (in the blog's timezone) below.
            $query = 'after:' . $start->modify('-1 day')->format('Y-m-d') . ' before:' . $end->modify('+1 day')->format('Y-m-d');
            $page = $client->search($query, $path, $cursor);
            $entries = array();
            foreach ((array) $page['matches'] as $match) {
                if (isset($match['metadata']['metadata'])) {
                    $entries[] = $match['metadata']['metadata'];
                }
            }
            $matches = array();
            $scanned = 0;
            $skipped = array();
            $folder_lower = strtolower(rtrim($path, '/'));
            foreach ($entries as $entry) {
                if ($entry['.tag'] !== 'file') {
                    continue;
                }
                // Search is always recursive; honour the checkbox ourselves.
                if (!$recursive && strtolower(dirname($entry['path_lower'])) !== ($folder_lower === '' ? '/' : $folder_lower)) {
                    continue;
                }
                $scanned++;
                if (!SEW_DNP_Dropbox_Client::is_image_name($entry['name'])) {
                    $ext = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) ?: '(none)';
                    $skipped[$ext] = isset($skipped[$ext]) ? $skipped[$ext] + 1 : 1;
                    continue;
                }
                $taken_raw = $entry['client_modified'];
                $taken_source = 'modified';
                if (isset($entry['media_info']['metadata']['time_taken'])) {
                    $taken_raw = $entry['media_info']['metadata']['time_taken'];
                    $taken_source = 'exif';
                }
                $taken_ts = strtotime($taken_raw);
                if ($taken_ts === false || $taken_ts < $start_ts || $taken_ts > $end_ts) {
                    continue;
                }
                $dims = isset($entry['media_info']['metadata']['dimensions']) ? $entry['media_info']['metadata']['dimensions'] : null;
                $matches[] = array(
                    'id'           => $entry['id'],
                    'name'         => $entry['name'],
                    'path_lower'   => $entry['path_lower'],
                    'path_display' => $entry['path_display'],
                    'size'         => (int) $entry['size'],
                    'taken'        => wp_date('Y-m-d H:i:s', $taken_ts, $tz),
                    'taken_ts'     => $taken_ts,
                    'taken_source' => $taken_source,
                    'width'        => $dims ? (int) $dims['width'] : 0,
                    'height'       => $dims ? (int) $dims['height'] : 0,
                );
            }
            wp_send_json_success(array(
                'entries'  => $matches,
                'scanned'  => $scanned,
                'skipped'  => $skipped,
                'query'    => $query,
                'cursor'   => !empty($page['has_more']) && !empty($page['cursor']) ? $page['cursor'] : '',
                'has_more' => !empty($page['has_more']) && !empty($page['cursor']),
            ));
        } catch (Exception $exc) {
            $this->ajax_fail($exc);
        }
    }

    public function ajax_thumbs() {
        $this->ajax_guard();
        $paths = $this->post('paths', array());
        if (!is_array($paths) || !$paths) {
            wp_send_json_error(array('message' => 'no paths'), 400);
        }
        $paths = array_slice(array_map('strval', $paths), 0, SEW_DNP_Dropbox_Client::THUMB_BATCH_MAX);
        $settings = self::settings();
        $size = !empty($settings['thumb_size']) ? $settings['thumb_size'] : 'w480h320';
        try {
            wp_send_json_success(array('thumbs' => $this->client()->get_thumbnail_batch($paths, $size, 'bestfit')));
        } catch (Exception $exc) {
            $this->ajax_fail($exc);
        }
    }

    /** Step 1: create the (empty) gallery folder. */
    public function ajax_gallery_create() {
        $this->ajax_guard();
        $name = trim((string) $this->post('name'));
        if ($name === '') {
            wp_send_json_error(array('message' => 'Please enter a gallery name.'), 400);
        }
        $slug = SEW_DNP_NGG_Importer::slugify($name);
        if ($slug === '') {
            wp_send_json_error(array('message' => 'The gallery name yields an empty folder name; use some letters or digits.'), 400);
        }
        try {
            $slug = SEW_DNP_NGG_Importer::clean_folder($slug);
            if (!SEW_DNP_NGG_Importer::is_available()) {
                throw new SEW_DNP_NGG_Exception('NextGEN Gallery is not active.');
            }
            $abspath = SEW_DNP_NGG_Importer::gallery_abspath($slug);
            if (SEW_DNP_NGG_Importer::find_gallery($slug)) {
                throw new SEW_DNP_NGG_Exception('A NextGEN gallery with the folder "' . $slug . '" already exists. Choose another name.');
            }
            if (is_dir($abspath) && SEW_DNP_NGG_Importer::disk_files($abspath)) {
                throw new SEW_DNP_NGG_Exception('The folder ' . SEW_DNP_NGG_Importer::gallery_basedir() . '/' . $slug . ' already contains images. Choose another name.');
            }
            if (!is_dir($abspath) && !wp_mkdir_p($abspath)) {
                throw new SEW_DNP_NGG_Exception('Cannot create ' . $abspath);
            }
            if (!wp_is_writable($abspath)) {
                throw new SEW_DNP_NGG_Exception($abspath . ' is not writable.');
            }
            wp_send_json_success(array(
                'slug'   => $slug,
                'path'   => SEW_DNP_NGG_Importer::gallery_basedir() . '/' . $slug,
                'editor' => SEW_DNP_Image_Processor::editor_class(),
            ));
        } catch (Exception $exc) {
            $this->ajax_fail($exc, 400);
        }
    }

    /** Step 2 (per image): download from Dropbox, downscale, store in the gallery folder. */
    public function ajax_gallery_add() {
        $this->ajax_guard();
        $slug   = (string) $this->post('slug');
        $path   = (string) $this->post('path');
        $name   = (string) $this->post('name');
        $index  = (int) $this->post('index', 0);
        $width  = (int) $this->post('width', 0);
        $height = (int) $this->post('height', 0);
        $tmp = null;
        try {
            $slug = SEW_DNP_NGG_Importer::clean_folder($slug);
            $abspath = SEW_DNP_NGG_Importer::gallery_abspath($slug);
            if (!is_dir($abspath)) {
                throw new SEW_DNP_NGG_Exception('gallery folder missing: ' . $slug);
            }
            if ($path === '' || $path[0] !== '/') {
                throw new SEW_DNP_NGG_Exception('invalid Dropbox path');
            }
            $stem = sanitize_file_name(pathinfo($name !== '' ? $name : basename($path), PATHINFO_FILENAME));
            $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem) ?: 'image';
            $filename = sprintf('%03d-%s.jpg', max(0, $index), $stem);
            $dest = $abspath . '/' . $filename;

            $client = $this->client();
            if (!function_exists('wp_tempnam')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp = wp_tempnam('sew-dnp-');
            $source = 'original';
            $fits = SEW_DNP_Image_Processor::fits_in_memory($width, $height);
            if (!$fits) {
                // Too many pixels for GD on this host: let Dropbox downscale first.
                $client->download_thumbnail($path, $tmp, 'w2048h1536');
                $source = 'dropbox-2048';
            } else {
                $client->download($path, $tmp);
                if (!$width || !$height) {
                    $probe = @getimagesize($tmp);
                    if ($probe && !SEW_DNP_Image_Processor::fits_in_memory($probe[0], $probe[1])) {
                        @unlink($tmp);
                        $client->download_thumbnail($path, $tmp, 'w2048h1536');
                        $source = 'dropbox-2048';
                    }
                }
            }
            $meta = SEW_DNP_Image_Processor::process($tmp, $dest);
            @unlink($tmp);
            $meta['file'] = $filename;
            $meta['source'] = $source;
            wp_send_json_success($meta);
        } catch (Exception $exc) {
            if ($tmp) {
                @unlink($tmp);
            }
            $this->ajax_fail($exc);
        }
    }

    /** Step 3: register the folder with NextGEN and build its thumbnails. */
    public function ajax_gallery_finish() {
        $this->ajax_guard();
        $slug  = (string) $this->post('slug');
        $title = trim((string) $this->post('title'));
        try {
            wp_send_json_success(SEW_DNP_NGG_Importer::import($slug, $title));
        } catch (Exception $exc) {
            $this->ajax_fail($exc);
        }
    }
}

SEW_Dropbox_NGG_Picker::instance();
