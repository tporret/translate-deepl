<?php
declare(strict_types=1);

namespace TranslateDeepL\Repository;

if (! defined('ABSPATH')) {
    exit;
}

final class TranslationMemoryRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function getTranslation(string $hash, string $targetLang): ?string
    {
        $query = $this->wpdb->prepare(
            'SELECT translated_text FROM ' . $this->getTableName() . ' WHERE hash = %s AND target_lang = %s LIMIT 1',
            $hash,
            $targetLang
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $value = $this->wpdb->get_var($query);

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public function saveTranslation(
        string $hash,
        string $sourceLang,
        string $targetLang,
        string $translatedText
    ): bool {
        $result = $this->wpdb->replace(
            $this->getTableName(),
            [
                'hash' => $hash,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'translated_text' => $translatedText,
            ],
            ['%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    private function getTableName(): string
    {
        return sprintf('%sdeepl_translation_memory', $this->wpdb->prefix);
    }
}
