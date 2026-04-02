<?php
declare(strict_types=1);

namespace TranslateDeepL\Database;

final class Installer
{
    private const FSE_TERMS_BACKFILL_OPTION = 'deepl_fse_terms_backfill_v1_done';

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

        $postRelationsSql = "
            CREATE TABLE {$postRelationsTable} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                original_post_id BIGINT UNSIGNED NOT NULL,
                translated_post_id BIGINT UNSIGNED NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY unique_original_language (original_post_id, language_code),
                KEY translated_post_id (translated_post_id)
            ) {$charsetCollate};
        ";

        $translationMemorySql = "
            CREATE TABLE {$translationMemoryTable} (
                hash VARCHAR(64) NOT NULL,
                source_lang VARCHAR(10) NOT NULL,
                target_lang VARCHAR(10) NOT NULL,
                translated_text LONGTEXT NOT NULL,
                PRIMARY KEY  (hash),
                KEY source_target_lang (source_lang, target_lang)
            ) {$charsetCollate};
        ";

        dbDelta($postRelationsSql);
        dbDelta($translationMemorySql);

        $this->backfillLegacyFseTerms();
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
