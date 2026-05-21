<?php
/**
 * SEO Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_SEO {

    public function __construct() {
        add_action('wp_head', [$this, 'output_meta_tags'], 1);
        add_filter('document_title_parts', [$this, 'filter_title']);
    }

    /**
     * Output meta tags
     */
    public function output_meta_tags() {
        $meta_description = get_option('matrix_mlm_meta_description', '');
        $meta_keywords = get_option('matrix_mlm_meta_keywords', '');
        $og_image = get_option('matrix_mlm_og_image', '');

        if ($meta_description) {
            echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
        }
        if ($meta_keywords) {
            echo '<meta name="keywords" content="' . esc_attr($meta_keywords) . '">' . "\n";
        }

        // Open Graph
        echo '<meta property="og:title" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:url" content="' . esc_url(home_url()) . '">' . "\n";
        if ($og_image) {
            echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
        }
        if ($meta_description) {
            echo '<meta property="og:description" content="' . esc_attr($meta_description) . '">' . "\n";
        }

        // Custom head code
        $custom_head = get_option('matrix_mlm_custom_head_code', '');
        if ($custom_head) {
            echo $custom_head . "\n";
        }
    }

    /**
     * Filter page title
     */
    public function filter_title($title_parts) {
        $site_title = get_option('matrix_mlm_seo_title', '');
        if ($site_title && is_front_page()) {
            $title_parts['title'] = $site_title;
        }
        return $title_parts;
    }
}
