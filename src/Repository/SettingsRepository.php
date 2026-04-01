<?php
declare(strict_types=1);

namespace TranslateDeepL\Repository;

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
}
