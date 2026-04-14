<?php
declare(strict_types=1);

namespace TranslateDeepL\Api;

interface ApiClientInterface
{
    /**
     * @param array<int, string> $texts
     *
     * @return array<int, string>
     */
    public function translate(array $texts, string $targetLang, string $sourceLang = ''): array;
}
