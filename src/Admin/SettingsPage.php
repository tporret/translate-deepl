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

        register_setting(
            self::SETTINGS_GROUP,
            'deepl_sweeper_enabled',
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitizeSweeperEnabled'],
                'default' => false,
            ]
        );

        register_setting(
            self::SETTINGS_GROUP,
            'deepl_sweeper_time',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitizeSweeperTime'],
                'default' => '11:00',
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

        add_settings_field(
            'deepl_sweeper_enabled',
            'Daily Sweep',
            [$this, 'renderSweeperEnabledField'],
            self::PAGE_SLUG,
            self::SECTION_ID
        );

        add_settings_field(
            'deepl_sweeper_time',
            'Sweep Time',
            [$this, 'renderSweeperTimeField'],
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

    public function sanitizeSweeperEnabled(mixed $enabled): bool
    {
        if (is_bool($enabled)) {
            return $enabled;
        }

        if (is_int($enabled)) {
            return $enabled === 1;
        }

        if (is_string($enabled)) {
            return in_array(strtolower($enabled), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    public function sanitizeSweeperTime(string $time): string
    {
        $value = trim($time);

        if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $value) !== 1) {
            return '11:00';
        }

        return $value;
    }

    public function renderSectionDescription(): void
    {
        $wordpressNow = wp_date('Y-m-d H:i:s');
        $wordpressTimezone = wp_timezone_string();
        $serverNow = date('Y-m-d H:i:s');
        $serverTimezone = date_default_timezone_get();
        $configuredTimezone = (string) get_option('timezone_string', '');

        echo '<p>Configure your DeepL API credentials and target languages for automatic translations.</p>';

        printf(
            '<p><strong>Current WordPress time:</strong> %1$s (%2$s)</p>',
            esc_html($wordpressNow),
            esc_html($wordpressTimezone)
        );

        printf(
            '<p><strong>Current server time:</strong> %1$s (%2$s)</p>',
            esc_html($serverNow),
            esc_html($serverTimezone)
        );

        if ($configuredTimezone === '') {
            echo '<div class="notice notice-warning inline"><p>Your WordPress timezone is not set to a named city (Settings > General). Daily sweep timing may be inaccurate around DST changes.</p></div>';
        }

        if ($wordpressTimezone !== $serverTimezone) {
            echo '<div class="notice notice-warning inline"><p>WordPress timezone and server timezone differ. The sweeper uses WordPress time shown above. Verify this before setting a daily sweep time.</p></div>';
        }
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

    public function renderSweeperEnabledField(): void
    {
        $enabled = get_option('deepl_sweeper_enabled', false);

        printf(
            '<label><input type="checkbox" id="deepl_sweeper_enabled" name="deepl_sweeper_enabled" value="1" %s /> Enable Daily Auto-Translation Sweep</label>',
            checked((bool) $enabled, true, false)
        );
    }

    public function renderSweeperTimeField(): void
    {
        $time = (string) get_option('deepl_sweeper_time', '11:00');

        if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time) !== 1) {
            $time = '11:00';
        }

        printf(
            '<input type="time" id="deepl_sweeper_time" name="deepl_sweeper_time" value="%s" step="60" />',
            esc_attr($time)
        );
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
        $this->renderSweeperDashboard();
        echo '</div>';
    }

    private function renderSweeperDashboard(): void
    {
        $logs = get_option('deepl_sweeper_logs', []);

        if (! is_array($logs)) {
            $logs = [];
        }

        $sweeperEnabled = (bool) get_option('deepl_sweeper_enabled', false);
        $sweeperTime = (string) get_option('deepl_sweeper_time', '11:00');
        $nextRun = $this->getNextSweeperRunTimestamp();

        echo '<hr style="margin: 28px 0;" />';
        echo '<h2>Sweeper Dashboard</h2>';

        echo '<table class="widefat striped" style="max-width: 900px; margin-bottom: 16px;">';
        echo '<tbody>';

        printf(
            '<tr><th style="width: 220px;">Sweeper Enabled</th><td>%s</td></tr>',
            esc_html($sweeperEnabled ? 'Yes' : 'No')
        );

        printf(
            '<tr><th>Configured Sweep Time</th><td>%s</td></tr>',
            esc_html($sweeperTime)
        );

        printf(
            '<tr><th>Next Scheduled Run</th><td>%s</td></tr>',
            esc_html($nextRun)
        );

        printf(
            '<tr><th>Stored Log Entries</th><td>%d</td></tr>',
            count($logs)
        );

        echo '</tbody>';
        echo '</table>';

        echo '<h3>Recent Sweeper Runs (Last 20)</h3>';

        if ($logs === []) {
            echo '<p>No sweeper runs logged yet.</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width: 900px;">';
        echo '<thead><tr>';
        echo '<th style="width: 260px;">Run Time</th>';
        echo '<th style="width: 180px;">Queued Items</th>';
        echo '<th>Languages</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($logs as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $queuedCount = isset($entry['queued_count']) ? (int) $entry['queued_count'] : 0;
            $languages = isset($entry['languages']) && is_array($entry['languages'])
                ? array_values(array_map(static fn ($code): string => strtoupper((string) $code), $entry['languages']))
                : [];

            $runTime = $timestamp > 0
                ? wp_date('Y-m-d H:i:s', $timestamp) . ' (' . wp_timezone_string() . ')'
                : 'Unknown';

            printf(
                '<tr><td>%1$s</td><td>%2$d</td><td>%3$s</td></tr>',
                esc_html($runTime),
                $queuedCount,
                esc_html($languages !== [] ? implode(', ', $languages) : 'None')
            );
        }

        echo '</tbody>';
        echo '</table>';
    }

    private function getNextSweeperRunTimestamp(): string
    {
        if (! function_exists('as_next_scheduled_action')) {
            return 'Action Scheduler not available';
        }

        $next = as_next_scheduled_action('deepl_daily_sweeper_job', [], 'translate-deepl');

        if ($next === false) {
            return 'Not scheduled';
        }

        return wp_date('Y-m-d H:i:s', (int) $next) . ' (' . wp_timezone_string() . ')';
    }
}
