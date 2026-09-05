<?php
/**
 * Minimal Dropbox API v2 client on top of the WordPress HTTP API.
 *
 * Covers exactly what the picker needs: OAuth2 code flow with refresh tokens,
 * folder listing (with media info so photos carry their EXIF "time taken"),
 * thumbnail batches for the grid, and file download for the import.
 *
 * API reference: https://www.dropbox.com/developers/documentation/http/documentation
 * OAuth guide:   https://developers.dropbox.com/oauth-guide
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEW_DNP_Dropbox_Exception extends RuntimeException {
    /** @var int */
    public $http_status = 0;
    /** @var string */
    public $error_tag = '';
}

class SEW_DNP_Dropbox_Client {

    const API_BASE     = 'https://api.dropboxapi.com/2/';
    const CONTENT_BASE = 'https://content.dropboxapi.com/2/';
    const TOKEN_URL    = 'https://api.dropboxapi.com/oauth2/token';
    const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';

    /** Scopes the app must have enabled in the App Console -> Permissions tab. */
    const SCOPES = 'account_info.read files.metadata.read files.content.read';

    /** get_thumbnail_batch accepts at most 25 entries per call. */
    const THUMB_BATCH_MAX = 25;

    /** Extensions Dropbox can render thumbnails for (and WordPress can resize). */
    const IMAGE_EXTENSIONS = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp');

    /** @var array{app_key?:string, app_secret?:string, manual_token?:string} */
    private $settings;
    /** @var array{access_token?:string, refresh_token?:string, expires_at?:int, account_id?:string, scope?:string} */
    private $tokens;

    public function __construct(array $settings, array $tokens) {
        $this->settings = $settings;
        $this->tokens   = $tokens;
    }

    public static function from_options() {
        return new self(
            (array) get_option(SEW_DNP_OPTION_SETTINGS, array()),
            (array) get_option(SEW_DNP_OPTION_TOKENS, array())
        );
    }

    // ------------------------------------------------------------------ state

    public function is_configured() {
        return !empty($this->settings['app_key']) && !empty($this->settings['app_secret']);
    }

    /** True when a usable credential exists: a refresh token or a pasted test token. */
    public function is_connected() {
        return !empty($this->tokens['refresh_token']) || !empty($this->settings['manual_token']);
    }

    public function uses_manual_token() {
        return !empty($this->settings['manual_token']);
    }

    public function tokens() {
        return $this->tokens;
    }

    // ------------------------------------------------------------------ oauth

