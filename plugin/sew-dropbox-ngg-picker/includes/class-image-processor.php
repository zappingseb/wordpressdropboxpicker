<?php
/**
 * Downscale a downloaded photo for the gallery: longest edge <= 2048 px and
 * file size <= 500 KB, as a progressive-friendly JPEG without EXIF (so no GPS
 * coordinates leak onto the blog -- same policy as the music_blog pipeline).
 *
 * Uses WordPress's own image editor abstraction (Imagick when available, GD
 * otherwise), so it behaves like the media library on this host.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEW_DNP_Image_Exception extends RuntimeException {}

class SEW_DNP_Image_Processor {

    const MAX_DIM   = 2048;
    const MAX_BYTES = 500 * 1024;

    /** Quality ladder tried at each size before shrinking the dimensions. */
    const QUALITIES = array(85, 80, 75, 70, 65, 60, 55, 50, 45, 40);

    /** Which editor WordPress will pick for JPEGs: 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' or false. */
    public static function editor_class() {
        if (!function_exists('_wp_image_editor_choose')) {
            require_once ABSPATH . WPINC . '/media.php';
        }
        return _wp_image_editor_choose(array('mime_type' => 'image/jpeg', 'methods' => array('resize', 'save')));
    }

    /** Bytes PHP may still allocate after raising the limit for image work. */
    public static function memory_headroom() {
        wp_raise_memory_limit('image');
        $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return PHP_INT_MAX; // unlimited
        }
        return max(0, $limit - memory_get_usage(true));
    }

    /**
     * Can an image of this size be decoded here? GD needs the whole bitmap in
     * RAM (roughly 5 bytes per pixel with the resize target), Imagick can
     * spill to disk, so only GD is checked.
     */
    public static function fits_in_memory($width, $height) {
        $width  = (int) $width;
        $height = (int) $height;
        if ($width <= 0 || $height <= 0) {
            return true; // unknown; try and let the fallback handle failure
        }
        if (self::editor_class() === 'WP_Image_Editor_Imagick') {
            return true;
        }
        $needed = $width * $height * 5 + 8 * 1024 * 1024;
        return $needed < self::memory_headroom() * 0.9;
    }

    /**
     * Resize + compress $source into $dest (JPEG).
     *
     * @return array{width:int,height:int,bytes:int,quality:int,editor:string,shrink_steps:int}
     */
    public static function process($source, $dest) {
        wp_raise_memory_limit('image');
        @set_time_limit(300);

        $scale = 1.0;
        $steps = 0;
        $last  = null;
        // Shrink in 15 % steps until the byte budget is met; four steps takes a
        // 2048 px edge down to ~1070 px, which is where we stop trying.
        while ($steps <= 4) {
            // The temp file has no telling extension, so sniff the real type;
            // without a mime_type WordPress finds no editor for it.
            $mime = wp_get_image_mime($source);
            if (!$mime) {
                throw new SEW_DNP_Image_Exception('The downloaded file is not an image WordPress recognises.');
            }
            $editor = wp_get_image_editor($source, array('mime_type' => $mime));
            if (is_wp_error($editor)) {
                throw new SEW_DNP_Image_Exception('WordPress cannot open the image (' . $mime . '): ' . $editor->get_error_message());
            }
            if (method_exists($editor, 'maybe_exif_rotate')) {
                $editor->maybe_exif_rotate();
            }
            $size = $editor->get_size();
            $w = (int) $size['width'];
            $h = (int) $size['height'];
            $longest = max($w, $h);
            $target_scale = min(1.0, self::MAX_DIM / max(1, $longest)) * $scale;
            if ($target_scale >= 1.0 && $editor instanceof WP_Image_Editor_Imagick && $longest > 4) {
                // Imagick only strips EXIF (GPS!) while resizing; GD always does.
                // A two-pixel shrink costs nothing visible and drops the metadata
                // (one pixel is not enough: image_resize_dimensions() treats a
                // result within 1 px of the original as "no resize" and fails).
                $target_scale = ($longest - 2) / max(1, $longest);
            }
            if ($target_scale < 1.0) {
                $tw = max(1, (int) round($w * $target_scale));
                $th = max(1, (int) round($h * $target_scale));
                $resized = $editor->resize($tw, $th, false);
                if (is_wp_error($resized)) {
                    throw new SEW_DNP_Image_Exception('Resize failed: ' . $resized->get_error_message());
                }
            }
            $qualities = $steps === 0 ? self::QUALITIES : array_values(array_filter(self::QUALITIES, function ($q) { return $q <= 75; }));
            foreach ($qualities as $quality) {
                $editor->set_quality($quality);
                if (file_exists($dest)) {
                    @unlink($dest);
                }
                $saved = $editor->save($dest, 'image/jpeg');
                if (is_wp_error($saved)) {
                    throw new SEW_DNP_Image_Exception('Save failed: ' . $saved->get_error_message());
                }
                // Editors may append a suffix or change the extension; normalise.
                if (!empty($saved['path']) && $saved['path'] !== $dest) {
                    @rename($saved['path'], $dest);
                }
                clearstatcache(true, $dest);
                $bytes = (int) filesize($dest);
                $final = $editor->get_size();
                $last = array(
                    'width'        => (int) $final['width'],
                    'height'       => (int) $final['height'],
                    'bytes'        => $bytes,
                    'quality'      => $quality,
                    'editor'       => get_class($editor),
                    'shrink_steps' => $steps,
                );
                if ($bytes <= self::MAX_BYTES) {
                    unset($editor);
                    return $last;
                }
            }
            unset($editor);
            $scale *= 0.85;
            $steps++;
        }
        // Still over budget after shrinking; keep the smallest result rather than fail.
        $last['over_budget'] = true;
        return $last;
    }
}
