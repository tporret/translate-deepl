<?php
declare(strict_types=1);

namespace TranslateDeepL\Repository;

if (! defined('ABSPATH')) {
    exit;
}

final class SettingsRepository
{
    /**
     * @return array<int, string>
     */
    public function getSupportedPostTypes(): array
    {
        $defaults = ['post', 'page', 'wp_template', 'wp_template_part', 'wp_block'];

        $value = apply_filters('deepl_supported_post_types', $defaults);

        if (! is_array($value)) {
            return $defaults;
        }

        $types = [];

        foreach ($value as $item) {
            $type = sanitize_key((string) $item);

            if ($type === '') {
                continue;
            }

            $types[] = $type;
        }

        $types = array_values(array_unique($types));

        if ($types === []) {
            return $defaults;
        }

        return $types;
    }

    public function getApiKey(): string
    {
        return (string) get_option('deepl_api_key', '');
    }

    /**
     * @return array<int, string>
     */
    public function getActiveLanguages(): array
    {
        $value = get_option('deepl_active_languages', []);

        if (! is_array($value)) {
            return [];
        }

        return array_map(static fn ($item) => (string) $item, $value);
    }

    public function isSweeperEnabled(): bool
    {
        $value = get_option('deepl_sweeper_enabled', false);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    public function getSweeperTime(): string
    {
        $value = (string) get_option('deepl_sweeper_time', '11:00');

        if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $value) !== 1) {
            return '11:00';
        }

        return $value;
    }
}
