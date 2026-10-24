<?php
/**
 * Plugin Name: CWM Help REST Free
 * Description: Correctly routes REST-uploaded HTML help docs into /uploads/cwm-help/{help_path} and makes them indexable by Relevanssi.
 */

if (!defined('ABSPATH')) exit;

class CWM_Help_REST_Free {
    private $current_help_path = null;

    public function __construct() {

        // Capture help_path from REST API
        add_filter('rest_request_before_callbacks', [$this, 'capture_help_path'], 10, 3);

        // Override upload dir ONLY for these files
        add_filter('upload_dir', [$this, 'override_upload_dir']);

        // Populate attachment post_content for Relevanssi
        add_action('add_attachment', [$this, 'fill_attachment_content']);

        // Redirect attachment → actual HTML file
        add_action('template_redirect', [$this, 'redirect_attachment']);
    }

    /** 1️⃣ Capture help_path from the REST media request */
    public function capture_help_path($response, $handler, $request) {
        if ($request->get_route() === '/wp/v2/media') {
            if ($request->get_method() === 'POST') {

                if (!empty($request['help_path'])) {
                    $this->current_help_path = sanitize_text_field($request['help_path']);
                }
            }
        }

        return $response;
    }

    /** 2️⃣ Override upload directory for this request */
    public function override_upload_dir($dirs) {
        // Only modify directory IF this is a help upload
        if ($this->current_help_path) {

            $help_path = str_replace('\\', '/', ltrim($this->current_help_path, '/'));

            $dirs['subdir'] = '/cwm-help/' . dirname($help_path);
            if ($dirs['subdir'] === '/cwm-help/.') {
                $dirs['subdir'] = '/cwm-help';
            }

            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']  = $dirs['baseurl'] . $dirs['subdir'];

            // Ensure directory exists
            if (!file_exists($dirs['path'])) {
                wp_mkdir_p($dirs['path']);
            }
        }

        return $dirs;
    }

    /** 3️⃣ Fill attachment post_content with stripped HTML text */
    public function fill_attachment_content($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_mime_type !== 'text/html') return;

        $file = get_attached_file($post_id);
        if (!$file || !file_exists($file)) return;

        $content = file_get_contents($file);
        if ($content === false) return;

        $text = wp_strip_all_tags($content);
        $title = pathinfo($file, PATHINFO_FILENAME);

        wp_update_post([
            'ID' => $post_id,
            'post_title' => $title,
            'post_content' => $text
        ]);
    }

    /** 4️⃣ Redirect attachment page → raw HTML file */
    public function redirect_attachment() {
        if (!is_attachment()) return;
        $post = get_post();

        if ($post->post_mime_type !== 'text/html') return;

        $file = get_attached_file($post->ID);
        if (!$file) return;

        $upload = wp_upload_dir();
        $relative = str_replace($upload['basedir'], '', $file);
        $url = $upload['baseurl'] . $relative;

        wp_redirect($url, 301);
        exit;
    }
}

new CWM_Help_REST_Free();
