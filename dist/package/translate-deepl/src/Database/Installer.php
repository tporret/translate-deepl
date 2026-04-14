<?php
declare(strict_types=1);

namespace TranslateDeepL\Database;

final class Installer
{
    private const FSE_TERMS_BACKFILL_OPTION = 'deepl_fse_terms_backfill_v1_done';
    private const TABLE_ENGINE = 'InnoDB';

    public function install(): void
    {
        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $postRelationsTable = sprintf('%sdeepl_post_relations', $wpdb->prefix);
        $translationMemoryTable = sprintf('%sdeepl_translation_memory', $wpdb->prefix);
        $registrySourcesTable = sprintf('%sdeepl_registry_assets', $wpdb->prefix);
        $registryTranslationsTable = sprintf('%sdeepl_registry_asset_translations', $wpdb->prefix);

        $postRelationsSql = "
            CREATE TABLE {$postRelationsTable} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                original_post_id BIGINT UNSIGNED NOT NULL,
                translated_post_id BIGINT UNSIGNED NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY unique_original_language (original_post_id, language_code),
                KEY translated_post_id (translated_post_id)
            ) ENGINE=" . self::TABLE_ENGINE . " {$charsetCollate};
        ";

        $translationMemorySql = "
            CREATE TABLE {$translationMemoryTable} (
                hash CHAR(64) NOT NULL,
                source_lang VARCHAR(10) NOT NULL,
                target_lang VARCHAR(10) NOT NULL,
                translated_text LONGTEXT NOT NULL,
                PRIMARY KEY  (hash, target_lang),
                KEY source_target_lang (source_lang, target_lang)
            ) ENGINE=" . self::TABLE_ENGINE . " {$charsetCollate};
        ";

        $registrySourcesSql = "
            CREATE TABLE {$registrySourcesTable} (
                asset_key VARCHAR(191) NOT NULL,
                asset_type VARCHAR(32) NOT NULL,
                source_kind VARCHAR(32) NOT NULL,
                theme_slug VARCHAR(191) NOT NULL,
                title TEXT NOT NULL,
                content LONGTEXT NOT NULL,
                content_hash CHAR(64) NOT NULL,
                metadata_json LONGTEXT NULL,
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (asset_key),
                KEY theme_kind (theme_slug, source_kind),
                KEY asset_type_kind (asset_type, source_kind),
                KEY content_hash (content_hash)
            ) ENGINE=" . self::TABLE_ENGINE . " {$charsetCollate};
        ";

        $registryTranslationsSql = "
            CREATE TABLE {$registryTranslationsTable} (
                asset_key VARCHAR(191) NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                translated_title TEXT NOT NULL,
                translated_content LONGTEXT NOT NULL,
                source_content_hash CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'published',
                translated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (asset_key, language_code),
                KEY status_language (status, language_code),
                KEY source_content_hash (source_content_hash)
            ) ENGINE=" . self::TABLE_ENGINE . " {$charsetCollate};
        ";

        dbDelta($postRelationsSql);
        dbDelta($translationMemorySql);
        dbDelta($registrySourcesSql);
        dbDelta($registryTranslationsSql);

        $this->enforceCustomTableStorage(
            $wpdb,
            $postRelationsTable,
            $translationMemoryTable,
            $registrySourcesTable,
            $registryTranslationsTable
        );
        $this->enforceTranslationMemoryKeyShape($wpdb, $translationMemoryTable);

        $this->backfillLegacyFseTerms();
    }

    private function enforceCustomTableStorage(
        \wpdb $wpdb,
        string $postRelationsTable,
        string $translationMemoryTable,
        string $registrySourcesTable,
        string $registryTranslationsTable
    ): void {
        foreach ([$postRelationsTable, $translationMemoryTable, $registrySourcesTable, $registryTranslationsTable] as $tableName) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be passed as placeholders.
            $wpdb->query("ALTER TABLE {$tableName} ENGINE=" . self::TABLE_ENGINE);
        }
    }

    private function enforceTranslationMemoryKeyShape(\wpdb $wpdb, string $translationMemoryTable): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be passed as placeholders.
        $wpdb->query("ALTER TABLE {$translationMemoryTable} MODIFY hash CHAR(64) NOT NULL");

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be passed as placeholders.
        $wpdb->query(
            "ALTER TABLE {$translationMemoryTable}
             DROP PRIMARY KEY,
             ADD PRIMARY KEY (hash, target_lang)"
        );
    }

    private function backfillLegacyFseTerms(): void
    {
        if ((bool) get_option(self::FSE_TERMS_BACKFILL_OPTION, false)) {
            return;
        }

        if (! function_exists('get_block_templates') || ! taxonomy_exists('wp_theme')) {
            update_option(self::FSE_TERMS_BACKFILL_OPTION, true);
            return;
        }

        $templateCatalog = $this->buildCurrentThemeTemplateCatalog();

        if ($templateCatalog === []) {
            update_option(self::FSE_TERMS_BACKFILL_OPTION, true);
            return;
        }

        $query = new \WP_Query([
            'post_type' => ['wp_template', 'wp_template_part'],
            'post_status' => ['publish', 'draft', 'auto-draft', 'private'],
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);

        if (is_array($query->posts)) {
            foreach ($query->posts as $postId) {
                $post = get_post((int) $postId);

                if (! $post instanceof \WP_Post) {
                    continue;
                }

                $type = (string) $post->post_type;
                $slug = (string) $post->post_name;
                $key = $type . ':' . $slug;

                if (! isset($templateCatalog[$key])) {
                    continue;
                }

                $themeTerms = wp_get_object_terms((int) $post->ID, 'wp_theme', ['fields' => 'slugs']);

                if (! is_wp_error($themeTerms) && (is_array($themeTerms) && $themeTerms === [])) {
                    wp_set_object_terms((int) $post->ID, [get_stylesheet()], 'wp_theme', false);
                }

                $area = $templateCatalog[$key]['area'];

                if (
                    $type === 'wp_template_part'
                    && $area !== ''
                    && taxonomy_exists('wp_template_part_area')
                ) {
                    $areaTerms = wp_get_object_terms((int) $post->ID, 'wp_template_part_area', ['fields' => 'slugs']);

                    if (! is_wp_error($areaTerms) && (is_array($areaTerms) && $areaTerms === [])) {
                        wp_set_object_terms((int) $post->ID, [$area], 'wp_template_part_area', false);
                    }
                }
            }
        }

        update_option(self::FSE_TERMS_BACKFILL_OPTION, true);
    }

    /**
     * @return array<string, array{area:string}>
     */
    private function buildCurrentThemeTemplateCatalog(): array
    {
        $catalog = [];

        $templates = array_merge(
            get_block_templates([], 'wp_template'),
            get_block_templates([], 'wp_template_part')
        );

        foreach ($templates as $template) {
            if (! is_object($template)) {
                continue;
            }

            $slug = isset($template->slug) ? (string) $template->slug : '';
            $type = isset($template->type) ? (string) $template->type : '';
            $source = isset($template->source) ? (string) $template->source : '';

            if ($slug === '' || $type === '' || $source !== 'theme') {
                continue;
            }

            $area = '';

            if (isset($template->area) && is_string($template->area)) {
                $area = $template->area;
            }

            $catalog[$type . ':' . $slug] = [
                'area' => $area,
            ];
        }

        return $catalog;
    }
}
