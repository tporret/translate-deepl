<?php
declare(strict_types=1);

namespace TranslateDeepL\Routing;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\PostRelationRepository;

final class LanguageRouter
{
    private const QUERY_VAR = 'deepl_lang';

    public function __construct(private readonly PostRelationRepository $postRelationRepository)
    {
    }

    public function registerHooks(): void
    {
        add_action('init', [$this, 'registerRewriteRules']);
        add_action('parse_request', [$this, 'parseLanguageRequest']);
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_filter('request', [$this, 'resolveRequest']);
        add_filter('post_link', [$this, 'filterPostLink'], 10, 3);
        add_filter('page_link', [$this, 'filterPageLink'], 10, 3);
        add_filter('get_pages', [$this, 'filterPagesByLanguage'], 10, 2);
        add_filter('wp_nav_menu_objects', [$this, 'filterNavMenuObjects'], 10, 2);
        add_filter('nav_menu_link_attributes', [$this, 'filterNavMenuLinkAttributes'], 10, 4);
        add_action('pre_get_posts', [$this, 'filterMainQuery']);
        add_filter('get_next_post_where', [$this, 'filterPostNavigationWhere'], 10, 5);
        add_filter('get_previous_post_where', [$this, 'filterPostNavigationWhere'], 10, 5);
    }

    public function registerRewriteRules(): void
    {
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([a-z]{2})');
        add_rewrite_rule(
            '^([a-z]{2})/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^([a-z]{2})/([^/]+)/?$',
            'index.php?name=$matches[2]&' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * @param array<int, string> $queryVars
     *
     * @return array<int, string>
     */
    public function registerQueryVars(array $queryVars): array
    {
        $queryVars[] = self::QUERY_VAR;

        return $queryVars;
    }

    /**
     * @param array<string, mixed> $queryVars
     *
     * @return array<string, mixed>
     */
    public function resolveRequest(array $queryVars): array
    {
        if (
            isset($queryVars[self::QUERY_VAR])
            && ! isset($queryVars['name'], $queryVars['pagename'], $queryVars['p'], $queryVars['page_id'])
        ) {
            $langCode = sanitize_key((string) $queryVars[self::QUERY_VAR]);

            if ($langCode !== '') {
                $frontPageId = (int) get_option('page_on_front', 0);

                if ($frontPageId > 0) {
                    $translatedFrontPageId = $this->postRelationRepository->getTranslatedPostId($frontPageId, $langCode);
                    $resolvedPageId = $translatedFrontPageId ?? $frontPageId;

                    unset($queryVars['name'], $queryVars['p']);
                    $queryVars['page_id'] = $resolvedPageId;
                }
            }

            return $queryVars;
        }

        if (! isset($queryVars[self::QUERY_VAR])) {
            return $queryVars;
        }

        $langCode = sanitize_key((string) $queryVars[self::QUERY_VAR]);
        $slug = $this->resolveRequestedSlug($queryVars, $langCode);

        if ($langCode === '' || $slug === '') {
            return $queryVars;
        }

        $translatedPostId = $this->postRelationRepository->getTranslatedPostIdBySlugAndLanguage($slug, $langCode);

        if ($translatedPostId === null) {
            return $queryVars;
        }

        unset($queryVars['name'], $queryVars['pagename']);

        $translatedPost = get_post($translatedPostId);

        if (! $translatedPost instanceof \WP_Post) {
            return $queryVars;
        }

        if ($translatedPost->post_type === 'page') {
            $queryVars['page_id'] = (int) $translatedPost->ID;
            unset($queryVars['p']);

            return $queryVars;
        }

        $queryVars['p'] = (int) $translatedPost->ID;
        unset($queryVars['page_id']);

        return $queryVars;
    }

    /**
     * @param array<string, mixed> $queryVars
     */
    private function resolveRequestedSlug(array $queryVars, string $langCode): string
    {
        $name = isset($queryVars['name']) ? (string) $queryVars['name'] : '';

        if ($name !== '') {
            return sanitize_title($name);
        }

        $pagename = isset($queryVars['pagename']) ? (string) $queryVars['pagename'] : '';

        if ($pagename === '') {
            return '';
        }

        $pagename = trim($pagename, '/');

        if (str_starts_with($pagename, $langCode . '/')) {
            $pagename = substr($pagename, strlen($langCode) + 1);
        }

        if ($pagename === '') {
            return '';
        }

        $segments = explode('/', $pagename);
        $lastSegment = end($segments);

        return is_string($lastSegment) ? sanitize_title($lastSegment) : '';
    }

    public function filterPostLink(string $permalink, \WP_Post $post, bool $leaveName): string
    {
        $langCode = $this->postRelationRepository->getLanguageCodeByTranslatedPostId((int) $post->ID);

        if ($langCode === null) {
            return $permalink;
        }

        return $this->buildLanguageUrl($langCode, (string) $post->post_name);
    }

    public function filterPageLink(string $link, int $postId, bool $sample): string
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            return $link;
        }

        $langCode = $this->postRelationRepository->getLanguageCodeByTranslatedPostId((int) $post->ID);

        if ($langCode === null) {
            return $link;
        }

        return $this->buildLanguageUrl($langCode, (string) $post->post_name);
    }

