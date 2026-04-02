<?php
declare(strict_types=1);

namespace TranslateDeepL\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Repository\SettingsRepository;
use TranslateDeepL\Queue\JobManager;

final class TemplateManagerPage
{
    private const PAGE_SLUG = 'translate-deepl-templates';
    private const FSE_TRANSLATION_ACTION = 'deepl_trigger_fse_translation';
    private const FSE_TRANSLATION_NONCE_ACTION = 'deepl_trigger_fse_translation';
    private const CREATE_COPY_ACTION = 'deepl_create_template_copy';
    private const CREATE_COPY_NONCE_ACTION = 'deepl_create_template_copy';

    /**
     * @var array<string, bool>|null
     */
    private ?array $currentThemeTemplateKeys = null;

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
        echo '<h1>FSE Template Translations</h1>';

        $this->renderAdminNotice();

        if ($activeLanguages === []) {
            echo '<div class="notice notice-warning"><p>No active languages configured. Configure them in Settings > Translate DeepL first.</p></div>';
            echo '</div>';

            return;
        }

        $templates = $this->getTemplatesForDisplay();

        if ($templates === []) {
            echo '<p>No templates found. If your theme uses file-based templates, open Site Editor and save/customize a template to create a database record for translation.</p>';
            echo '</div>';

            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Template Name</th>';
        echo '<th>Template Type</th>';

        foreach ($activeLanguages as $languageCode) {
            echo '<th>' . esc_html($this->getLanguageLabel($languageCode)) . '</th>';
        }

        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($templates as $template) {
            $templateId = $template['id'];
            $templateTitle = $template['title'];
            $templateType = $template['type'];
            $templateSource = $template['source'];

            echo '<tr>';
            echo '<td>' . esc_html($templateTitle !== '' ? $templateTitle : '(Untitled)') . '</td>';
            echo '<td>' . esc_html($templateType) . '</td>';

            foreach ($activeLanguages as $languageCode) {
                echo '<td>';

                if ($templateId === null) {
                    $createCopyUrl = add_query_arg(
                        [
                            'action' => self::CREATE_COPY_ACTION,
                            'template_type' => $templateType,
                            'template_slug' => $template['slug'],
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

            if ($templateSource === 'theme') {
                echo '</tr>';
                continue;
            }

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    private function getLanguageLabel(string $languageCode): string
    {
        return $this->languageLabels[$languageCode] ?? strtoupper($languageCode);
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

        if (! in_array($templateType, ['wp_template', 'wp_template_part'], true) || $templateSlug === '') {
            $this->redirectWithStatus('invalid_template');
        }

        if ($templateSource !== 'theme') {
            $this->redirectWithStatus('invalid_source');
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
        $languageCode = isset($_GET['lang']) ? sanitize_key((string) $_GET['lang']) : '';

        $post = $postId > 0 ? get_post($postId) : null;

        if (! $post instanceof \WP_Post) {
            $this->redirectWithStatus('invalid_template');
        }

        $postType = (string) $post->post_type;

        if (! in_array($postType, ['wp_template', 'wp_template_part'], true)) {
            $this->redirectWithStatus('invalid_template');
        }

        $isSourcePost = $this->postRelationRepository->getOriginalPostId($postId) === null;

        if (! $isSourcePost) {
            $this->redirectWithStatus('invalid_template');
        }

        $isCurrentThemeTemplate = $this->isCurrentThemeTemplatePost($post);

        if (! $isCurrentThemeTemplate) {
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
        $query = new \WP_Query([
            'post_type' => $postType,
            'name' => $postName,
            'post_status' => ['publish', 'draft', 'auto-draft', 'private'],
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);

        if (! is_array($query->posts) || $query->posts === []) {
            return null;
        }

        foreach ($query->posts as $postId) {
            $post = get_post((int) $postId);

            if (! $post instanceof \WP_Post) {
                continue;
            }

            if ($this->isCurrentThemeTemplatePost($post)) {
                return (int) $post->ID;
            }
        }

        return null;
    }

    private function findThemeTemplate(string $templateType, string $templateSlug): ?object
    {
        if (! function_exists('get_block_templates')) {
            return null;
        }

        $templates = get_block_templates([], $templateType);

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
        $templates = [];
        $indexByTypeAndSlug = [];

        $query = new \WP_Query([
            'post_type' => ['wp_template', 'wp_template_part'],
            'post_status' => ['publish', 'draft', 'auto-draft'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        while ($query->have_posts()) {
            $query->the_post();
            $post = get_post((int) get_the_ID());

            if (! $post instanceof \WP_Post) {
                continue;
            }

            if (! $this->isCurrentThemeTemplatePost($post)) {
                continue;
            }

            $originalPostId = $this->postRelationRepository->getOriginalPostId((int) $post->ID);

            // Only display source templates in the manager table.
            if ($originalPostId !== null) {
                continue;
            }

            $slug = (string) $post->post_name;
            $type = (string) $post->post_type;
            $key = $type . ':' . $slug;

            $templates[] = [
                'id' => (int) $post->ID,
                'slug' => $slug,
                'title' => (string) get_the_title((int) $post->ID),
                'type' => $type,
                'source' => 'database',
            ];

            $indexByTypeAndSlug[$key] = true;
        }

        wp_reset_postdata();

        if (! function_exists('get_block_templates')) {
            return $templates;
        }

        $fileBasedTemplates = array_merge(
            get_block_templates([], 'wp_template'),
            get_block_templates([], 'wp_template_part')
        );

        foreach ($fileBasedTemplates as $blockTemplate) {
            if (! is_object($blockTemplate)) {
                continue;
            }

            $slug = isset($blockTemplate->slug) ? (string) $blockTemplate->slug : '';
            $type = isset($blockTemplate->type) ? (string) $blockTemplate->type : '';
            $source = isset($blockTemplate->source) ? (string) $blockTemplate->source : '';

            if ($slug === '' || $type === '') {
                continue;
            }

            if ($source !== 'theme') {
                continue;
            }

            $key = $type . ':' . $slug;

            if (isset($indexByTypeAndSlug[$key])) {
                continue;
            }

            $title = '';

            if (isset($blockTemplate->title) && is_string($blockTemplate->title)) {
                $title = $blockTemplate->title;
            }

            if ($title === '') {
                $title = $slug;
            }

            $templates[] = [
                'id' => null,
                'slug' => $slug,
                'title' => $title,
                'type' => $type,
                'source' => $source,
            ];
        }

        usort(
            $templates,
            static fn (array $a, array $b): int => strcmp($a['title'], $b['title'])
        );

        return $templates;
    }

    private function isCurrentThemeTemplatePost(\WP_Post $post): bool
    {
        $themeTerms = wp_get_object_terms((int) $post->ID, 'wp_theme', ['fields' => 'slugs']);

        if (is_wp_error($themeTerms)) {
            return false;
        }

        if (is_array($themeTerms) && $themeTerms !== []) {
            return in_array(get_stylesheet(), $themeTerms, true);
        }

        return $this->isCurrentThemeTemplateSlug((string) $post->post_type, (string) $post->post_name);
    }

    private function isCurrentThemeTemplateSlug(string $templateType, string $templateSlug): bool
    {
        if ($templateType === '' || $templateSlug === '') {
            return false;
        }

        $keys = $this->getCurrentThemeTemplateKeys();

        return isset($keys[$templateType . ':' . $templateSlug]);
    }

    /**
     * @return array<string, bool>
     */
    private function getCurrentThemeTemplateKeys(): array
    {
        if ($this->currentThemeTemplateKeys !== null) {
            return $this->currentThemeTemplateKeys;
        }

        $keys = [];

        if (! function_exists('get_block_templates')) {
            $this->currentThemeTemplateKeys = $keys;

            return $keys;
        }

        $templates = array_merge(
            get_block_templates([], 'wp_template'),
            get_block_templates([], 'wp_template_part')
        );

        foreach ($templates as $template) {
            if (! is_object($template)) {
                continue;
            }

            $slug = isset($template->slug) ? (string) $template->slug : '';
            $type = isset($template->type) ? (string) $template->type : '';
            $source = isset($template->source) ? (string) $template->source : '';

            if ($slug === '' || $type === '' || $source !== 'theme') {
                continue;
            }

            $keys[$type . ':' . $slug] = true;
        }

        $this->currentThemeTemplateKeys = $keys;

        return $keys;
    }
}