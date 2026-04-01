<?php
declare(strict_types=1);

namespace TranslateDeepL\Service;

if (! defined('ABSPATH')) {
    exit;
}

final class BlockProcessor
{
    /**
     * @return array<int, string>
     */
    public function extractTranslatableStrings(string $postContent): array
    {
        $blocks = parse_blocks($postContent);
        $strings = [];

        $this->extractFromBlocks($blocks, $strings);

        return $strings;
    }

    /**
     * @param array<int, string> $translatedStrings
     */
    public function reassembleBlocks(string $postContent, array $translatedStrings): string
    {
        $blocks = parse_blocks($postContent);
        $position = 0;

        $this->applyTranslations($blocks, $translatedStrings, $position);

        if ($position !== count($translatedStrings)) {
            throw new \InvalidArgumentException(
                sprintf( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
                    'Translation count mismatch: consumed %d values but received %d.',
                    $position,
                    count($translatedStrings)
                )
            );
        }

        return serialize_blocks($blocks);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, string> $strings
     */
    private function extractFromBlocks(array $blocks, array &$strings): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $fragment) {
                    if (! is_string($fragment)) {
                        continue;
                    }

                    if (trim($fragment) !== '') {
                        $strings[] = $fragment;
                    }
                }
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->extractFromBlocks($block['innerBlocks'], $strings);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, string> $translatedStrings
     */
    private function applyTranslations(array &$blocks, array $translatedStrings, int &$position): void
    {
        foreach ($blocks as &$block) {
            if (! is_array($block)) {
                continue;
            }

            if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $fragmentIndex => $fragment) {
                    if (! is_string($fragment)) {
                        continue;
                    }

                    if (trim($fragment) !== '') {
                        if (! array_key_exists($position, $translatedStrings)) {
                            throw new \InvalidArgumentException(
                                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
                                sprintf('Missing translated string at position %d.', $position)
                            );
                        }

                        $block['innerContent'][$fragmentIndex] = $translatedStrings[$position];
                        $position++;
                    }
                }
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->applyTranslations($block['innerBlocks'], $translatedStrings, $position);
            }
        }

        unset($block);
    }
}