    /**
     * @param array<string, string> $attributes
     * @param object $menuItem
     * @param object $args
     *
     * @return array<string, string>
     */
    public function filterNavMenuLinkAttributes(array $attributes, object $menuItem, object $args, int $depth): array
    {
        $langCode = $this->getCurrentLanguage();

        if ($langCode === null) {
            return $attributes;
        }

        $objectId = isset($menuItem->object_id) ? (int) $menuItem->object_id : 0;

        if ($objectId <= 0) {
            return $attributes;
        }

        $translatedPostId = $this->postRelationRepository->getTranslatedPostId($objectId, $langCode);

        if ($translatedPostId === null) {
            return $attributes;
        }

        $translatedPost = get_post($translatedPostId);

        if (! $translatedPost instanceof \WP_Post) {
            return $attributes;
        }

        $attributes['href'] = $this->buildLanguageUrl($langCode, (string) $translatedPost->post_name);

        return $attributes;
    }

    /**
     * @param array<int, object> $items
     * @param object $args
     *
     * @return array<int, object>
     */
    public function filterNavMenuObjects(array $items, object $args): array
    {
        $currentLanguage = $this->getCurrentLanguage();
        $menuObjectIds = [];

        foreach ($items as $item) {
            $objectId = isset($item->object_id) ? (int) $item->object_id : 0;

            if ($objectId > 0) {
                $menuObjectIds[$objectId] = true;
            }
        }

        $filteredItems = [];

        foreach ($items as $item) {
            $objectId = isset($item->object_id) ? (int) $item->object_id : 0;

            if ($objectId <= 0) {
                $filteredItems[] = $item;
                continue;
            }

            $originalPostId = $this->postRelationRepository->getOriginalPostId($objectId);

            if ($currentLanguage === null) {
                // On default-language routes, remove translated target items from navigation.
                if ($originalPostId !== null) {
                    continue;
                }

                $filteredItems[] = $item;
                continue;
            }

            if ($originalPostId !== null) {
                $translatedItemLang = $this->postRelationRepository->getLanguageCodeByTranslatedPostId($objectId);

                // Keep only translated items that match the active language route.
                if ($translatedItemLang !== $currentLanguage) {
                    continue;
                }

                $filteredItems[] = $item;
                continue;
            }

            $translatedForCurrentLanguage = $this->postRelationRepository->getTranslatedPostId($objectId, $currentLanguage);

            // If both original and translated are present in menu, hide original on language routes.
            if ($translatedForCurrentLanguage !== null && isset($menuObjectIds[$translatedForCurrentLanguage])) {
                continue;
            }

            $filteredItems[] = $item;
        }

        return $filteredItems;
    }

    /**
     * @param array<int, \WP_Post> $pages
     * @param array<string, mixed> $parsedArgs
     *
     * @return array<int, \WP_Post>
     */
    public function filterPagesByLanguage(array $pages, array $parsedArgs): array
    {
        $currentLanguage = $this->getCurrentLanguage();
        $filtered = [];

        foreach ($pages as $page) {
            if (! $page instanceof \WP_Post) {
                continue;
            }

            $pageId = (int) $page->ID;
            $originalPostId = $this->postRelationRepository->getOriginalPostId($pageId);

            if ($currentLanguage === null) {
                // Default language: hide translated targets from page-list navigation.
                if ($originalPostId !== null) {
                    continue;
                }

                $filtered[] = $page;
                continue;
            }

            if ($originalPostId !== null) {
                $translatedPageLang = $this->postRelationRepository->getLanguageCodeByTranslatedPostId($pageId);

                // On /{lang}/ routes, keep only translated pages for that language.
                if ($translatedPageLang !== $currentLanguage) {
                    continue;
                }

                $filtered[] = $page;
                continue;
            }

            $translatedForCurrentLanguage = $this->postRelationRepository->getTranslatedPostId($pageId, $currentLanguage);

            // Hide original when a language-specific translated page exists.
            if ($translatedForCurrentLanguage !== null) {
                continue;
            }

            $filtered[] = $page;
        }

        return $filtered;
    }

