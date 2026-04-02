<?php
declare(strict_types=1);

namespace TranslateDeepL\Service;

use TranslateDeepL\Api\DeepLApiClient;
use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Repository\TranslationMemoryRepository;

final class PostTranslator
{
    public function __construct(
        private readonly BlockProcessor $blockProcessor,
        private readonly DeepLApiClient $apiClient,
        private readonly TranslationMemoryRepository $translationMemoryRepository,
        private readonly PostRelationRepository $postRelationRepository
    ) {
    }

    public function translatePost(int $originalPostId, string $targetLang, string $sourceLang = 'en'): bool
    {
        $existingTranslatedPostId = $this->postRelationRepository->getTranslatedPostId($originalPostId, $targetLang);

        $post = get_post($originalPostId);

        if (! $post instanceof \WP_Post) {
            return false;
        }

        $sourceContent = (string) $post->post_content;

        if (in_array((string) $post->post_type, ['wp_template', 'wp_template_part'], true)) {
            $sourceContent = $this->expandPatternsInContent($sourceContent);
        }

        $sourceStrings = $this->blockProcessor->extractTranslatableStrings($sourceContent);
        $translatedStrings = $this->translateStringsWithMemory($sourceStrings, $targetLang, $sourceLang);
        $translatedContent = $this->blockProcessor->reassembleBlocks($sourceContent, $translatedStrings);

        $translatedTitle = $this->translateTitle((string) $post->post_title, $targetLang, $sourceLang);

        if ($existingTranslatedPostId !== null) {
            $updatedPostId = wp_update_post(
                [
                    'ID' => $existingTranslatedPostId,
                    'post_content' => $translatedContent,
                    'post_title' => $translatedTitle,
                ],
                true
            );

            if (is_wp_error($updatedPostId)) {
                return false;
            }

            if (! is_int($updatedPostId) || $updatedPostId <= 0) {
                return false;
            }

            update_post_meta($existingTranslatedPostId, '_deepl_language_code', $targetLang);
            update_post_meta($existingTranslatedPostId, '_deepl_original_post_id', $originalPostId);

            $this->inheritFseTerms($post, $existingTranslatedPostId);

            return true;
        }

        $translatedPostId = wp_insert_post(
            [
                'post_title' => $translatedTitle,
                'post_content' => $translatedContent,
                'post_type' => (string) $post->post_type,
                'post_status' => (string) $post->post_status,
            ],
            true
        );

        if (is_wp_error($translatedPostId)) {
            return false;
        }

        if (! is_int($translatedPostId) || $translatedPostId <= 0) {
            return false;
        }

        update_post_meta($translatedPostId, '_deepl_language_code', $targetLang);
        update_post_meta($translatedPostId, '_deepl_original_post_id', $originalPostId);

        $this->inheritFseTerms($post, $translatedPostId);

        return $this->postRelationRepository->saveRelation($originalPostId, $translatedPostId, $targetLang);
    }

    /**
     * @param array<int, string> $sourceStrings
     *
     * @return array<int, string>
     */
    private function translateStringsWithMemory(array $sourceStrings, string $targetLang, string $sourceLang): array
    {
        $translated = [];
        $misses = [];

        foreach ($sourceStrings as $index => $sourceText) {
            $hash = hash('sha256', $sourceText);
            $cached = $this->translationMemoryRepository->getTranslation($hash, $targetLang);

            if ($cached !== null) {
                $translated[$index] = $cached;
                continue;
            }

            $misses[] = [
                'index' => $index,
                'hash' => $hash,
                'text' => $sourceText,
            ];
        }

        if ($misses !== []) {
            $newTranslations = $this->translateMissingStrings($misses, $targetLang, $sourceLang);

            foreach ($newTranslations as $item) {
                $translated[$item['index']] = $item['translated_text'];
            }
        }

        ksort($translated);

        if (count($translated) !== count($sourceStrings)) {
            throw new \RuntimeException('Unable to produce a complete translated string set.');
        }

        return array_values($translated);
    }

