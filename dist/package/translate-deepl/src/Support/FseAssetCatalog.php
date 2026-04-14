<?php
declare(strict_types=1);

namespace TranslateDeepL\Support;

if (! defined('ABSPATH')) {
    exit;
}

final class FseAssetCatalog
{
    private const TEMPLATE_ASSET_PREFIX = 'theme-template';
    private const PATTERN_ASSET_PREFIX = 'theme-pattern';

    /**
     * @var array<string, bool>|null
     */
    private ?array $currentThemeTemplateKeys = null;

    /**
     * @var array<string, array{name:string, title:string, content:string, source:string, slug:string}>|null
     */
    private ?array $themePatterns = null;

    /**
     * @return array<int, array{id:?int, slug:string, title:string, type:string, source:string, asset_key:string}>
     */
    public function getAssetsForManagement(): array
    {
        $assets = [];
        $indexedTemplates = [];

        $query = new \WP_Query([
            'post_type' => ['wp_template', 'wp_template_part', 'wp_block'],
            'post_status' => ['publish', 'draft', 'auto-draft', 'private'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        while ($query->have_posts()) {
            $query->the_post();
            $post = get_post((int) get_the_ID());

            if (! $post instanceof \WP_Post || $this->isTranslatedPost((int) $post->ID)) {
                continue;
            }

            $type = (string) $post->post_type;

            if ($type !== 'wp_block' && ! $this->isCurrentThemeTemplatePost($post)) {
                continue;
            }

            $assetKey = (string) $post->post_name;

            if ($type !== 'wp_block') {
                $indexedTemplates[$type . ':' . (string) $post->post_name] = true;
            }

            $assets[] = [
                'id' => (int) $post->ID,
                'slug' => (string) $post->post_name,
                'title' => (string) get_the_title((int) $post->ID),
                'type' => $type,
                'source' => 'database',
                'asset_key' => $assetKey,
            ];
        }

        wp_reset_postdata();

        foreach ($this->getThemeTemplates() as $template) {
            $key = $template['type'] . ':' . $template['slug'];

            if (isset($indexedTemplates[$key])) {
                continue;
            }

            $assets[] = [
                'id' => null,
                'slug' => $template['slug'],
                'title' => $template['title'],
                'type' => $template['type'],
                'source' => 'theme',
                'asset_key' => $template['slug'],
            ];
        }

        foreach ($this->getThemePatterns() as $pattern) {
            $assets[] = [
                'id' => null,
                'slug' => $pattern['slug'],
                'title' => $pattern['title'],
                'type' => 'wp_block',
                'source' => 'theme_pattern',
                'asset_key' => $pattern['name'],
            ];
        }

        usort(
            $assets,
            static fn (array $left, array $right): int => strcmp($left['title'], $right['title'])
        );

        return $assets;
    }

    public function findSourceTemplatePostId(string $postType, string $slug): ?int
    {
        if (! in_array($postType, ['wp_template', 'wp_template_part'], true) || $slug === '') {
            return null;
        }

        $query = new \WP_Query([
            'post_type' => $postType,
            'name' => $slug,
            'post_status' => ['publish', 'draft', 'auto-draft', 'private'],
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);

        if (! is_array($query->posts)) {
            return null;
        }

        foreach ($query->posts as $postId) {
            $post = get_post((int) $postId);

            if (! $post instanceof \WP_Post || ! $this->isCurrentThemeTemplatePost($post) || $this->isTranslatedPost((int) $post->ID)) {
                continue;
            }

            return (int) $post->ID;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findThemePattern(string $patternName): ?array
    {
        $patterns = $this->getThemePatterns();

        return $patterns[$patternName] ?? null;
    }

    /**
     * @return array<int, array{asset_key:string, asset_type:string, source_kind:string, title:string, content:string, metadata:array<string, mixed>}>
     */
    public function getThemeTemplatesForRegistry(): array
    {
        if (! function_exists('get_block_templates')) {
            return [];
        }

        $assets = [];
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
            $content = isset($template->content) ? (string) $template->content : '';

            if ($slug === '' || $type === '' || $source !== 'theme' || $content === '') {
                continue;
            }

            $title = isset($template->title) && is_string($template->title)
                ? $template->title
                : $slug;
            $metadata = [];

            if (isset($template->area) && is_string($template->area) && $template->area !== '') {
                $metadata['area'] = $template->area;
            }

            $assets[] = [
                'asset_key' => $this->buildThemeTemplateAssetKey($type, $slug),
                'asset_type' => $type,
                'source_kind' => 'theme_file',
                'title' => $title,
                'content' => $content,
                'metadata' => $metadata,
            ];
        }

        return $assets;
    }

    /**
     * @return array<int, array{asset_key:string, asset_type:string, source_kind:string, title:string, content:string, metadata:array<string, mixed>}>
     */
    public function getThemePatternsForRegistry(): array
    {
        $assets = [];

        foreach ($this->getThemePatterns() as $pattern) {
            $assets[] = [
                'asset_key' => $this->buildThemePatternAssetKey($pattern['name']),
                'asset_type' => 'wp_block',
                'source_kind' => 'theme_pattern',
                'title' => $pattern['title'],
                'content' => $pattern['content'],
                'metadata' => [
                    'pattern_name' => $pattern['name'],
                    'pattern_slug' => $pattern['slug'],
                ],
            ];
        }

        return $assets;
    }

    public function buildThemeTemplateAssetKey(string $templateType, string $slug): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            self::TEMPLATE_ASSET_PREFIX,
            sanitize_key(get_stylesheet()),
            sanitize_key($templateType),
            sanitize_key($slug)
        );
    }

    public function buildThemePatternAssetKey(string $patternName): string
    {
        return sprintf(
            '%s:%s:%s',
            self::PATTERN_ASSET_PREFIX,
            sanitize_key(get_stylesheet()),
            sanitize_key(str_replace('/', '__', $patternName))
        );
    }

    /**
     * @return array<string, bool>
     */
    public function getCurrentThemeTemplateKeys(): array
    {
        if ($this->currentThemeTemplateKeys !== null) {
            return $this->currentThemeTemplateKeys;
        }

        $keys = [];

        foreach ($this->getThemeTemplates() as $template) {
            $keys[$template['type'] . ':' . $template['slug']] = true;
        }

        $this->currentThemeTemplateKeys = $keys;

        return $keys;
    }

    public function isCurrentThemeTemplatePost(\WP_Post $post): bool
    {
        $themeTerms = wp_get_object_terms((int) $post->ID, 'wp_theme', ['fields' => 'slugs']);

        if (is_wp_error($themeTerms)) {
            return false;
        }

        if (is_array($themeTerms) && $themeTerms !== []) {
            return in_array(get_stylesheet(), $themeTerms, true);
        }

        return $this->isCurrentThemeTemplateSlug((string) $post->post_type, (string) $post->post_name);
    }

    public function isCurrentThemeTemplateSlug(string $templateType, string $templateSlug): bool
    {
        if ($templateType === '' || $templateSlug === '') {
            return false;
        }

        return isset($this->getCurrentThemeTemplateKeys()[$templateType . ':' . $templateSlug]);
    }

    /**
     * @return array<int, array{slug:string, title:string, type:string}>
     */
    private function getThemeTemplates(): array
    {
        if (! function_exists('get_block_templates')) {
            return [];
        }

        $results = [];
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

            $title = isset($template->title) && is_string($template->title)
                ? $template->title
                : $slug;

            $results[] = [
                'slug' => $slug,
                'title' => $title,
                'type' => $type,
            ];
        }

        return $results;
    }

    /**
     * @return array<string, array{name:string, title:string, content:string, source:string, slug:string}>
     */
    private function getThemePatterns(): array
    {
        if ($this->themePatterns !== null) {
            return $this->themePatterns;
        }

        $patterns = [];

        if (! class_exists('WP_Block_Patterns_Registry')) {
            $this->themePatterns = $patterns;

            return $patterns;
        }

        $registry = \WP_Block_Patterns_Registry::get_instance();
        $registeredPatterns = $registry->get_all_registered();
        $validNamespaces = array_unique([
            sanitize_title(get_stylesheet()),
            sanitize_title(get_template()),
        ]);

        foreach ($registeredPatterns as $pattern) {
            if (! is_array($pattern)) {
                continue;
            }

            $name = isset($pattern['name']) ? (string) $pattern['name'] : '';
            $title = isset($pattern['title']) ? (string) $pattern['title'] : '';
            $content = isset($pattern['content']) ? (string) $pattern['content'] : '';

            if ($name === '' || $title === '' || $content === '') {
                continue;
            }

            $segments = explode('/', $name, 2);
            $namespace = $segments[0] ?? '';
            $slug = $segments[1] ?? $name;

            if (! in_array($namespace, $validNamespaces, true)) {
                continue;
            }

            $patterns[$name] = [
                'name' => $name,
                'slug' => $slug,
                'title' => $title,
                'content' => $content,
                'source' => 'theme_pattern',
            ];
        }

        $this->themePatterns = $patterns;

        return $patterns;
    }

    private function isTranslatedPost(int $postId): bool
    {
        $originalPostId = get_post_meta($postId, '_deepl_original_post_id', true);

        if ($originalPostId === '' || $originalPostId === null) {
            return false;
        }

        return (int) $originalPostId > 0;
    }
}