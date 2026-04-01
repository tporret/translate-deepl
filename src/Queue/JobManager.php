<?php
declare(strict_types=1);

namespace TranslateDeepL\Queue;

if (! defined('ABSPATH')) {
    exit;
}

use Exception;
use TranslateDeepL\Api\Exception\DeepLRateLimitException;
use TranslateDeepL\Service\PostTranslator;

final class JobManager
{
    private const JOB_HOOK = 'deepl_translate_post_job';
    private const JOB_GROUP = 'translate-deepl';
    private const RETRY_DELAY_SECONDS = 120;

    public function __construct(private readonly PostTranslator $postTranslator)
    {
    }

    public function registerHooks(): void
    {
        add_action(self::JOB_HOOK, [$this, 'processJob'], 10, 2);
    }

    public function schedulePostTranslation(int $postId, string $targetLang): void
    {
        as_enqueue_async_action(
            self::JOB_HOOK,
            [
                'post_id' => $postId,
                'target_lang' => $targetLang,
            ],
            self::JOB_GROUP
        );
    }

    public function processJob(int $postId, string $targetLang): void
    {
        try {
            $success = $this->postTranslator->translatePost($postId, $targetLang);

            if (! $success) {
                throw new \RuntimeException(
                    sprintf('Post translation failed for post %d and language %s.', $postId, $targetLang)
                );
            }
        } catch (DeepLRateLimitException $exception) {
            as_schedule_single_action(
                time() + self::RETRY_DELAY_SECONDS,
                self::JOB_HOOK,
                [
                    'post_id' => $postId,
                    'target_lang' => $targetLang,
                ],
                self::JOB_GROUP
            );
        } catch (Exception $exception) {
            error_log(
                sprintf(
                    '[translate-deepl] Failed translation job for post %d (%s): %s',
                    $postId,
                    $targetLang,
                    $exception->getMessage()
                )
            );
        }
    }
}
