<?php
/**
 * Register a folder of JPEGs as a NextGEN Gallery and build its thumbnails.
 *
 * Ported from music_blog/server/sew-claude-music/ngg-helper.php, which is the
 * proven path on this host (NextGEN 3.35): files go into
 * wp-content/gallery/<folder>/, nggAdmin::import_gallery() writes the
 * ngg_gallery / ngg_pictures rows, and C_Gallery_Storage::generate_thumbnail()
 * creates thumbs/thumbs_<file>. NextGEN only loads its storage module in an
 * admin request; admin-ajax.php defines WP_ADMIN, so calling this from an AJAX
 * handler satisfies that.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEW_DNP_NGG_Exception extends RuntimeException {}

class SEW_DNP_NGG_Importer {

    /** NextGEN's configured gallery directory, relative to the WordPress root. */
    public static function gallery_basedir() {
        if (class_exists('C_NextGen_Settings')) {
            $settings = C_NextGen_Settings::get_instance();
            if (!empty($settings->gallerypath)) {
                return trim(str_replace('\\', '/', $settings->gallerypath), '/');
            }
        }
        return 'wp-content/gallery';
    }

    public static function gallery_abspath($folder) {
        return rtrim(ABSPATH, '/') . '/' . self::gallery_basedir() . '/' . $folder;
    }

    public static function ngg_version() {
        if (defined('NGG_PLUGIN_VERSION')) {
            return NGG_PLUGIN_VERSION;
        }
        return class_exists('C_NextGEN_Bootstrap') ? 'unknown (bootstrap present)' : null;
    }

    public static function is_available() {
        return class_exists('C_Gallery_Mapper') || class_exists('C_NextGEN_Bootstrap') || class_exists('nggAdmin');
    }

    /** The legacy nggAdmin class is only auto-loaded in some admin screens. */
    public static function load_ngg_admin() {
        if (class_exists('nggAdmin')) {
            return true;
        }
        $candidates = array(
            WP_PLUGIN_DIR . '/nextgen-gallery/products/photocrati_nextgen/modules/ngglegacy/admin/functions.php',
        );
        foreach ((array) glob(WP_PLUGIN_DIR . '/*/products/photocrati_nextgen/modules/ngglegacy/admin/functions.php') as $match) {
            $candidates[] = $match;
        }
        foreach (array_unique($candidates) as $path) {
            if (file_exists($path)) {
                foreach (array('file.php', 'image.php', 'media.php') as $include) {
                    require_once ABSPATH . 'wp-admin/includes/' . $include;
                }
                require_once $path;
                if (class_exists('nggAdmin')) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Folder names: no traversal, no surprises. Same rule as the helper. */
    public static function clean_folder($raw) {
        $folder = basename(trim((string) $raw, "/ \t\n\r"));
        if ($folder === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $folder)) {
            throw new SEW_DNP_NGG_Exception('invalid gallery folder name: ' . $raw);
        }
        return $folder;
    }

    /** German-aware slug, matching music_blog's slugify(): "München" -> "muenchen". */
    public static function slugify($text) {
        $map = array(
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'ß' => 'ss', 'æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'đ' => 'd', 'ł' => 'l', '&' => ' und ',
        );
        $text = strtr((string) $text, $map);
        $text = remove_accents($text);
        $text = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $text));
        return trim(preg_replace('/-{2,}/', '-', $text), '-');
    }

    public static function find_gallery($folder) {
        if (!class_exists('C_Gallery_Mapper')) {
            return null;
        }
        $mapper = C_Gallery_Mapper::get_instance();
        foreach ((array) $mapper->find_all() as $gallery) {
            $name = isset($gallery->name) ? $gallery->name : '';
            $path = isset($gallery->path) ? rtrim(str_replace('\\', '/', $gallery->path), '/') : '';
            if ($name === $folder || substr($path, -strlen('/' . $folder)) === '/' . $folder) {
                return $gallery;
            }
        }
        return null;
    }

    public static function count_images($gallery_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ngg_pictures WHERE galleryid = %d",
            $gallery_id
        ));
    }

    public static function disk_files($abspath) {
        $files = array();
        foreach ((array) glob($abspath . '/*') as $path) {
            if (is_file($path) && preg_match('/\.(jpe?g|png|gif)$/i', $path)) {
                $files[] = basename($path);
            }
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    /** Admin URL of NextGEN's "Manage gallery" screen for a gallery id. */
    public static function manage_url($gallery_id) {
        return admin_url('admin.php?page=nggallery-manage-gallery&mode=edit&gid=' . (int) $gallery_id);
    }

    public static function thumb_settings() {
        $width = 120;
        $height = 90;
        $crop = true;
        if (class_exists('C_NextGen_Settings')) {
            $settings = C_NextGen_Settings::get_instance();
            if (!empty($settings->thumbwidth)) {
                $width = (int) $settings->thumbwidth;
            }
            if (!empty($settings->thumbheight)) {
                $height = (int) $settings->thumbheight;
            }
            if (isset($settings->thumbfix)) {
                $crop = (bool) $settings->thumbfix;
            }
        }
        return array($width, $height, $crop);
    }

    /** Write thumbs/thumbs_<file> directly, bypassing NextGEN's storage module. */
    private static function thumb_fallback($abspath, $filename) {
        list($width, $height, $crop) = self::thumb_settings();
        $source = $abspath . '/' . $filename;
        if (!is_file($source)) {
            return 'source missing';
        }
        $dir = $abspath . '/thumbs';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return 'cannot create thumbs/';
        }
        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) {
            return 'editor: ' . $editor->get_error_message();
        }
        $resized = $editor->resize($width, $height, $crop);
        if (is_wp_error($resized)) {
            return 'resize: ' . $resized->get_error_message();
        }
        $saved = $editor->save($dir . '/thumbs_' . $filename);
        if (is_wp_error($saved)) {
            return 'save: ' . $saved->get_error_message();
        }
        return true;
    }

    public static function generate_thumbnails($gallery_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pid, filename FROM {$wpdb->prefix}ngg_pictures WHERE galleryid = %d ORDER BY pid",
            $gallery_id
        ));
        $gallery = $wpdb->get_row($wpdb->prepare(
            "SELECT path FROM {$wpdb->prefix}ngg_gallery WHERE gid = %d", $gallery_id
        ));
        $abspath = rtrim(ABSPATH, '/') . '/' . trim($gallery ? $gallery->path : '', '/');

        list($tw, $th, $crop) = self::thumb_settings();
        $result = array(
            'total' => count($rows), 'generated' => 0, 'via_storage' => 0,
            'via_fallback' => 0, 'failed' => array(),
            'size' => $tw . 'x' . $th . ($crop ? ' (cropped)' : ' (fit)'),
        );

        $storage = class_exists('C_Gallery_Storage') ? C_Gallery_Storage::get_instance() : null;
        foreach ($rows as $row) {
            $pid = (int) $row->pid;
            $ok = false;
            $why = '';
            if ($storage) {
                ob_start();
                try {
                    $ok = (bool) $storage->generate_thumbnail($pid);
                } catch (Throwable $exc) {
                    $ok = false;
                    $why = $exc->getMessage();
                }
                ob_end_clean();
                if ($ok) {
                    $result['generated']++;
                    $result['via_storage']++;
                    continue;
                }
            }
            $fallback = self::thumb_fallback($abspath, $row->filename);
            if ($fallback === true) {
                $result['generated']++;
                $result['via_fallback']++;
            } else {
                $result['failed'][] = $pid . ': ' . $fallback . ($why ? ' (storage: ' . $why . ')' : '');
            }
        }
        return $result;
    }

    /** Remove a gallery directory (files, thumbs/, and the dir itself). Only used for self-test galleries. */
    public static function delete_folder($abspath) {
        $abspath = rtrim($abspath, '/');
        $base = rtrim(ABSPATH, '/') . '/' . self::gallery_basedir() . '/';
        if (strpos($abspath, $base) !== 0 || strpos(basename($abspath), 'sew-dnp-selftest') !== 0) {
            return 'refused to delete ' . $abspath;
        }
        foreach (array($abspath . '/thumbs', $abspath . '/dynamic', $abspath) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir . '/{,.}*', GLOB_BRACE) as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
        return is_dir($abspath) ? 'folder NOT fully removed: ' . $abspath : 'folder removed';
    }

    /** Delete a self-test gallery: NextGEN rows first (pictures, then gallery), then the files. */
    public static function delete_gallery($gallery_id) {
        global $wpdb;
        $gallery_id = (int) $gallery_id;
        $row = $wpdb->get_row($wpdb->prepare("SELECT name, path FROM {$wpdb->prefix}ngg_gallery WHERE gid = %d", $gallery_id));
        if (!$row) {
            return 'gallery ' . $gallery_id . ' not found';
        }
        if (strpos((string) $row->name, 'sew-dnp-selftest') !== 0) {
            return 'refused: not a self-test gallery';
        }
        $abspath = rtrim(ABSPATH, '/') . '/' . trim($row->path, '/');
        $notes = array();
        $done = false;
        if (class_exists('C_Gallery_Mapper')) {
            try {
                ob_start();
                $mapper = C_Gallery_Mapper::get_instance();
                $entity = $mapper->find($gallery_id);
                if ($entity) {
                    $mapper->destroy($entity, true); // true = also remove files
                    $done = true;
                    $notes[] = 'mapper destroy ok';
                }
                ob_end_clean();
            } catch (Throwable $exc) {
                ob_end_clean();
                $notes[] = 'mapper destroy failed: ' . $exc->getMessage();
            }
        }
        if (!$done) {
            $wpdb->delete($wpdb->prefix . 'ngg_pictures', array('galleryid' => $gallery_id), array('%d'));
            $wpdb->delete($wpdb->prefix . 'ngg_gallery', array('gid' => $gallery_id), array('%d'));
            $notes[] = 'rows deleted directly';
        }
        $notes[] = is_dir($abspath) ? self::delete_folder($abspath) : 'folder already gone';
        return implode(', ', $notes);
    }

    /**
     * Register <basedir>/<folder> as a gallery titled $title and build thumbnails.
     *
     * @return array{gallery_id:int, folder:string, path:string, images:int, thumbnails:array, notes:array, manage_url:string, shortcode:string}
     */
    public static function import($folder, $title) {
        global $wpdb;
        @set_time_limit(300);

        $folder  = self::clean_folder($folder);
        $title   = $title !== '' ? $title : $folder;
        $relpath = self::gallery_basedir() . '/' . $folder;
        $abspath = rtrim(ABSPATH, '/') . '/' . $relpath;

        if (!is_dir($abspath)) {
            throw new SEW_DNP_NGG_Exception('gallery folder not found on disk: ' . $relpath);
        }
        if (!self::load_ngg_admin()) {
            throw new SEW_DNP_NGG_Exception('NextGEN Gallery not found (nggAdmin unavailable)');
        }

        $notes = array();
        $gallery = self::find_gallery($folder);
        $gallery_id = $gallery ? (int) $gallery->gid : 0;

        if ($gallery_id) {
            $notes[] = 'reused existing gallery';
        } elseif (class_exists('C_Gallery_Mapper')) {
            // Create the record up front so the id is known even if the importer
            // returns something falsy. The mapper rejects plain arrays.
            $mapper = C_Gallery_Mapper::get_instance();
            $properties = array(
                'title'      => $title,
                'name'       => $folder,
                'path'       => $relpath,
                'author'     => get_current_user_id(),
                'previewpic' => 0,
            );
            if (method_exists($mapper, 'create')) {
                $entity = $mapper->create($properties);
                $notes[] = 'built entity via C_Gallery_Mapper::create()';
            } else {
                $entity = (object) $properties;
                $notes[] = 'built entity via stdClass cast';
            }
            $created = $mapper->save($entity);
            if (is_object($created)) {
                $gallery_id = (int) $created->gid;
            } elseif (is_numeric($created)) {
                $gallery_id = (int) $created;
            } elseif (is_object($entity) && !empty($entity->gid)) {
                $gallery_id = (int) $entity->gid;
            }
            if (!$gallery_id) {
                throw new SEW_DNP_NGG_Exception('could not create gallery record for ' . $folder);
            }
            $notes[] = 'created gallery record';
        }

        $notes[] = 'disk files: ' . count(self::disk_files($abspath));
        $notes[] = 'registered before import: ' . self::count_images($gallery_id);

        // import_gallery() has taken the bare folder name, an ABSPATH-relative
        // path and an absolute path across versions; try each until rows appear.
        $imported = false;
        foreach (array($folder, $relpath, $abspath) as $candidate) {
            $before = self::count_images($gallery_id);
            ob_start();  // import_gallery() echoes its own HTML status markup
            try {
                $result = nggAdmin::import_gallery($candidate, $gallery_id ?: null);
                $echoed = trim(preg_replace('/\s+/', ' ', strip_tags(ob_get_clean())));
            } catch (Throwable $exc) {
                ob_end_clean();
                $notes[] = 'import_gallery(' . $candidate . ') threw: ' . $exc->getMessage();
                continue;
            }
            $after = self::count_images($gallery_id);
            $notes[] = 'import_gallery(' . $candidate . ') -> ' . var_export($result, true)
                . ' | rows ' . $before . '->' . $after
                . ' | echoed: ' . ($echoed === '' ? '(nothing)' : $echoed)
                . ($wpdb->last_error ? ' | db error: ' . $wpdb->last_error : '');
            if ($after > $before) {
                $imported = true;
                if (is_numeric($result)) {
                    $gallery_id = (int) $result;
                }
                break;
            }
        }

        if (!$gallery_id) {
            $found = self::find_gallery($folder);
            $gallery_id = $found ? (int) $found->gid : 0;
        }
        if (!$gallery_id) {
            throw new SEW_DNP_NGG_Exception('import failed for ' . $relpath . ' -- ' . implode(' / ', $notes));
        }

        $count = self::count_images($gallery_id);
        if (!$imported && !$count) {
            throw new SEW_DNP_NGG_Exception('gallery ' . $gallery_id . ' has no registered images -- ' . implode(' / ', $notes));
        }

        // Make sure the title is the one the user typed (import_gallery may set its own).
        if (class_exists('C_Gallery_Mapper')) {
            try {
                $mapper = C_Gallery_Mapper::get_instance();
                $entity = $mapper->find($gallery_id);
                if ($entity && isset($entity->title) && $entity->title !== $title) {
                    $entity->title = $title;
                    $mapper->save($entity);
                    $notes[] = 'title set to "' . $title . '"';
                }
            } catch (Throwable $exc) {
                $notes[] = 'could not set title: ' . $exc->getMessage();
            }
        }

        $thumbnails = self::generate_thumbnails($gallery_id);

        return array(
            'gallery_id' => $gallery_id,
            'folder'     => $folder,
            'path'       => $relpath,
            'images'     => $count,
            'thumbnails' => $thumbnails,
            'notes'      => $notes,
            'manage_url' => self::manage_url($gallery_id),
            'shortcode'  => '[ngg src="galleries" ids="' . $gallery_id . '" display="basic_thumbnail"]',
        );
    }
}
