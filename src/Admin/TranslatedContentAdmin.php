<?php
declare(strict_types=1);

namespace TranslateDeepL\Admin;

if (! defined('ABSPATH')) {
    exit;
}

final class TranslatedContentAdmin
{
    private const FILTER_QUERY_VAR = 'deepl_set';

    public function registerHooks(): void
    {
        add_filter('map_meta_cap', [$this, 'blockTranslatedPostEditing'], 10, 4);
        add_filter('post_row_actions', [$this, 'filterRowActions'], 10, 2);
        add_filter('page_row_actions', [$this, 'filterRowActions'], 10, 2);
        add_filter('views_edit-post', [$this, 'addSetViews']);
        add_filter('views_edit-page', [$this, 'addSetViews']);
        add_filter('manage_post_posts_columns', [$this, 'addLanguageColumn']);
        add_filter('manage_page_posts_columns', [$this, 'addLanguageColumn']);
        add_filter('manage_edit-post_sortable_columns', [$this, 'addSortableLanguageColumn']);
        add_filter('manage_edit-page_sortable_columns', [$this, 'addSortableLanguageColumn']);
        add_action('manage_post_posts_custom_column', [$this, 'renderLanguageColumn'], 10, 2);
        add_action('manage_page_posts_custom_column', [$this, 'renderLanguageColumn'], 10, 2);
        add_action('pre_get_posts', [$this, 'filterListQuery']);
    }

    /**
     * @param array<int, string> $caps
     * @param array<int, mixed> $args
     *
     * @return array<int, string>
     */
    public function blockTranslatedPostEditing(array $caps, string $cap, int $userId, array $args): array
    {
        if ($cap !== 'edit_post') {
            return $caps;
        }

        $postId = isset($args[0]) ? (int) $args[0] : 0;

        if ($postId <= 0) {
            return $caps;
        }

        if (! $this->isTranslatedPost($postId)) {
            return $caps;
        }

        return ['do_not_allow'];
    }

    /**
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function filterRowActions(array $actions, \WP_Post $post): array
    {
        if (! $this->isTranslatedPost((int) $post->ID)) {
            return $actions;
        }

        unset($actions['edit'], $actions['inline hide']);

        return $actions;
    }

    /**
     * @param array<string, string> $views
     *
     * @return array<string, string>
     */
    public function addSetViews(array $views): array
    {
        $currentSet = isset($_GET[self::FILTER_QUERY_VAR])
            ? sanitize_key((string) $_GET[self::FILTER_QUERY_VAR])
            : 'all';

        $postType = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : 'post';

        $baseUrl = add_query_arg(
            [
                'post_type' => $postType,
            ],
            admin_url('edit.php')
        );

        $links = [
            'all' => add_query_arg(self::FILTER_QUERY_VAR, 'all', $baseUrl),
            'original' => add_query_arg(self::FILTER_QUERY_VAR, 'original', $baseUrl),
            'translated' => add_query_arg(self::FILTER_QUERY_VAR, 'translated', $baseUrl),
        ];

        $views['deepl_all'] = sprintf(
            '<a href="%s" class="%s">All</a>',
            esc_url($links['all']),
            $currentSet === 'all' ? 'current' : ''
        );

        $views['deepl_original'] = sprintf(
            '<a href="%s" class="%s">Original</a>',
            esc_url($links['original']),
            $currentSet === 'original' ? 'current' : ''
        );

        $views['deepl_translated'] = sprintf(
            '<a href="%s" class="%s">Translated</a>',
            esc_url($links['translated']),
            $currentSet === 'translated' ? 'current' : ''
        );

        return $views;
    }

    public function filterListQuery(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        global $pagenow;

        if ($pagenow !== 'edit.php') {
            return;
        }

        $postType = (string) $query->get('post_type');

        if (! in_array($postType, ['post', 'page'], true)) {
            return;
        }

        $set = isset($_GET[self::FILTER_QUERY_VAR])
            ? sanitize_key((string) $_GET[self::FILTER_QUERY_VAR])
            : 'all';

        if (! in_array($set, ['all', 'original', 'translated'], true)) {
            return;
        }

        if ($set === 'all') {
            return;
        }

        $metaQuery = $query->get('meta_query');

        if (! is_array($metaQuery)) {
            $metaQuery = [];
        }

        if ($set === 'translated') {
            $metaQuery[] = [
                'key' => '_deepl_original_post_id',
                'compare' => 'EXISTS',
            ];
        }

        if ($set === 'original') {
            $metaQuery[] = [
                'key' => '_deepl_original_post_id',
                'compare' => 'NOT EXISTS',
            ];
        }

        if (count($metaQuery) > 1 && ! isset($metaQuery['relation'])) {
            $metaQuery['relation'] = 'AND';
        }

        $query->set('meta_query', $metaQuery);

        $orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : '';

        if ($orderby === 'deepl_language') {
            $query->set('meta_key', '_deepl_language_code');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function addLanguageColumn(array $columns): array
    {
        if (! $this->shouldShowLanguageColumn()) {
            unset($columns['deepl_language']);

            return $columns;
        }

        $columns['deepl_language'] = 'Language';

        return $columns;
    }

    public function renderLanguageColumn(string $columnName, int $postId): void
    {
        if ($columnName !== 'deepl_language') {
            return;
        }

        $languageCode = (string) get_post_meta($postId, '_deepl_language_code', true);

        if ($languageCode === '') {
            echo '&mdash;';

            return;
        }

        echo esc_html(strtoupper($languageCode));
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function addSortableLanguageColumn(array $columns): array
    {
        if (! $this->shouldShowLanguageColumn()) {
            unset($columns['deepl_language']);

            return $columns;
        }

        $columns['deepl_language'] = 'deepl_language';

        return $columns;
    }

    private function isTranslatedPost(int $postId): bool
    {
        $originalPostId = get_post_meta($postId, '_deepl_original_post_id', true);

        if ($originalPostId === '' || $originalPostId === null) {
            return false;
        }

        return (int) $originalPostId > 0;
    }

    private function shouldShowLanguageColumn(): bool
    {
        global $pagenow;

        if ($pagenow !== 'edit.php') {
            return false;
        }

        $set = isset($_GET[self::FILTER_QUERY_VAR])
            ? sanitize_key((string) $_GET[self::FILTER_QUERY_VAR])
            : 'all';

        if ($set !== 'translated') {
            return false;
        }

        $postType = isset($_GET['post_type'])
            ? sanitize_key((string) $_GET['post_type'])
            : 'post';

        return in_array($postType, ['post', 'page'], true);
    }
}
