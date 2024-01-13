<?php

function fifu_sizes_cron_task($original_image_url, $att_id) {
    // Validate inputs
    if (empty($original_image_url) || !is_numeric($att_id)) {
        return;
    }

    // Get image dimensions
    $image_data = @file_get_contents($original_image_url);
    if (!$image_data) {
        return;
    }

    $size = @getimagesizefromstring($image_data);
    if (!$size) {
        return;
    }
    list($width, $height) = $size;

    // Update attachment metadata
    $metadata = wp_get_attachment_metadata($att_id);
    if (!is_array($metadata)) {
        $metadata = [];
    }
    $metadata['width'] = $width;
    $metadata['height'] = $height;
    wp_update_attachment_metadata($att_id, $metadata);
}

function fifu_sizes_schedule_task($original_image_url, $att_id) {
    wp_schedule_single_event(time(), 'fifu_sizes_cron_action', array($original_image_url, $att_id));
}

add_action('fifu_sizes_cron_action', 'fifu_sizes_cron_task', 10, 2);

function fifu_image_downsize($out, $att_id, $size) {
    if (!$att_id || !fifu_is_remote_image($att_id)) {
        return false;
    }

    if (fifu_is_off('fifu_photon')) {
        return false;
    }

    $original_image_url = get_post_meta($att_id, '_wp_attached_file', true);
    if (!$original_image_url) {
        if (strpos($original_image_url, "https://thumbnails.odycdn.com") !== 0 &&
                strpos($original_image_url, "https://res.cloudinary.com") !== 0 &&
                fifu_jetpack_blocked($original_image_url)) {
            return false;
        }
    }

    if (strpos($original_image_url, "https://drive.google.com") === 0) {
        $original_image_url = 'https://res.cloudinary.com/glide/image/fetch/' . urlencode($original_image_url);
    }

    // Remove existing query parameters from the URL
    $parsed_url = parse_url($original_image_url);
    $original_image_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];

    // Check if the requested size is "full"
    if ($size === 'full') {
        // Check if dimensions are already saved
        $metadata = wp_get_attachment_metadata($att_id);
        if (!empty($metadata['width']) && !empty($metadata['height'])) {
            $original_width = $metadata['width'];
            $original_height = $metadata['height'];
            $aspect_ratio = $original_height / $original_width;
            $max_dimension = 1920;

            if ($original_width > $original_height) {
                // Landscape or square image
                $new_width = min($original_width, $max_dimension);
                $new_height = intval($new_width * $aspect_ratio);
            } else {
                // Portrait image
                $new_height = min($original_height, $max_dimension);
                $new_width = intval($new_height / $aspect_ratio);
            }

            if (strpos($original_image_url, "https://img.youtube.com") === 0) {
                return array(fifu_resize_with_odycdn($original_image_url, $new_width, $new_height), $new_width, $new_height, false);
            } else {
                return array(fifu_resize_with_photon($original_image_url, $new_width, $new_height), $new_width, $new_height, false);
            }
        } else {
            // Save dimensions
            fifu_sizes_schedule_task($original_image_url, $att_id);

            // Use a small width to quickly get the height
            $small_width = 100;

            if (strpos($original_image_url, "https://img.youtube.com") === 0) {
                $small_resized_url = fifu_resize_with_odycdn($original_image_url, $small_width, intval($small_width * 90 / 120));
            } else {
                $small_resized_url = fifu_resize_with_photon($original_image_url, $small_width, 9999);
            }

            list(, $small_height) = getimagesize($small_resized_url);

            // Calculate width for a larger size based on the aspect ratio
            $large_width = 1920;
            $aspect_ratio = $small_height / $small_width;
            $large_height = intval($large_width * $aspect_ratio);

            if (strpos($original_image_url, "https://img.youtube.com") === 0) {
                $resized_url = fifu_resize_with_odycdn($original_image_url, $large_width, $large_height);
            } else {
                $resized_url = fifu_resize_with_photon($original_image_url, $large_width, $large_height);
            }

            return array($resized_url, $large_width, $large_height, false);
        }
    } else {
        // Logic for other sizes
        // Get all registered image sizes
        $image_sizes = get_intermediate_image_sizes();
        $additional_sizes = wp_get_additional_image_sizes();

        // Determine the size dimensions
        $width = $height = 0;
        if (is_array($size)) {
            list($width, $height) = $size;
        } elseif (in_array($size, $image_sizes)) {
            if (isset($additional_sizes[$size])) {
                $width = intval($additional_sizes[$size]['width']);
                $height = intval($additional_sizes[$size]['height']);
            } else {
                $width = get_option("{$size}_size_w");
                $height = get_option("{$size}_size_h");
            }
        } else {
            // Size not found, return false
            return false;
        }

        if (strpos($original_image_url, "https://img.youtube.com") === 0) {
            return array(fifu_resize_with_odycdn($original_image_url, $width, $height), $width, $height, false);
        } else {
            return array(fifu_resize_with_photon($original_image_url, $width, $height), $width, $height, false);
        }
    }
}

add_filter('image_downsize', 'fifu_image_downsize', 10, 3);

function fifu_resize_with_photon($url, $width, $height) {
    $photon_base_url = "https://i" . (hexdec(substr(md5($url), 0, 1)) % 4) . ".wp.com/";
    $resize_param = $height == 9999 ? "{$width}" : "{$width},{$height}";
    return $photon_base_url . preg_replace('#^https?://#', '', $url) . "?w={$width}&resize={$resize_param}";
}

function fifu_resize_with_odycdn($url, $width, $height) {
    return "https://thumbnails.odycdn.com/optimize/s:{$width}:{$height}/quality:85/plain/{$url}";
}
