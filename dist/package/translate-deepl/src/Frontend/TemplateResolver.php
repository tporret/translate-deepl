<?php
declare(strict_types=1);

namespace TranslateDeepL\Frontend;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Support\FseAssetCatalog;

final class TemplateResolver
{
    private const CACHE_GROUP = 'translate-deepl';
    private const CACHE_MISS = '__translate_deepl_cache_miss__';
    private const QUERY_VAR = 'deepl_lang';

    public function __construct(
        private readonly PostRelationRepository $postRelationRepository,
        private readonly FseAssetCatalog $fseAssetCatalog
    ) {
    }

    public function registerHooks(): void
    {
        add_filter('render_block_core/template-part', [$this, 'resolveTemplatePart'], 10, 2);
        add_filter('get_block_templates', [$this, 'resolveTemplates'], 10, 3);
        add_filter('get_block_template', [$this, 'resolveTemplate'], 10, 3);
    }

    /**
     * @param array<int, mixed> $templates
     * @param array<string, mixed> $query
     *
     * @return array<int, mixed>
     */
    public function resolveTemplates(array $templates, array $query, string $templateType): array
    {
        $langCode = $this->getCurrentLanguage();

        if ($langCode === null || is_admin() || $templateType !== 'wp_template') {
            return $templates;
        }

        foreach ($templates as $index => $template) {
            if (! $template instanceof \WP_Block_Template) {
                continue;
            }

            $templates[$index] = $this->applyTranslatedTemplate($template, $langCode, $templateType);
        }

        return $templates;
    }

    public function resolveTemplate(?\WP_Block_Template $template, string $id, string $templateType): ?\WP_Block_Template
    {
        $langCode = $this->getCurrentLanguage();

        if ($template === null || $langCode === null || is_admin() || $templateType !== 'wp_template') {
            return $template;
        }

        return $this->applyTranslatedTemplate($template, $langCode, $templateType);
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

        $cacheKey = $this->buildTemplatePartCacheKey($slug, $lang);
        $cachedValue = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($cachedValue === self::CACHE_MISS) {
            return $blockContent;
        }

        if (is_string($cachedValue)) {
            return $cachedValue;
        }

        $sourceCacheKey = $this->buildTemplatePartSourceCacheKey($slug);
        $originalPostId = wp_cache_get($sourceCacheKey, self::CACHE_GROUP);

        if (! is_int($originalPostId)) {
            $originalPostId = $this->fseAssetCatalog->findSourceTemplatePostId('wp_template_part', $slug);

            if ($originalPostId === null) {
                wp_cache_set($cacheKey, self::CACHE_MISS, self::CACHE_GROUP);

                return $blockContent;
            }

            wp_cache_set($sourceCacheKey, $originalPostId, self::CACHE_GROUP);
        }

        $translatedPostIdCacheKey = $this->buildTemplatePartTranslatedIdCacheKey($originalPostId, $lang);
        $translatedPostId = wp_cache_get($translatedPostIdCacheKey, self::CACHE_GROUP);

        if (! is_int($translatedPostId)) {
            $translatedPostId = $this->postRelationRepository->getTranslatedPostId($originalPostId, $lang);

            if ($translatedPostId === null) {
                wp_cache_set($cacheKey, self::CACHE_MISS, self::CACHE_GROUP);
                wp_cache_set($translatedPostIdCacheKey, 0, self::CACHE_GROUP);

                return $blockContent;
            }

            wp_cache_set($translatedPostIdCacheKey, $translatedPostId, self::CACHE_GROUP);
        }

        if ($translatedPostId <= 0) {
            wp_cache_set($cacheKey, self::CACHE_MISS, self::CACHE_GROUP);

            return $blockContent;
        }

        $translatedPost = get_post($translatedPostId);

        if (! $translatedPost instanceof \WP_Post) {
            wp_cache_set($cacheKey, self::CACHE_MISS, self::CACHE_GROUP);

            return $blockContent;
        }

        $resolvedContent = (string) do_blocks((string) $translatedPost->post_content);
        wp_cache_set($cacheKey, $resolvedContent, self::CACHE_GROUP);

        return $resolvedContent;
    }

    private function applyTranslatedTemplate(\WP_Block_Template $template, string $langCode, string $templateType): \WP_Block_Template
    {
        $slug = isset($template->slug) && is_string($template->slug)
            ? $template->slug
            : '';

        if ($slug === '') {
            return $template;
        }

        $sourcePostId = $this->fseAssetCatalog->findSourceTemplatePostId($templateType, $slug);

        if ($sourcePostId === null) {
            return $template;
        }

        $translatedPostId = $this->postRelationRepository->getTranslatedPostId($sourcePostId, $langCode);

        if ($translatedPostId === null) {
            return $template;
        }

        $translatedPost = get_post($translatedPostId);

        if (! $translatedPost instanceof \WP_Post || $translatedPost->post_type !== 'wp_template') {
            return $template;
        }

        $resolvedTemplate = clone $template;
        $content = (string) $translatedPost->post_content;

        if (function_exists('apply_block_hooks_to_content')) {
            $content = apply_block_hooks_to_content(
                $content,
                $resolvedTemplate,
                'insert_hooked_blocks_and_set_ignored_hooked_blocks_metadata'
            );
        }

        $resolvedTemplate->content = $content;

        if (isset($resolvedTemplate->wp_id)) {
            $resolvedTemplate->wp_id = (int) $translatedPost->ID;
        }

        return $resolvedTemplate;
    }

    private function getCurrentLanguage(): ?string
    {
        $langCode = get_query_var(self::QUERY_VAR);

        if (! is_string($langCode) || $langCode === '') {
            return null;
        }

        return sanitize_key($langCode);
    }

    private function buildTemplatePartCacheKey(string $slug, string $langCode): string
    {
        return sprintf(
            'deepl_fse_tpl_part_%s_%s_%s_%s',
            sanitize_key(get_stylesheet()),
            sanitize_key($slug),
            sanitize_key($langCode),
            $this->getPostsLastChanged()
        );
    }

    private function buildTemplatePartSourceCacheKey(string $slug): string
    {
        return sprintf(
            'deepl_fse_tpl_part_source_%s_%s_%s',
            sanitize_key(get_stylesheet()),
            sanitize_key($slug),
            $this->getPostsLastChanged()
        );
    }

    private function buildTemplatePartTranslatedIdCacheKey(int $originalPostId, string $langCode): string
    {
        return sprintf(
            'deepl_fse_tpl_part_rel_%d_%s_%s',
            $originalPostId,
            sanitize_key($langCode),
            $this->getPostsLastChanged()
        );
    }

    private function getPostsLastChanged(): string
    {
        if (function_exists('wp_cache_get_last_changed')) {
            return (string) wp_cache_get_last_changed('posts');
        }

        return '1';
    }
}
