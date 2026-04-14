<?php
declare(strict_types=1);

namespace TranslateDeepL\Service;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Api\DeepLApiClient;
use TranslateDeepL\Repository\AssetRegistryRepository;
use TranslateDeepL\Repository\TranslationMemoryRepository;

final class AssetRegistryTranslator
{
    public function __construct(
        private readonly BlockProcessor $blockProcessor,
        private readonly DeepLApiClient $apiClient,
        private readonly TranslationMemoryRepository $translationMemoryRepository,
        private readonly AssetRegistryRepository $assetRegistryRepository
    ) {
    }

    public function translateAsset(string $assetKey, string $targetLang, string $sourceLang = 'en'): bool
    {
        $sourceAsset = $this->assetRegistryRepository->getSourceAsset($assetKey);

        if ($sourceAsset === null) {
            return false;
        }

        $sourceContent = isset($sourceAsset['content']) ? (string) $sourceAsset['content'] : '';
        $sourceTitle = isset($sourceAsset['title']) ? (string) $sourceAsset['title'] : '';
        $sourceContentHash = isset($sourceAsset['content_hash']) ? (string) $sourceAsset['content_hash'] : '';

        if ($sourceContent === '' || $sourceContentHash === '') {
            return false;
        }

        $sourceStrings = $this->blockProcessor->extractTranslatableStrings($sourceContent);
        $translatedStrings = $this->translateStringsWithMemory($sourceStrings, $targetLang, $sourceLang);
        $translatedContent = $this->blockProcessor->reassembleBlocks($sourceContent, $translatedStrings);
        $translatedTitle = $sourceTitle !== ''
            ? $this->translateTitle($sourceTitle, $targetLang, $sourceLang)
            : $sourceTitle;

        return $this->assetRegistryRepository->saveTranslation(
            $assetKey,
            $targetLang,
            $translatedTitle,
            $translatedContent,
            $sourceContentHash
        );
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
            $translatedPayload = $this->apiClient->translate(
                array_map(static fn (array $item): string => $item['text'], $misses),
                $targetLang,
                $sourceLang
            );

            if (count($translatedPayload) !== count($misses)) {
                throw new \RuntimeException('DeepL response count does not match registry asset translation request.');
            }

            foreach ($translatedPayload as $position => $translatedText) {
                $miss = $misses[$position];
                $saved = $this->translationMemoryRepository->saveTranslation(
                    $miss['hash'],
                    $sourceLang,
                    $targetLang,
                    $translatedText
                );

                if (! $saved) {
                    throw new \RuntimeException('Failed to save registry asset translation memory entry.');
                }

                $translated[$miss['index']] = $translatedText;
            }
        }

        ksort($translated);

        if (count($translated) !== count($sourceStrings)) {
            throw new \RuntimeException('Unable to produce a complete registry asset translated string set.');
        }

        return array_values($translated);
    }

    private function translateTitle(string $title, string $targetLang, string $sourceLang): string
    {
        $translated = $this->apiClient->translate([$title], $targetLang, $sourceLang);

        if (! isset($translated[0]) || ! is_string($translated[0])) {
            throw new \RuntimeException('DeepL did not return a translated registry asset title.');
        }

        return $translated[0];
    }
}