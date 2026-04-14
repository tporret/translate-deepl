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

        $sourceStrings = $this->blockProcessor->extractTranslatableStrings($sourceContent);
        $translatedStrings = $this->translateStringsWithMemory($sourceStrings, $targetLang, $sourceLang);
        $translatedContent = $this->blockProcessor->reassembleBlocks($sourceContent, $translatedStrings);

        $translatedTitle = $this->translateTitle((string) $post->post_title, $targetLang, $sourceLang);

        if ($existingTranslatedPostId !== null) {
            $updatedPostId = wp_update_post(
                [
                    'ID' => $existingTranslatedPostId,
                    'post_author' => (int) $post->post_author,
                    'post_date' => (string) $post->post_date,
                    'post_date_gmt' => (string) $post->post_date_gmt,
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

            $this->inheritPostTerms($post, $existingTranslatedPostId);

            return true;
        }

        $translatedPostId = wp_insert_post(
            [
                'post_author' => (int) $post->post_author,
                'post_date' => (string) $post->post_date,
                'post_date_gmt' => (string) $post->post_date_gmt,
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

        $this->inheritPostTerms($post, $translatedPostId);

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

    private function inheritPostTerms(\WP_Post $sourcePost, int $targetPostId): void
    {
        $taxonomies = get_object_taxonomies((string) $sourcePost->post_type, 'names');

        if (! is_array($taxonomies) || $taxonomies === []) {
            return;
        }

        foreach ($taxonomies as $taxonomy) {
            if (! is_string($taxonomy) || $taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                continue;
            }

            $termIds = wp_get_object_terms((int) $sourcePost->ID, $taxonomy, ['fields' => 'ids']);

            if (is_wp_error($termIds) || ! is_array($termIds)) {
                continue;
            }

            wp_set_object_terms($targetPostId, $termIds, $taxonomy, false);
        }
    }

}
