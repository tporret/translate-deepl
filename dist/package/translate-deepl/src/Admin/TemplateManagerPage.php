<?php
declare(strict_types=1);

namespace TranslateDeepL\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Repository\SettingsRepository;
use TranslateDeepL\Repository\AssetRegistryRepository;
use TranslateDeepL\Queue\JobManager;
use TranslateDeepL\Support\FseAssetCatalog;

final class TemplateManagerPage
{
    private const PAGE_SLUG = 'translate-deepl-templates';
    private const FSE_TRANSLATION_ACTION = 'deepl_trigger_fse_translation';
    private const FSE_TRANSLATION_NONCE_ACTION = 'deepl_trigger_fse_translation';
    private const CREATE_COPY_ACTION = 'deepl_create_template_copy';
    private const CREATE_COPY_NONCE_ACTION = 'deepl_create_template_copy';

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
        private readonly AssetRegistryRepository $assetRegistryRepository,
        private readonly JobManager $jobManager,
        private readonly FseAssetCatalog $fseAssetCatalog
    ) {
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenuPage']);
        add_action('admin_post_' . self::CREATE_COPY_ACTION, [$this, 'handleCreateEditableCopy']);
        add_action('admin_post_' . self::FSE_TRANSLATION_ACTION, [$this, 'handleTranslationTrigger']);
        add_action('admin_notices', [$this, 'displayAdminNotices']);
    }

    public function registerMenuPage(): void
    {
        add_submenu_page(
            'options-general.php',
            'Template Translations',
            'Template Translations',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $activeLanguages = $this->settingsRepository->getActiveLanguages();

        echo '<div class="wrap">';
        echo '<h1>FSE Translations</h1>';

        $this->renderAdminNotice();
        $this->renderWorkflowNotice();

        if ($activeLanguages === []) {
            echo '<div class="notice notice-warning"><p>No active languages configured. Configure them in Settings > Translate DeepL first.</p></div>';
            echo '</div>';

            return;
        }

        $templates = $this->getTemplatesForDisplay();

        if ($templates === []) {
            echo '<p>No FSE assets found. Theme file templates and template parts may require an editable copy before translation. Theme patterns are discovered directly from the active theme registry.</p>';
            echo '</div>';

            return;
        }

        $sections = $this->groupTemplatesBySection($templates);

        $this->renderSection(
            'Theme Files',
            'Copy-first',
            'Theme file templates and template parts must be materialized as editable copies before they can be translated or synced.',
            $sections['theme_files'],
            $activeLanguages
        );
        $this->renderSection(
            'Theme Patterns',
            'Registry-native',
            'Theme patterns are registry-native assets. Translate them directly from the active theme source without creating editable copies.',
            $sections['theme_patterns'],
            $activeLanguages
        );
        $this->renderSection(
            'Database Assets',
            'Editable',
            'These assets already exist as editable database records and can be translated or synchronized directly.',
            $sections['database_assets'],
            $activeLanguages
        );

        echo '</div>';
    }

    private function getLanguageLabel(string $languageCode): string
    {
        return $this->languageLabels[$languageCode] ?? strtoupper($languageCode);
    }

    private function renderWorkflowNotice(): void
    {
        echo '<div class="notice notice-info" style="margin-top:12px;">';
        echo '<p><strong>File templates and template parts</strong> are translated from editable database copies. Use <em>Create editable copy</em> first for theme file assets.</p>';
        echo '<p><strong>Theme patterns</strong> are translated directly from the registry-backed source store. They do not require editable copies.</p>';
        echo '</div>';
    }

    /**
     * @param array<int, array{id:?int, slug:string, title:string, type:string, source:string, asset_key:string}> $templates
     *
     * @return array<string, array<int, array{id:?int, slug:string, title:string, type:string, source:string, asset_key:string}>>
     */
    private function groupTemplatesBySection(array $templates): array
    {
        $sections = [
            'theme_files' => [],
            'theme_patterns' => [],
            'database_assets' => [],
        ];

        foreach ($templates as $template) {
            $source = $template['source'];

            if ($source === 'theme') {
                $sections['theme_files'][] = $template;
                continue;
            }

            if ($source === 'theme_pattern') {
                $sections['theme_patterns'][] = $template;
                continue;
            }

            $sections['database_assets'][] = $template;
        }

        return $sections;
    }

    /**
     * @param array<int, array{id:?int, slug:string, title:string, type:string, source:string, asset_key:string}> $templates
     * @param array<int, string> $activeLanguages
     */
    private function renderSection(string $title, string $badgeLabel, string $description, array $templates, array $activeLanguages): void
    {
        echo '<section style="margin-top:24px;">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">';
        echo '<h2 style="margin:0;">' . esc_html($title) . '</h2>';
        echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#f0f4f8;color:#1d2327;font-size:12px;font-weight:600;line-height:1.4;">' . esc_html($badgeLabel) . '</span>';
        echo '</div>';
        echo '<p style="margin-top:0;color:#50575e;max-width:900px;">' . esc_html($description) . '</p>';

        if ($templates === []) {
            echo '<p style="color:#50575e;"><em>No assets in this section.</em></p>';
            echo '</section>';

            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Asset</th>';
        echo '<th>Type</th>';

        foreach ($activeLanguages as $languageCode) {
            echo '<th>' . esc_html($this->getLanguageLabel($languageCode)) . '</th>';
        }

        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($templates as $template) {
            $this->renderAssetRow($template, $activeLanguages);
        }

        echo '</tbody>';
        echo '</table>';
        echo '</section>';
    }

    /**
     * @param array{id:?int, slug:string, title:string, type:string, source:string, asset_key:string} $template
     * @param array<int, string> $activeLanguages
     */
    private function renderAssetRow(array $template, array $activeLanguages): void
    {
        $templateId = $template['id'];
        $templateTitle = $template['title'];
        $templateType = $template['type'];
        $templateSource = $template['source'];
        $assetKey = $template['asset_key'];

        echo '<tr>';
        echo '<td>';
        echo '<strong>' . esc_html($templateTitle !== '' ? $templateTitle : '(Untitled)') . '</strong>';
        echo '<div style="margin-top:4px;color:#50575e;font-size:12px;">' . esc_html($this->getWorkflowDescription($templateType, $templateSource)) . '</div>';
        echo '</td>';
        echo '<td>' . esc_html($this->getAssetTypeLabel($templateType, $templateSource)) . '</td>';

        foreach ($activeLanguages as $languageCode) {
            echo '<td>';

            if ($templateId === null) {
                if ($templateSource === 'theme_pattern') {
                    $hasTranslation = $this->assetRegistryRepository->hasPublishedTranslation($assetKey, $languageCode);
                    $translateUrl = add_query_arg(
                        [
                            'action' => self::FSE_TRANSLATION_ACTION,
                            'asset_key' => $assetKey,
                            'asset_source' => $templateSource,
                            'lang' => $languageCode,
                            '_wpnonce' => wp_create_nonce(self::FSE_TRANSLATION_NONCE_ACTION),
                        ],
                        admin_url('admin-post.php')
                    );

                    if ($hasTranslation) {
                        echo '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e8f7ed;color:#0a7a31;font-weight:600;margin-right:8px;">Translated</span>';
                        echo '<a class="button button-secondary" href="' . esc_url($translateUrl) . '">Sync Pattern</a>';
                    } else {
                        echo '<a class="button" href="' . esc_url($translateUrl) . '">Translate Pattern</a>';
                    }

                    echo '</td>';
                    continue;
                }

                $createCopyUrl = add_query_arg(
                    [
                        'action' => self::CREATE_COPY_ACTION,
                        'template_type' => $templateType,
                        'template_slug' => $template['slug'],
                        'asset_key' => $assetKey,
                        'template_source' => $templateSource,
                        '_wpnonce' => wp_create_nonce(self::CREATE_COPY_NONCE_ACTION),
                    ],
                    admin_url('admin-post.php')
                );

                echo '<a class="button" href="' . esc_url($createCopyUrl) . '">Create editable copy</a>';
                echo '</td>';
                continue;
            }

            $translatedId = $this->postRelationRepository->getTranslatedPostId($templateId, $languageCode);

            if ($translatedId !== null) {
                $syncUrl = add_query_arg(
                    [
                        'action' => self::FSE_TRANSLATION_ACTION,
                        'post_id' => (string) $templateId,
                        'lang' => $languageCode,
                        '_wpnonce' => wp_create_nonce(self::FSE_TRANSLATION_NONCE_ACTION),
                    ],
                    admin_url('admin-post.php')
                );

                echo '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e8f7ed;color:#0a7a31;font-weight:600;margin-right:8px;">Translated</span>';
                echo '<a class="button button-secondary" href="' . esc_url($syncUrl) . '">Sync Update</a>';
            } else {
                $url = add_query_arg(
                    [
                        'action' => self::FSE_TRANSLATION_ACTION,
                        'post_id' => (string) $templateId,
                        'lang' => $languageCode,
                        '_wpnonce' => wp_create_nonce(self::FSE_TRANSLATION_NONCE_ACTION),
                    ],
                    admin_url('admin-post.php')
                );

                echo '<a class="button" href="' . esc_url($url) . '">Translate</a>';
            }

            echo '</td>';
        }

        echo '</tr>';
    }

    private function getAssetTypeLabel(string $templateType, string $templateSource): string
    {
        if ($templateSource === 'theme_pattern') {
            return 'Theme Pattern';
        }

        return match ($templateType) {
            'wp_template' => 'Template',
            'wp_template_part' => 'Template Part',
            'wp_block' => 'Synced Pattern',
            default => $templateType,
        };
    }

    private function getWorkflowDescription(string $templateType, string $templateSource): string
    {
        if ($templateSource === 'theme_pattern') {
            return 'Registry-native pattern. Translate directly without creating an editable copy.';
        }

        if ($templateSource === 'theme') {
            return 'Theme file asset. Create an editable copy before translating.';
        }

        if ($templateType === 'wp_block') {
            return 'Database-backed synced pattern.';
        }

        return 'Database-backed editable asset.';
    }

    public function handleCreateEditableCopy(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        check_admin_referer(self::CREATE_COPY_NONCE_ACTION);

        $templateType = isset($_GET['template_type']) ? sanitize_key((string) $_GET['template_type']) : '';
        $templateSlug = isset($_GET['template_slug']) ? sanitize_title((string) $_GET['template_slug']) : '';
        $templateSource = isset($_GET['template_source']) ? sanitize_key((string) $_GET['template_source']) : '';

        if (! in_array($templateType, ['wp_template', 'wp_template_part'], true)) {
            $this->redirectWithStatus('invalid_template');
        }

        if ($templateSource !== 'theme') {
            $this->redirectWithStatus('invalid_source');
        }

        if ($templateSlug === '') {
            $this->redirectWithStatus('invalid_template');
        }

        $existingTemplateId = $this->findTemplatePostIdBySlug($templateType, $templateSlug);

        if ($existingTemplateId !== null) {
            $this->redirectWithStatus('already_exists');
        }

        $templateFromTheme = $this->findThemeTemplate($templateType, $templateSlug);

        if ($templateFromTheme === null) {
            $this->redirectWithStatus('not_found');
        }

        $templateTitle = isset($templateFromTheme->title) && is_string($templateFromTheme->title)
            ? $templateFromTheme->title
            : '';

        if ($templateTitle === '') {
            $templateTitle = ucwords(str_replace(['-', '_'], ' ', $templateSlug));
        }

        $templateContent = isset($templateFromTheme->content) && is_string($templateFromTheme->content)
            ? $templateFromTheme->content
            : '';

        $taxInput = [
            'wp_theme' => [get_stylesheet()],
        ];

        if (
            $templateType === 'wp_template_part'
            && isset($templateFromTheme->area)
            && is_string($templateFromTheme->area)
            && $templateFromTheme->area !== ''
        ) {
            $taxInput['wp_template_part_area'] = [$templateFromTheme->area];
        }

        $newTemplateId = wp_insert_post(
            [
                'post_type' => $templateType,
                'post_name' => $templateSlug,
                'post_title' => $templateTitle,
                'post_status' => 'publish',
                'post_content' => $templateContent,
                'tax_input' => $taxInput,
            ],
            true
        );

        if (is_wp_error($newTemplateId) || ! is_int($newTemplateId) || $newTemplateId <= 0) {
            $this->redirectWithStatus('create_failed');
        }

        $this->redirectWithStatus('created');
    }

    public function handleTranslationTrigger(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        check_admin_referer(self::FSE_TRANSLATION_NONCE_ACTION);

        $postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        $assetKey = isset($_GET['asset_key']) ? sanitize_text_field((string) $_GET['asset_key']) : '';
        $assetSource = isset($_GET['asset_source']) ? sanitize_key((string) $_GET['asset_source']) : '';
        $languageCode = isset($_GET['lang']) ? sanitize_key((string) $_GET['lang']) : '';

        if ($assetKey !== '') {
            if ($assetSource !== 'theme_pattern' || $languageCode === '') {
                $this->redirectWithStatus('invalid_template');
            }

            $this->jobManager->scheduleRegistryAssetTranslation($assetKey, $languageCode);

            $url = add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                    'message' => 'fse_queued',
                ],
                admin_url('options-general.php')
            );

            wp_safe_redirect($url);
            exit;
        }

        $post = $postId > 0 ? get_post($postId) : null;

        if (! $post instanceof \WP_Post) {
            $this->redirectWithStatus('invalid_template');
        }

        $postType = (string) $post->post_type;

        if (! in_array($postType, ['wp_template', 'wp_template_part', 'wp_block'], true)) {
            $this->redirectWithStatus('invalid_template');
        }

        $isSourcePost = $this->postRelationRepository->getOriginalPostId($postId) === null;

        if (! $isSourcePost) {
            $this->redirectWithStatus('invalid_template');
        }

        if ($postType !== 'wp_block' && ! $this->isCurrentThemeTemplatePost($post)) {
            $this->redirectWithStatus('invalid_template');
        }

        if ($postId > 0 && $languageCode !== '') {
            $this->jobManager->schedulePostTranslation($postId, $languageCode);
        }

        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'message' => 'fse_queued',
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    public function displayAdminNotices(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $message = isset($_GET['message']) ? sanitize_key((string) $_GET['message']) : '';

        if ($page !== self::PAGE_SLUG || $message !== 'fse_queued') {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>Template translation successfully queued! It will be processed in the background.</p></div>';
    }

    private function renderAdminNotice(): void
    {
        $status = isset($_GET['deepl_templates_status'])
            ? sanitize_key((string) $_GET['deepl_templates_status'])
            : '';

        if ($status === '') {
            return;
        }

        if ($status === 'created') {
            echo '<div class="notice notice-success is-dismissible"><p>Editable template copy created successfully.</p></div>';
            return;
        }

        $messages = [
            'already_exists' => 'An editable copy already exists for this template.',
            'not_found' => 'Unable to find the source file-based template.',
            'invalid_template' => 'Invalid template type or slug supplied.',
            'invalid_source' => 'Only theme file-based templates can be copied.',
            'create_failed' => 'Failed to create editable template copy.',
        ];

        $message = $messages[$status] ?? 'Unable to process template copy request.';
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function redirectWithStatus(string $status): void
    {
        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'deepl_templates_status' => $status,
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    private function findTemplatePostIdBySlug(string $postType, string $postName): ?int
    {
        return $this->fseAssetCatalog->findSourceTemplatePostId($postType, $postName);
    }

    private function findThemeTemplate(string $templateType, string $templateSlug): ?object
    {
        if (! function_exists('get_block_templates')) {
            return null;
        }

        $templates = get_block_templates(['slug__in' => [$templateSlug]], $templateType);

        foreach ($templates as $template) {
            if (! is_object($template)) {
                continue;
            }

            $slug = isset($template->slug) ? (string) $template->slug : '';
            $source = isset($template->source) ? (string) $template->source : '';

            if ($slug === $templateSlug && $source === 'theme') {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id:?int, slug:string, title:string, type:string, source:string}>
     */
    private function getTemplatesForDisplay(): array
    {
        return $this->fseAssetCatalog->getAssetsForManagement();
    }
}