<?php
declare(strict_types=1);

namespace TranslateDeepL\Frontend;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\PostRelationRepository;

final class TemplateResolver
{
    private const QUERY_VAR = 'deepl_lang';

    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly PostRelationRepository $postRelationRepository
    ) {
    }

    public function registerHooks(): void
    {
        add_filter('render_block_core/template-part', [$this, 'resolveTemplatePart'], 10, 2);
    }

    /**
     * @param array<string, mixed> $block
     */
    public function resolveTemplatePart(string $blockContent, array $block): string
    {
        $lang = $this->getCurrentLanguage();

        if ($lang === null) {
            return $blockContent;
        }

        $slug = isset($block['attrs']['slug']) && is_string($block['attrs']['slug'])
            ? $block['attrs']['slug']
            : '';

        if ($slug === '') {
            return $blockContent;
        }

        $originalPostId = $this->findTemplatePartPostId($slug);

        if ($originalPostId === null) {
            return $blockContent;
        }

        $translatedPostId = $this->postRelationRepository->getTranslatedPostId($originalPostId, $lang);

        if ($translatedPostId === null) {
            return $blockContent;
        }

        $translatedPost = get_post($translatedPostId);

        if (! $translatedPost instanceof \WP_Post) {
            return $blockContent;
        }

        return (string) do_blocks((string) $translatedPost->post_content);
    }

    private function findTemplatePartPostId(string $slug): ?int
    {
        $postsTable    = $this->wpdb->posts;
        $termRelTable  = $this->wpdb->term_relationships;
        $termTaxTable  = $this->wpdb->term_taxonomy;
        $termsTable    = $this->wpdb->terms;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be passed as placeholders.
        $query = $this->wpdb->prepare(
            "SELECT p.ID
             FROM {$postsTable} p
             INNER JOIN {$termRelTable}  tr ON tr.object_id          = p.ID
             INNER JOIN {$termTaxTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$termsTable}    t  ON t.term_id            = tt.term_id
             WHERE p.post_name   = %s
               AND p.post_type   = 'wp_template_part'
               AND p.post_status IN ('publish', 'auto-draft')
               AND tt.taxonomy   = 'wp_theme'
               AND t.slug        = %s
             LIMIT 1",
            $slug,
            get_stylesheet()
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $value = $this->wpdb->get_var($query);

        if ($value !== null) {
            return (int) $value;
        }

        // Fallback for legacy rows without wp_theme taxonomy (pre-backfill).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name cannot be passed as a placeholder.
        $fallbackQuery = $this->wpdb->prepare(
            "SELECT ID FROM {$postsTable}
             WHERE post_name   = %s
               AND post_type   = 'wp_template_part'
               AND post_status IN ('publish', 'auto-draft')
             LIMIT 1",
            $slug
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        $fallbackValue = $this->wpdb->get_var($fallbackQuery);

        return $fallbackValue !== null ? (int) $fallbackValue : null;
    }

    private function getCurrentLanguage(): ?string
    {
        $langCode = get_query_var(self::QUERY_VAR);

        if (! is_string($langCode) || $langCode === '') {
            return null;
        }

        return sanitize_key($langCode);
    }
}