    public function authorize_url($redirect_uri, $state) {
        return self::AUTHORIZE_URL . '?' . http_build_query(array(
            'client_id'         => $this->settings['app_key'],
            'response_type'     => 'code',
            'redirect_uri'      => $redirect_uri,
            'state'             => $state,
            'token_access_type' => 'offline',   // ask for a refresh token
            'scope'             => self::SCOPES,
            'force_reapprove'   => 'false',
        ), '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchange the authorization code; stores and returns the token set. */
    public function exchange_code($code, $redirect_uri) {
        $body = $this->token_request(array(
            'code'         => $code,
            'grant_type'   => 'authorization_code',
            'redirect_uri' => $redirect_uri,
        ));
        $this->store_tokens($body, true);
        return $this->tokens;
    }

    private function refresh_access_token() {
        if (empty($this->tokens['refresh_token'])) {
            throw $this->error('Dropbox is not connected (no refresh token stored). Connect it on the settings page.');
        }
        $body = $this->token_request(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->tokens['refresh_token'],
        ));
        $this->store_tokens($body, false);
    }

    private function token_request(array $fields) {
        if (!$this->is_configured()) {
            throw $this->error('Dropbox app key / secret are not configured.');
        }
        $fields['client_id']     = $this->settings['app_key'];
        $fields['client_secret'] = $this->settings['app_secret'];
        $response = wp_remote_post(self::TOKEN_URL, array(
            'timeout' => 30,
            'body'    => $fields,
        ));
        if (is_wp_error($response)) {
            throw $this->error('Token request failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($body) || empty($body['access_token'])) {
            $detail = is_array($body) ? (isset($body['error_description']) ? $body['error_description'] : json_encode($body)) : wp_remote_retrieve_body($response);
            throw $this->error('Dropbox token endpoint returned HTTP ' . $status . ': ' . $detail, $status);
        }
        return $body;
    }

    private function store_tokens(array $body, $initial) {
        $tokens = $this->tokens;
        $tokens['access_token'] = $body['access_token'];
        $tokens['expires_at']   = time() + (isset($body['expires_in']) ? (int) $body['expires_in'] : 14400);
        if (!empty($body['refresh_token'])) {
            $tokens['refresh_token'] = $body['refresh_token'];
        }
        if (!empty($body['scope'])) {
            $tokens['scope'] = $body['scope'];
        }
        if (!empty($body['account_id'])) {
            $tokens['account_id'] = $body['account_id'];
        }
        if ($initial) {
            $tokens['connected_at'] = time();
        }
        $this->tokens = $tokens;
        update_option(SEW_DNP_OPTION_TOKENS, $tokens, false);
    }

    /** A valid bearer token, refreshing when it expires within the next minute. */
    private function access_token() {
        if (!empty($this->settings['manual_token'])) {
            return trim($this->settings['manual_token']);
        }
        if (empty($this->tokens['access_token']) || empty($this->tokens['expires_at'])
            || (int) $this->tokens['expires_at'] - 60 < time()) {
            $this->refresh_access_token();
        }
        return $this->tokens['access_token'];
    }

    // ------------------------------------------------------------------ transport

    /**
     * RPC-style call (JSON in, JSON out) against api.dropboxapi.com.
     *
     * @return array decoded response
     */
    public function rpc($endpoint, $args = null, $retry = true) {
        $response = wp_remote_post(self::API_BASE . $endpoint, array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token(),
                'Content-Type'  => 'application/json',
            ),
            'body'    => $args === null ? 'null' : wp_json_encode($args),
        ));
        if (is_wp_error($response)) {
            throw $this->error('Dropbox request ' . $endpoint . ' failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = wp_remote_retrieve_body($response);
        if ($status === 200) {
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                throw $this->error('Dropbox returned unreadable JSON for ' . $endpoint);
            }
            return $body;
        }
        if ($retry && $this->should_retry($status, $raw, $response)) {
            return $this->rpc($endpoint, $args, false);
        }
        throw $this->api_error($endpoint, $status, $raw);
    }

    /**
     * Content-style download (argument in the Dropbox-API-Arg header, bytes out)
     * streamed straight to a file so large originals never sit in PHP memory.
     *
     * @return array file metadata from the Dropbox-API-Result header
     */
    public function content_download($endpoint, array $arg, $to_file, $retry = true) {
        $response = wp_remote_post(self::CONTENT_BASE . $endpoint, array(
            'timeout'  => 180,
            'stream'   => true,
            'filename' => $to_file,
            'headers'  => array(
                'Authorization'   => 'Bearer ' . $this->access_token(),
                'Dropbox-API-Arg' => wp_json_encode($arg),
                'Content-Type'    => '',
            ),
        ));
        if (is_wp_error($response)) {
            throw $this->error('Dropbox download ' . $endpoint . ' failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 200) {
            $meta = json_decode((string) wp_remote_retrieve_header($response, 'dropbox-api-result'), true);
            return is_array($meta) ? $meta : array();
        }
        // On failure the error body was streamed into the target file.
        $raw = is_readable($to_file) ? (string) file_get_contents($to_file) : '';
        @unlink($to_file);
        if ($retry && $this->should_retry($status, $raw, $response)) {
            return $this->content_download($endpoint, $arg, $to_file, false);
        }
        throw $this->api_error($endpoint, $status, $raw);
    }

    private function should_retry($status, $raw, $response) {
        if ($status === 401 && empty($this->settings['manual_token']) && !empty($this->tokens['refresh_token'])) {
            $this->refresh_access_token();
            return true;
        }
        if ($status === 429 || $status === 503) {
            $wait = (int) wp_remote_retrieve_header($response, 'retry-after');
            $body = json_decode($raw, true);
            if (!$wait && isset($body['error']['retry_after'])) {
                $wait = (int) $body['error']['retry_after'];
            }
            sleep(max(1, min(10, $wait ?: 2)));
            return true;
        }
        return false;
    }

    private function api_error($endpoint, $status, $raw) {
        $body = json_decode($raw, true);
        $summary = '';
        $tag = '';
        if (is_array($body)) {
            $summary = isset($body['error_summary']) ? $body['error_summary'] : (isset($body['error_description']) ? $body['error_description'] : '');
            if (isset($body['error']['.tag'])) {
                $tag = $body['error']['.tag'];
            }
        }
        if ($summary === '') {
            $summary = trim(wp_strip_all_tags($raw));
        }
        $hint = '';
        if ($status === 401) {
            $hint = ' The access token is invalid or expired -- reconnect Dropbox on the settings page.';
        } elseif (strpos($summary, 'required scope') !== false || strpos($raw, 'required scope') !== false) {
            $hint = ' Enable the scopes "' . self::SCOPES . '" in the Dropbox App Console (Permissions tab), then reconnect.';
        }
        $exc = $this->error('Dropbox ' . $endpoint . ' -> HTTP ' . $status . ': ' . mb_substr($summary, 0, 400) . $hint, $status);
        $exc->error_tag = $tag;
        return $exc;
    }

    private function error($message, $status = 0) {
        $exc = new SEW_DNP_Dropbox_Exception($message);
        $exc->http_status = (int) $status;
        return $exc;
    }

    // ------------------------------------------------------------------ endpoints

    public function current_account() {
        return $this->rpc('users/get_current_account');
    }

    /**
     * One page of a folder listing. Pass $cursor to continue.
     *
     * @return array{entries: array, cursor: string, has_more: bool}
     */
    public function list_folder($path, $recursive = false, $cursor = '') {
        if ($cursor !== '') {
            return $this->rpc('files/list_folder/continue', array('cursor' => $cursor));
        }
        return $this->rpc('files/list_folder', array(
            'path'                             => $path === '/' ? '' : $path,
            'recursive'                        => (bool) $recursive,
            'include_media_info'               => true,
            'include_deleted'                  => false,
            'include_has_explicit_shared_members' => false,
            'include_mounted_folders'          => true,
            'include_non_downloadable_files'   => false,
            'limit'                            => 2000,
        ));
    }

    /**
     * One page of a date-filtered search. Dropbox's search understands the same
     * operators as the web UI, so "after:2026-08-15 before:2026-08-19" lets the
     * server do the date filtering instead of us listing the whole folder.
     * Search is always recursive below $path and returns up to 1000 matches per
     * page; continue with $cursor.
     *
     * @return array{matches: array, cursor?: string, has_more: bool}
     */
    public function search($query, $path, $cursor = '', $max_results = 1000) {
        if ($cursor !== '') {
            return $this->rpc('files/search/continue_v2', array('cursor' => $cursor));
        }
        $options = array(
            'max_results'     => (int) $max_results,
            'file_status'     => 'active',
            'filename_only'   => false,
            'file_categories' => array(array('.tag' => 'image')),
            'order_by'        => array('.tag' => 'last_modified_time'),
        );
        if ($path !== '' && $path !== '/') {
            $options['path'] = $path;
        }
        return $this->rpc('files/search_v2', array(
            'query'              => $query,
            'options'            => $options,
            'include_highlights' => false,
        ));
    }

    /**
     * Thumbnails for up to 25 paths.
     *
     * @return array path_lower => data URI (missing when Dropbox could not render one)
     */
    public function get_thumbnail_batch(array $paths, $size = 'w480h320', $mode = 'bestfit') {
        $entries = array();
        foreach (array_slice(array_values($paths), 0, self::THUMB_BATCH_MAX) as $path) {
            $entries[] = array(
                'path'   => $path,
                'format' => array('.tag' => 'jpeg'),
                'size'   => array('.tag' => $size),
                'mode'   => array('.tag' => $mode),
            );
        }
        if (!$entries) {
            return array();
        }
        // get_thumbnail_batch is an RPC-style route but lives on the content host.
        $response = wp_remote_post(self::CONTENT_BASE . 'files/get_thumbnail_batch', array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token(),
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array('entries' => $entries)),
        ));
        if (is_wp_error($response)) {
            throw $this->error('Dropbox thumbnail batch failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = wp_remote_retrieve_body($response);
        if ($status !== 200) {
            if ($this->should_retry($status, $raw, $response)) {
                return $this->get_thumbnail_batch($paths, $size, $mode);
            }
            throw $this->api_error('files/get_thumbnail_batch', $status, $raw);
        }
        $body = json_decode($raw, true);
        $out = array();
        foreach ((array) (isset($body['entries']) ? $body['entries'] : array()) as $i => $entry) {
            $key = isset($entry['metadata']['path_lower']) ? $entry['metadata']['path_lower'] : strtolower($entries[$i]['path']);
            if (isset($entry['.tag']) && $entry['.tag'] === 'success' && !empty($entry['thumbnail'])) {
                $out[$key] = 'data:image/jpeg;base64,' . $entry['thumbnail'];
            }
        }
        return $out;
    }

    /** Download the original file to $to_file. */
    public function download($path, $to_file) {
        return $this->content_download('files/download', array('path' => $path), $to_file);
    }

    /** Download a Dropbox-rendered, already downscaled version (longest edge <= 2048). */
    public function download_thumbnail($path, $to_file, $size = 'w2048h1536') {
        return $this->content_download('files/get_thumbnail_v2', array(
            'resource' => array('.tag' => 'path', 'path' => $path),
            'format'   => array('.tag' => 'jpeg'),
            'size'     => array('.tag' => $size),
            'mode'     => array('.tag' => 'bestfit'),
        ), $to_file);
    }

    public static function is_image_name($name) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }
}