    /**
     * @param array<int, array{index:int, hash:string, text:string}> $misses
     *
     * @return array<int, array{index:int, translated_text:string}>
     */
    private function translateMissingStrings(array $misses, string $targetLang, string $sourceLang): array
    {
        $payload = array_map(
            static fn (array $item): string => $item['text'],
            $misses
        );

        $translatedPayload = $this->apiClient->translate($payload, $targetLang, $sourceLang);

        if (count($translatedPayload) !== count($misses)) {
            throw new \RuntimeException('DeepL response count does not match request count.');
        }

        $results = [];

        foreach ($translatedPayload as $position => $translatedText) {
            $miss = $misses[$position];
            $saved = $this->translationMemoryRepository->saveTranslation(
                $miss['hash'],
                $sourceLang,
                $targetLang,
                $translatedText
            );

            if (! $saved) {
                throw new \RuntimeException('Failed to save translated string to translation memory.');
            }

            $results[] = [
                'index' => $miss['index'],
                'translated_text' => $translatedText,
            ];
        }

        return $results;
    }

    private function translateTitle(string $title, string $targetLang, string $sourceLang): string
    {
        $translated = $this->apiClient->translate([$title], $targetLang, $sourceLang);

        if (! isset($translated[0]) || ! is_string($translated[0])) {
            throw new \RuntimeException('DeepL did not return a translated title.');
        }

        return $translated[0];
    }

    private function inheritFseTerms(\WP_Post $sourcePost, int $targetPostId): void
    {
        if (! in_array((string) $sourcePost->post_type, ['wp_template', 'wp_template_part'], true)) {
            return;
        }

        if (taxonomy_exists('wp_theme')) {
            $themeTerms = wp_get_object_terms((int) $sourcePost->ID, 'wp_theme', ['fields' => 'slugs']);

            if (! is_wp_error($themeTerms) && is_array($themeTerms) && $themeTerms !== []) {
                wp_set_object_terms($targetPostId, $themeTerms, 'wp_theme', false);
            }
        }

        if (
            (string) $sourcePost->post_type === 'wp_template_part'
            && taxonomy_exists('wp_template_part_area')
        ) {
            $areaTerms = wp_get_object_terms((int) $sourcePost->ID, 'wp_template_part_area', ['fields' => 'slugs']);

            if (! is_wp_error($areaTerms) && is_array($areaTerms) && $areaTerms !== []) {
                wp_set_object_terms($targetPostId, $areaTerms, 'wp_template_part_area', false);
            }
        }
    }

    private function expandPatternsInContent(string $content): string
    {
        if (! class_exists('WP_Block_Patterns_Registry')) {
            return $content;
        }

        $blocks = parse_blocks($content);
        $expandedBlocks = $this->expandPatternBlocks($blocks);

        return serialize_blocks($expandedBlocks);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     *
     * @return array<int, array<string, mixed>>
     */
    private function expandPatternBlocks(array $blocks): array
    {
        $expanded = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $blockName = isset($block['blockName']) && is_string($block['blockName'])
                ? $block['blockName']
                : '';

            if ($blockName === 'core/pattern') {
                $patternBlocks = $this->resolvePatternBlocks($block);

                if ($patternBlocks !== []) {
                    foreach ($patternBlocks as $patternBlock) {
                        $expanded[] = $patternBlock;
                    }

                    continue;
                }
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->expandPatternBlocks($block['innerBlocks']);
            }

            $expanded[] = $block;
        }

        return $expanded;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolvePatternBlocks(array $block): array
    {
        if (! isset($block['attrs']) || ! is_array($block['attrs'])) {
            return [];
        }

        $slug = isset($block['attrs']['slug']) ? (string) $block['attrs']['slug'] : '';

        if ($slug === '') {
            return [];
        }

        $registry = \WP_Block_Patterns_Registry::get_instance();
        $pattern = $registry->get_registered($slug);

        if (! is_array($pattern) || ! isset($pattern['content']) || ! is_string($pattern['content'])) {
            return [];
        }

        $patternBlocks = parse_blocks($pattern['content']);

        return $this->expandPatternBlocks($patternBlocks);
    }
}
