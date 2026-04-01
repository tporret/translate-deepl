<?php
declare(strict_types=1);

namespace TranslateDeepL\Queue;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\SettingsRepository;

final class Sweeper
{
    private const SWEEPER_HOOK = 'deepl_daily_sweeper_job';
    private const SWEEPER_GROUP = 'translate-deepl';

    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly SettingsRepository $settingsRepository,
        private readonly JobManager $jobManager
    ) {
    }

    public function registerHooks(): void
    {
        add_action(self::SWEEPER_HOOK, [$this, 'runSweep']);
        add_action('admin_init', [$this, 'syncSchedule']);
    }

    public function runSweep(): void
    {
        if (! $this->settingsRepository->isSweeperEnabled()) {
            return;
        }

        $activeLanguages = $this->settingsRepository->getActiveLanguages();

        if ($activeLanguages === []) {
            return;
        }

        foreach ($activeLanguages as $langCode) {
            $postIds = $this->getUntranslatedPostIdsForLanguage($langCode);

            foreach ($postIds as $postId) {
                $this->jobManager->schedulePostTranslation((int) $postId, $langCode);
            }
        }
    }

    public function syncSchedule(): void
    {
        $nextScheduled = as_next_scheduled_action(self::SWEEPER_HOOK, [], self::SWEEPER_GROUP);
        $enabled = $this->settingsRepository->isSweeperEnabled();

        if (! $enabled && $nextScheduled !== false) {
            as_unschedule_all_actions(self::SWEEPER_HOOK, [], self::SWEEPER_GROUP);
            return;
        }

        if ($enabled && $nextScheduled === false) {
            as_schedule_recurring_action(
                $this->resolveFirstRunTimestamp(),
                DAY_IN_SECONDS,
                self::SWEEPER_HOOK,
                [],
                self::SWEEPER_GROUP
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function getUntranslatedPostIdsForLanguage(string $langCode): array
    {
        $postsTable = $this->wpdb->posts;
        $relationsTable = $this->getRelationsTableName();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be passed as placeholders.
        $query = $this->wpdb->prepare(
            "SELECT posts.ID
            FROM {$postsTable} posts
            LEFT JOIN {$relationsTable} rel
                ON rel.original_post_id = posts.ID
               AND rel.language_code = %s
            WHERE posts.post_status = 'publish'
              AND posts.post_type IN ('post', 'page')
              AND rel.id IS NULL",
            $langCode
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above.
        return $this->wpdb->get_col($query);
    }

    private function resolveFirstRunTimestamp(): int
    {
        $time = $this->settingsRepository->getSweeperTime();
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $firstRun = $now->setTime($hours, $minutes);

        if ($firstRun <= $now) {
            $firstRun = $firstRun->modify('+1 day');
        }

        return $firstRun->getTimestamp();
    }

    private function getRelationsTableName(): string
    {
        return sprintf('%sdeepl_post_relations', $this->wpdb->prefix);
    }
}