    public function filterMainQuery(\WP_Query $query): void
    {
        // Only filter frontend queries, not admin or other contexts
        if (is_admin()) {
            return;
        }

        $currentLanguage = $this->getCurrentLanguage();

        // If on default language, exclude all translated posts
        if ($currentLanguage === null) {
            $translatedPostIds = $this->postRelationRepository->getAllTranslatedPostIds();
            if (! empty($translatedPostIds)) {
                $query->query_vars['post__not_in'] = $translatedPostIds;
            }
            return;
        }

        // On language routes, only show posts translated for that specific language
        $translatedPostIds = $this->postRelationRepository->getAllTranslatedPostIds();

        if (empty($translatedPostIds)) {
            // No translations at all, show nothing on language routes
            $query->query_vars['post__in'] = [];
            return;
        }

        // Get only the posts that are translations for the current language
        $postsForCurrentLanguage = [];

        foreach ($translatedPostIds as $translatedId) {
            $translatedLang = $this->postRelationRepository->getLanguageCodeByTranslatedPostId($translatedId);

            // Only include translations for the current language
            if ($translatedLang === $currentLanguage) {
                $postsForCurrentLanguage[] = $translatedId;
            }
        }

        // Restrict query to only posts translated for this language
        if (! empty($postsForCurrentLanguage)) {
            $query->query_vars['post__in'] = $postsForCurrentLanguage;
        } else {
            // No translations for this language, show nothing
            $query->query_vars['post__in'] = [];
        }
    }

    public function filterPostNavigationWhere(string $where, bool $inSameTerm, string $excludedTerms, string $taxonomy, \WP_Post $post): string
    {
        $currentLanguage = $this->getCurrentLanguage();
        $translatedPostIds = $this->postRelationRepository->getAllTranslatedPostIds();

        if (empty($translatedPostIds)) {
            return $where;
        }

        // If on default language, exclude all translated posts
        if ($currentLanguage === null) {
            $excludeList = implode(',', array_map('intval', $translatedPostIds));
            return $where . " AND p.ID NOT IN ({$excludeList})";
        }

        // On language routes, only show posts translated for that specific language
        $postsForCurrentLanguage = [];

        foreach ($translatedPostIds as $translatedId) {
            $translatedLang = $this->postRelationRepository->getLanguageCodeByTranslatedPostId($translatedId);

            // Only include translations for the current language
            if ($translatedLang === $currentLanguage) {
                $postsForCurrentLanguage[] = $translatedId;
            }
        }

        if (empty($postsForCurrentLanguage)) {
            // No translations for this language, exclude everything from navigation
            return $where . " AND p.ID = 0";
        }

        // Restrict to only posts in the current language
        $includeList = implode(',', array_map('intval', $postsForCurrentLanguage));
        return $where . " AND p.ID IN ({$includeList})";
    }

    private function getCurrentLanguage(): ?string
    {
        $langCode = get_query_var(self::QUERY_VAR);

        if (! is_string($langCode) || $langCode === '') {
            return null;
        }

        return sanitize_key($langCode);
    }

    private function buildLanguageUrl(string $langCode, string $slug): string
    {
        return home_url('/' . rawurlencode($langCode) . '/' . rawurlencode($slug) . '/');
    }

    public function parseLanguageRequest(\WP $wp): void
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if ($requestUri === '') {
            return;
        }

        $path = (string) wp_parse_url($requestUri, PHP_URL_PATH);
        $homePath = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

        if ($homePath !== '' && $homePath !== '/') {
            $normalizedHomePath = rtrim($homePath, '/');

            if (str_starts_with($path, $normalizedHomePath)) {
                $path = (string) substr($path, strlen($normalizedHomePath));
            }
        }

        $path = trim($path, '/');

        if ($path === '') {
            return;
        }

        if (preg_match('/^([a-z]{2})$/', $path, $homeMatches) === 1) {
            $langCode = sanitize_key($homeMatches[1]);
            $wp->query_vars[self::QUERY_VAR] = $langCode;

            $frontPageId = (int) get_option('page_on_front', 0);

            if ($frontPageId > 0) {
                $translatedFrontPageId = $this->postRelationRepository->getTranslatedPostId($frontPageId, $langCode);
                $resolvedPageId = $translatedFrontPageId ?? $frontPageId;
                $wp->query_vars['page_id'] = (int) $resolvedPageId;
            }

            unset($wp->query_vars['name'], $wp->query_vars['pagename'], $wp->query_vars['p']);

            return;
        }

        if (preg_match('/^([a-z]{2})\/([^\/]+)$/', $path, $matches) !== 1) {
            return;
        }

        $langCode = sanitize_key($matches[1]);
        $slug = sanitize_title($matches[2]);

        if ($langCode === '' || $slug === '') {
            return;
        }

        $translatedPostId = $this->postRelationRepository->getTranslatedPostIdBySlugAndLanguage($slug, $langCode);

        if ($translatedPostId === null) {
            return;
        }

        $translatedPost = get_post($translatedPostId);

        if (! $translatedPost instanceof \WP_Post) {
            return;
        }

        $wp->query_vars[self::QUERY_VAR] = $langCode;
        unset($wp->query_vars['name'], $wp->query_vars['pagename']);

        if ($translatedPost->post_type === 'page') {
            $wp->query_vars['page_id'] = (int) $translatedPostId;
            unset($wp->query_vars['p']);

            return;
        }

        $wp->query_vars['p'] = (int) $translatedPostId;
        unset($wp->query_vars['page_id']);
    }
}
