<?php
/**
 * Multi-Language Support
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Language {

    private static $instance;
    private $languages = [];
    private $current_lang;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->languages = $this->get_available_languages();
        $this->current_lang = $this->get_current_language();

        add_action('init', [$this, 'set_language']);
        add_filter('locale', [$this, 'filter_locale']);
    }

    /**
     * Get available languages
     */
    public function get_available_languages() {
        $default_languages = [
            'en' => ['name' => 'English', 'native' => 'English', 'flag' => '🇬🇧'],
            'fr' => ['name' => 'French', 'native' => 'Français', 'flag' => '🇫🇷'],
            'es' => ['name' => 'Spanish', 'native' => 'Español', 'flag' => '🇪🇸'],
            'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'flag' => '🇵🇹'],
            'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'flag' => '🇸🇦'],
            'ha' => ['name' => 'Hausa', 'native' => 'Hausa', 'flag' => '🇳🇬'],
            'yo' => ['name' => 'Yoruba', 'native' => 'Yorùbá', 'flag' => '🇳🇬'],
            'ig' => ['name' => 'Igbo', 'native' => 'Igbo', 'flag' => '🇳🇬'],
            'sw' => ['name' => 'Swahili', 'native' => 'Kiswahili', 'flag' => '🇰🇪'],
            'de' => ['name' => 'German', 'native' => 'Deutsch', 'flag' => '🇩🇪'],
        ];

        $custom = get_option('matrix_mlm_custom_languages', []);
        return array_merge($default_languages, $custom);
    }

    /**
     * Get current language
     */
    public function get_current_language() {
        if (isset($_COOKIE['matrix_language'])) {
            return sanitize_text_field($_COOKIE['matrix_language']);
        }
        return get_option('matrix_mlm_default_language', 'en');
    }

    /**
     * Set language from user preference
     */
    public function set_language() {
        if (isset($_GET['lang'])) {
            $lang = sanitize_text_field($_GET['lang']);
            if (array_key_exists($lang, $this->languages)) {
                setcookie('matrix_language', $lang, time() + (365 * DAY_IN_SECONDS), '/');
                $this->current_lang = $lang;
            }
        }
    }

    /**
     * Filter locale
     */
    public function filter_locale($locale) {
        $lang_map = [
            'en' => 'en_US', 'fr' => 'fr_FR', 'es' => 'es_ES',
            'pt' => 'pt_BR', 'ar' => 'ar', 'de' => 'de_DE',
            'ha' => 'ha', 'yo' => 'yo', 'ig' => 'ig', 'sw' => 'sw'
        ];

        if (isset($lang_map[$this->current_lang])) {
            return $lang_map[$this->current_lang];
        }

        return $locale;
    }

    /**
     * Render language switcher
     */
    public function render_switcher() {
        $enabled = get_option('matrix_mlm_enabled_languages', ['en']);
        if (!is_array($enabled)) $enabled = ['en'];

        $html = '<div class="matrix-lang-switcher">';
        $html .= '<select onchange="window.location.href=this.value" class="matrix-lang-select">';

        foreach ($enabled as $code) {
            if (!isset($this->languages[$code])) continue;
            $lang = $this->languages[$code];
            $url = add_query_arg('lang', $code);
            $selected = ($code === $this->current_lang) ? 'selected' : '';
            $html .= sprintf(
                '<option value="%s" %s>%s %s</option>',
                esc_url($url), $selected, $lang['flag'], esc_html($lang['native'])
            );
        }

        $html .= '</select></div>';
        return $html;
    }
}
