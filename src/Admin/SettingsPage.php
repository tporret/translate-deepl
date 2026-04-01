<?php
declare(strict_types=1);

namespace TranslateDeepL\Admin;

if (! defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    private const PAGE_SLUG = 'translate-deepl';
    private const SETTINGS_GROUP = 'deepl_settings_group';
    private const SECTION_ID = 'deepl_main_section';

    /**
     * @var array<string, string>
     */
    private array $availableLanguages = [
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
    ];

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerMenuPage(): void
    {
        add_options_page(
            'Translate DeepL',
            'Translate DeepL',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            'deepl_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitizeApiKey'],
                'default' => '',
            ]
        );

        register_setting(
            self::SETTINGS_GROUP,
            'deepl_active_languages',
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeLanguages'],
                'default' => [],
            ]
        );

        add_settings_section(
            self::SECTION_ID,
            'DeepL Settings',
            [$this, 'renderSectionDescription'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'deepl_api_key',
            'DeepL API Key',
            [$this, 'renderApiKeyField'],
            self::PAGE_SLUG,
            self::SECTION_ID
        );

        add_settings_field(
            'deepl_active_languages',
            'Active Languages',
            [$this, 'renderLanguagesField'],
            self::PAGE_SLUG,
            self::SECTION_ID
        );
    }

    public function sanitizeApiKey(string $apiKey): string
    {
        return sanitize_text_field($apiKey);
    }

    /**
     * @param mixed $languages
     *
     * @return array<int, string>
     */
    public function sanitizeLanguages(mixed $languages): array
    {
        if (! is_array($languages)) {
            return [];
        }

        $sanitized = [];

        foreach ($languages as $languageCode) {
            $code = strtolower(sanitize_key((string) $languageCode));

            if (! array_key_exists($code, $this->availableLanguages)) {
                continue;
            }

            $sanitized[] = $code;
        }

        return array_values(array_unique($sanitized));
    }

    public function renderSectionDescription(): void
    {
        echo '<p>Configure your DeepL API credentials and target languages for automatic translations.</p>';
    }

    public function renderApiKeyField(): void
    {
        $apiKey = (string) get_option('deepl_api_key', '');

        printf(
            '<input type="password" id="deepl_api_key" name="deepl_api_key" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr($apiKey)
        );
    }

    public function renderLanguagesField(): void
    {
        $activeLanguages = get_option('deepl_active_languages', []);

        if (! is_array($activeLanguages)) {
            $activeLanguages = [];
        }

        foreach ($this->availableLanguages as $code => $label) {
            printf(
                '<label><input type="checkbox" name="deepl_active_languages[]" value="%1$s" %2$s /> %3$s</label><br/>',
                esc_attr($code),
                checked(in_array($code, $activeLanguages, true), true, false),
                esc_html($label)
            );
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        echo '<div class="wrap">';
        echo '<h1>Translate DeepL</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::SETTINGS_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button('Save Settings');
        echo '</form>';
        echo '</div>';
    }
}
