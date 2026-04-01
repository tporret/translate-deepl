<?php
declare(strict_types=1);

namespace TranslateDeepL\Service;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Queue\JobManager;
use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Repository\SettingsRepository;

final class SyncManager
{
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly SettingsRepository $settingsRepository,
        private readonly PostRelationRepository $postRelationRepository
    ) {
    }

    public function registerHooks(): void
    {
        add_action('save_post', [$this, 'handlePostSave'], 20, 3);
    }

    public function handlePostSave(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if (! $update) {
            return;
        }

        $supportedPostTypes = $this->settingsRepository->getSupportedPostTypes();

        if (! in_array((string) $post->post_type, $supportedPostTypes, true)) {
            return;
        }

        $originalPostId = $this->postRelationRepository->getOriginalPostId($postId);

        if ($originalPostId !== null) {
            return;
        }

        $activeLanguages = $this->settingsRepository->getActiveLanguages();

        foreach ($activeLanguages as $languageCode) {
            $translatedPostId = $this->postRelationRepository->getTranslatedPostId($postId, $languageCode);

            if ($translatedPostId === null) {
                continue;
            }

            $this->jobManager->schedulePostTranslation($postId, $languageCode);
        }
    }
}
