<?php
declare(strict_types=1);

namespace TranslateDeepL\Repository;

if (! defined('ABSPATH')) {
    exit;
}

final class PostRelationRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function saveRelation(int $originalId, int $translatedId, string $langCode): bool
    {
        $result = $this->wpdb->replace(
            $this->getTableName(),
            [
                'original_post_id' => $originalId,
                'translated_post_id' => $translatedId,
                'language_code' => $langCode,
            ],
            ['%d', '%d', '%s']
        );

        return $result !== false;
    }

    public function getTranslatedPostId(int $originalId, string $langCode): ?int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name cannot be passed as a placeholder.
        $query = $this->wpdb->prepare(
            sprintf(
                'SELECT translated_post_id FROM %s WHERE original_post_id = %%d AND language_code = %%s LIMIT 1',
                $this->getTableName()
            ),
            $originalId,
            $langCode
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $value = $this->wpdb->get_var($query);

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    public function getOriginalPostId(int $translatedId): ?int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name cannot be passed as a placeholder.
        $query = $this->wpdb->prepare(
            sprintf(
                'SELECT original_post_id FROM %s WHERE translated_post_id = %%d LIMIT 1',
                $this->getTableName()
            ),
            $translatedId
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $value = $this->wpdb->get_var($query);

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    public function getLanguageCodeByTranslatedPostId(int $translatedId): ?string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name cannot be passed as a placeholder.
        $query = $this->wpdb->prepare(
            sprintf(
                'SELECT language_code FROM %s WHERE translated_post_id = %%d LIMIT 1',
                $this->getTableName()
            ),
            $translatedId
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $value = $this->wpdb->get_var($query);

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public function getTranslatedPostIdBySlugAndLanguage(string $slug, string $langCode): ?int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be passed as placeholders.
        $query = $this->wpdb->prepare(
            sprintf(
                'SELECT rel.translated_post_id
                FROM %1$s rel
                INNER JOIN %2$s posts ON posts.ID = rel.translated_post_id
                WHERE rel.language_code = %%s
                  AND posts.post_name = %%s
                  AND posts.post_status IN ("publish", "private", "draft")
                LIMIT 1',
                $this->getTableName(),
                $this->wpdb->posts
            ),
            $langCode,
            $slug
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $value = $this->wpdb->get_var($query);

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function getTableName(): string
    {
        return sprintf('%sdeepl_post_relations', $this->wpdb->prefix);
    }
}
