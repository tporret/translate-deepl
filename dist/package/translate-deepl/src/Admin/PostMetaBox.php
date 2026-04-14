<?php
declare(strict_types=1);

namespace TranslateDeepL\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Queue\JobManager;
use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Repository\SettingsRepository;

final class PostMetaBox
{
    private const ACTION = 'deepl_trigger_translation';
    private const NONCE_ACTION = 'deepl_trigger_translation';
    private const META_BOX_ID = 'translate_deepl_metabox';

    /**
     * @var array<string, string>
     */
    private array $languageLabels = [
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
    ];

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly PostRelationRepository $postRelationRepository,
        private readonly JobManager $jobManager
    ) {
    }

    public function registerHooks(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('admin_post_' . self::ACTION, [$this, 'handleTranslationRequest']);
    }

    public function registerMetaBox(): void
    {
        $supportedPostTypes = $this->settingsRepository->getSupportedPostTypes();

        add_meta_box(
            self::META_BOX_ID,
            'Translate DeepL',
            [$this, 'render'],
            $supportedPostTypes,
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        $activeLanguages = $this->settingsRepository->getActiveLanguages();

        if ($activeLanguages === []) {
            echo '<p>No active languages configured. Configure them in Settings > Translate DeepL.</p>';
            return;
        }

        echo '<ul style="margin:0; padding-left:18px;">';

        foreach ($activeLanguages as $languageCode) {
            $label = $this->getLanguageLabel($languageCode);
            $translatedPostId = $this->postRelationRepository->getTranslatedPostId((int) $post->ID, $languageCode);

            echo '<li>';
            echo esc_html($label . ': ');

            if ($translatedPostId !== null) {
                $editUrl = get_edit_post_link($translatedPostId, '');
                $syncUrl = wp_nonce_url(
                    add_query_arg(
                        [
                            'action' => self::ACTION,
                            'post_id' => (string) $post->ID,
                            'lang' => $languageCode,
                        ],
                        admin_url('admin-post.php')
                    ),
                    self::NONCE_ACTION
                );

                if (is_string($editUrl) && $editUrl !== '') {
                    printf(
                        '<a href="%s">Edit</a> <a class="button button-secondary button-small" href="%s">Sync Update</a>',
                        esc_url($editUrl)
                        ,
                        esc_url($syncUrl)
                    );
                } else {
                    printf(
                        'Translation exists <a class="button button-secondary button-small" href="%s">Sync Update</a>',
                        esc_url($syncUrl)
                    );
                }
            } else {
                $triggerUrl = wp_nonce_url(
                    add_query_arg(
                        [
                            'action' => self::ACTION,
                            'post_id' => (string) $post->ID,
                            'lang' => $languageCode,
                        ],
                        admin_url('admin-post.php')
                    ),
                    self::NONCE_ACTION
                );

                printf(
                    '<a class="button button-small" href="%s">Translate</a>',
                    esc_url($triggerUrl)
                );
            }

            echo '</li>';
        }

        echo '</ul>';
    }

    public function handleTranslationRequest(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        check_admin_referer(self::NONCE_ACTION);

        $postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        $languageCode = isset($_GET['lang']) ? sanitize_key((string) $_GET['lang']) : '';

        if ($postId > 0 && $languageCode !== '') {
            $this->jobManager->schedulePostTranslation($postId, $languageCode);
        }

        $redirectUrl = add_query_arg(
            [
                'post' => (string) $postId,
                'action' => 'edit',
                'message' => 'queued',
            ],
            admin_url('post.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    private function getLanguageLabel(string $languageCode): string
    {
        return $this->languageLabels[$languageCode] ?? strtoupper($languageCode);
    }
}
