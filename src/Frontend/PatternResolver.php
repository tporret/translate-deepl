<?php
declare(strict_types=1);

namespace TranslateDeepL\Frontend;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\AssetRegistryRepository;
use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Support\FseAssetCatalog;

final class PatternResolver
{
    private const QUERY_VAR = 'deepl_lang';

    public function __construct(
        private readonly AssetRegistryRepository $assetRegistryRepository,
        private readonly PostRelationRepository $postRelationRepository,
        private readonly FseAssetCatalog $fseAssetCatalog
    ) {
    }

    public function registerHooks(): void
    {
        add_filter('render_block_core/block', [$this, 'resolvePatternBlock'], 10, 2);
        add_filter('render_block_core/pattern', [$this, 'resolveThemePattern'], 10, 2);
    }

    /**
     * @param array<string, mixed> $block
     */
    public function resolvePatternBlock(string $blockContent, array $block): string
    {
        $langCode = $this->getCurrentLanguage();

        if ($langCode === null) {
            return $blockContent;
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs'])
            ? $block['attrs']
            : [];

        $referencedPostId = isset($attrs['ref']) ? (int) $attrs['ref'] : 0;

        if ($referencedPostId <= 0) {
            return $blockContent;
        }

        $translatedPostId = $this->postRelationRepository->getTranslatedPostId($referencedPostId, $langCode);

        if ($translatedPostId === null) {
            return $blockContent;
        }

        $translatedPattern = get_post($translatedPostId);

        if (! $translatedPattern instanceof \WP_Post || $translatedPattern->post_type !== 'wp_block') {
            return $blockContent;
        }

        return (string) do_blocks((string) $translatedPattern->post_content);
    }

    /**
     * @param array<string, mixed> $block
     */
    public function resolveThemePattern(string $blockContent, array $block): string
    {
        $langCode = $this->getCurrentLanguage();

        if ($langCode === null) {
            return $blockContent;
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs'])
            ? $block['attrs']
            : [];

        $patternName = isset($attrs['slug']) ? (string) $attrs['slug'] : '';

        if ($patternName === '') {
            return $blockContent;
        }

        $assetKey = $this->fseAssetCatalog->buildThemePatternAssetKey($patternName);
        $sourceAsset = $this->assetRegistryRepository->getSourceAsset($assetKey);

        if ($sourceAsset === null) {
            return $blockContent;
        }

        $translation = $this->assetRegistryRepository->getPublishedTranslation($assetKey, $langCode);
        $sourceHash = isset($sourceAsset['content_hash']) ? (string) $sourceAsset['content_hash'] : '';
        $translationHash = is_array($translation) && isset($translation['source_content_hash'])
            ? (string) $translation['source_content_hash']
            : '';
        $translatedContent = is_array($translation) && isset($translation['translated_content'])
            ? (string) $translation['translated_content']
            : '';

        if ($translatedContent === '' || $sourceHash === '' || ! hash_equals($sourceHash, $translationHash)) {
            return $blockContent;
        }

        return (string) do_blocks($translatedContent);
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