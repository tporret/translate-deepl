<?php
declare(strict_types=1);

namespace TranslateDeepL\Repository;

if (! defined('ABSPATH')) {
    exit;
}

final class AssetRegistryRepository
{
    public const STATUS_PUBLISHED = 'published';

    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function saveSourceAsset(
        string $assetKey,
        string $assetType,
        string $sourceKind,
        string $themeSlug,
        string $title,
        string $content,
        string $contentHash,
        array $metadata = []
    ): bool {
        $result = $this->wpdb->replace(
            $this->getSourcesTableName(),
            [
                'asset_key' => $assetKey,
                'asset_type' => $assetType,
                'source_kind' => $sourceKind,
                'theme_slug' => $themeSlug,
                'title' => $title,
                'content' => $content,
                'content_hash' => $contentHash,
                'metadata_json' => wp_json_encode($metadata),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSourceAsset(string $assetKey): ?array
    {
        $query = $this->wpdb->prepare(
            'SELECT asset_key, asset_type, source_kind, theme_slug, title, content, content_hash, metadata_json FROM '
            . $this->getSourcesTableName()
            . ' WHERE asset_key = %s LIMIT 1',
            $assetKey
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $row = $this->wpdb->get_row($query, ARRAY_A);

        if (! is_array($row)) {
            return null;
        }

        $row['metadata'] = $this->decodeMetadata(isset($row['metadata_json']) ? (string) $row['metadata_json'] : '');

        return $row;
    }

    /**
     * @param array<int, string> $assetKeys
     */
    public function deleteMissingSourceAssets(string $themeSlug, array $assetKeys): void
    {
        if ($assetKeys === []) {
            $query = $this->wpdb->prepare(
                'DELETE FROM ' . $this->getSourcesTableName() . ' WHERE theme_slug = %s',
                $themeSlug
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
            $this->wpdb->query($query);

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($assetKeys), '%s'));
        $prepareArgs = array_merge([$themeSlug], $assetKeys);
        $query = $this->wpdb->prepare(
            'DELETE FROM ' . $this->getSourcesTableName() . ' WHERE theme_slug = %s AND asset_key NOT IN (' . $placeholders . ')',
            ...$prepareArgs
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $this->wpdb->query($query);
    }

    public function saveTranslation(
        string $assetKey,
        string $languageCode,
        string $translatedTitle,
        string $translatedContent,
        string $sourceContentHash,
        string $status = self::STATUS_PUBLISHED
    ): bool {
        $result = $this->wpdb->replace(
            $this->getTranslationsTableName(),
            [
                'asset_key' => $assetKey,
                'language_code' => $languageCode,
                'translated_title' => $translatedTitle,
                'translated_content' => $translatedContent,
                'source_content_hash' => $sourceContentHash,
                'status' => $status,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPublishedTranslation(string $assetKey, string $languageCode): ?array
    {
        $query = $this->wpdb->prepare(
            'SELECT asset_key, language_code, translated_title, translated_content, source_content_hash, status FROM '
            . $this->getTranslationsTableName()
            . ' WHERE asset_key = %s AND language_code = %s AND status = %s LIMIT 1',
            $assetKey,
            $languageCode,
            self::STATUS_PUBLISHED
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $row = $this->wpdb->get_row($query, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function hasPublishedTranslation(string $assetKey, string $languageCode): bool
    {
        return $this->getPublishedTranslation($assetKey, $languageCode) !== null;
    }

    private function getSourcesTableName(): string
    {
        return sprintf('%sdeepl_registry_assets', $this->wpdb->prefix);
    }

    private function getTranslationsTableName(): string
    {
        return sprintf('%sdeepl_registry_asset_translations', $this->wpdb->prefix);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(string $metadataJson): array
    {
        if ($metadataJson === '') {
            return [];
        }

        $decoded = json_decode($metadataJson, true);

        return is_array($decoded) ? $decoded : [];
    }
}