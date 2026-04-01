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
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_filter('request', [$this, 'resolveRequest']);
        add_filter('post_link', [$this, 'filterPostLink'], 10, 3);
        add_filter('page_link', [$this, 'filterPageLink'], 10, 3);
        add_filter('nav_menu_link_attributes', [$this, 'filterNavMenuLinkAttributes'], 10, 4);
    }

    public function registerRewriteRules(): void
    {
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([a-z]{2})');
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
        if (! isset($queryVars[self::QUERY_VAR], $queryVars['name'])) {
            return $queryVars;
        }

        $langCode = sanitize_key((string) $queryVars[self::QUERY_VAR]);
        $slug = sanitize_title((string) $queryVars['name']);

        if ($langCode === '' || $slug === '') {
            return $queryVars;
        }

        $translatedPostId = $this->postRelationRepository->getTranslatedPostIdBySlugAndLanguage($slug, $langCode);

        if ($translatedPostId === null) {
            return $queryVars;
        }

        unset($queryVars['name']);

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
}
