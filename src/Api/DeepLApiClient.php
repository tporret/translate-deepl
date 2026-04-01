<?php
declare(strict_types=1);

namespace TranslateDeepL\Api;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Api\Exception\DeepLAuthException;
use TranslateDeepL\Api\Exception\DeepLRateLimitException;
use TranslateDeepL\Api\Exception\DeepLRequestException;

final class DeepLApiClient implements ApiClientInterface
{
    private const TRANSLATE_ENDPOINT_PRO = 'https://api.deepl.com/v2/translate';
    private const TRANSLATE_ENDPOINT_FREE = 'https://api-free.deepl.com/v2/translate';

    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * @param array<int, string> $texts
     *
     * @return array<int, string>
     */
    public function translate(array $texts, string $targetLang, string $sourceLang = ''): array
    {
        if ($this->apiKey === '') {
            throw new DeepLAuthException('DeepL API key is not configured.');
        }

        if ($texts === []) {
            return [];
        }

        $body = [
            'text' => array_values($texts),
            'target_lang' => $targetLang,
            'tag_handling' => 'html',
        ];

        if ($sourceLang !== '') {
            $body['source_lang'] = $sourceLang;
        }

        $response = wp_remote_post(
            $this->resolveEndpoint(),
            [
                'headers' => [
                    'Authorization' => sprintf('DeepL-Auth-Key %s', $this->apiKey),
                ],
                'body' => $body,
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
            throw new DeepLRequestException($response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);

        if ($statusCode === 403) {
            throw new DeepLAuthException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
                sprintf(
                    'DeepL API authentication failed with HTTP 403. Check API key and endpoint type (free vs pro). Response: %s',
                    $responseBody
                )
            );
        }

        if ($statusCode === 429) {
            throw new DeepLRateLimitException('DeepL API rate limit reached with HTTP 429.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new DeepLRequestException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not direct browser output.
                sprintf('DeepL API request failed with HTTP %d. Response: %s', $statusCode, $responseBody)
            );
        }

        $payload = json_decode($responseBody, true);

        if (! is_array($payload) || ! isset($payload['translations']) || ! is_array($payload['translations'])) {
            throw new DeepLRequestException('DeepL API response payload is invalid.');
        }

        $translatedTexts = [];

        foreach ($payload['translations'] as $translation) {
            if (! is_array($translation) || ! isset($translation['text']) || ! is_string($translation['text'])) {
                throw new DeepLRequestException('DeepL API translation item is malformed.');
            }

            $translatedTexts[] = $translation['text'];
        }

        return $translatedTexts;
    }

    private function resolveEndpoint(): string
    {
        if (str_ends_with($this->apiKey, ':fx')) {
            return self::TRANSLATE_ENDPOINT_FREE;
        }

        return self::TRANSLATE_ENDPOINT_PRO;
    }
}
