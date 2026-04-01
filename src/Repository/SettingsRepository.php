<?php
declare(strict_types=1);

namespace TranslateDeepL\Repository;

if (! defined('ABSPATH')) {
    exit;
}

final class SettingsRepository
{
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
