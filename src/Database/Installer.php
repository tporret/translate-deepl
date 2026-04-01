<?php
declare(strict_types=1);

namespace TranslateDeepL\Database;

final class Installer
{
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
    }
}
