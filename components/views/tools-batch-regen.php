<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, \WP_Post_Type> $post_types */
/** @var array<string, string> $page_templates */
/** @var array<int, array{value:string,label:string}> $archive_pages */
/** @var array<int, array{value:string,label:string,kind?:string}> $specialty_pages */

$hub_url = \NobleSchema\Admin\Tools_Page::page_url();
$post_types = $post_types ?? get_post_types(['public' => true], 'objects');
$page_templates = $page_templates ?? wp_get_theme()->get_page_templates();
$archive_pages = $archive_pages ?? \NobleSchema\Targeting\Archive_Context::available_archives();
$specialty_pages = $specialty_pages ?? \NobleSchema\Targeting\Specialty_Context::available();
?>
<div class="wrap ns-tools-wrap" id="ns-batch-regen">
    <div class="ns-wizard__heading">
        <a
            href="<?php echo esc_url($hub_url); ?>"
            class="ns-wizard__heading-back"
            aria-label="<?php esc_attr_e('Back to Schema Tools', 'noble-schema'); ?>"
        >
            <svg class="ns-wizard__nav-icon" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                <path d="M12 5 7 10l5 5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        <h1><?php esc_html_e('Batch Regenerate', 'noble-schema'); ?></h1>
    </div>
    <hr class="wp-header-end">
    <p class="description">
        <?php esc_html_e('Regenerate schema for posts matching placement targets (post types, templates, archives and more).', 'noble-schema'); ?>
    </p>

    <form id="ns-batch-regen-form" class="ns-tools-card ns-batch-regen">
        <div class="ns-field-row">
            <div class="ns-field-row__label">
                <span class="ns-badge ns-badge--required"><?php esc_html_e('Required', 'noble-schema'); ?></span>
                <span class="ns-field-row__heading"><?php esc_html_e('Placement', 'noble-schema'); ?></span>
            </div>
            <div class="ns-field-row__control">
                <fieldset class="ns-placement-mode">
                    <legend class="screen-reader-text"><?php esc_html_e('Placement mode', 'noble-schema'); ?></legend>
                    <label class="ns-placement-mode__option">
                        <input type="radio" name="placement" value="global" id="ns-batch-placement-global">
                        <?php esc_html_e('Global (all content)', 'noble-schema'); ?>
                    </label>
                    <label class="ns-placement-mode__option">
                        <input type="radio" name="placement" value="targeted" id="ns-batch-placement-targeted" checked>
                        <?php esc_html_e('Targeted (match any selected below)', 'noble-schema'); ?>
                    </label>
                </fieldset>
                <p class="ns-field-error" id="ns-batch-placement-error" role="alert" hidden></p>
            </div>
        </div>

        <div class="ns-placement-targets" id="ns-batch-placement-targets">
            <div class="ns-field-row">
                <div class="ns-field-row__label">
                    <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                    <label for="ns-batch-post-types-input"><?php esc_html_e('Post types', 'noble-schema'); ?></label>
                </div>
                <div class="ns-field-row__control">
                    <div class="ns-multiselect" id="ns-batch-post-types" data-name="target_post_types[]" data-placeholder="<?php esc_attr_e('Search post types…', 'noble-schema'); ?>">
                        <div class="ns-multiselect__pills" hidden></div>
                        <div class="ns-multiselect__control">
                            <input type="text" id="ns-batch-post-types-input" class="ns-multiselect__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search post types…', 'noble-schema'); ?>">
                            <ul class="ns-multiselect__dropdown" id="ns-batch-post-types-list" role="listbox" hidden></ul>
                        </div>
                        <div class="ns-multiselect__values"></div>
                        <script type="application/json" class="ns-multiselect__options"><?php
                            echo wp_json_encode(array_map(
                                static fn ($slug, $pt) => ['value' => $slug, 'label' => $pt->labels->singular_name],
                                array_keys($post_types),
                                array_values($post_types)
                            ));
                        ?></script>
                        <script type="application/json" class="ns-multiselect__selected">[]</script>
                    </div>
                </div>
            </div>

            <div class="ns-field-row">
                <div class="ns-field-row__label">
                    <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                    <label for="ns-batch-page-templates-input"><?php esc_html_e('Page templates', 'noble-schema'); ?></label>
                </div>
                <div class="ns-field-row__control">
                    <div class="ns-multiselect" id="ns-batch-page-templates" data-name="target_page_templates[]" data-placeholder="<?php esc_attr_e('Search page templates…', 'noble-schema'); ?>">
                        <div class="ns-multiselect__pills" hidden></div>
                        <div class="ns-multiselect__control">
                            <input type="text" id="ns-batch-page-templates-input" class="ns-multiselect__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search page templates…', 'noble-schema'); ?>">
                            <ul class="ns-multiselect__dropdown" id="ns-batch-page-templates-list" role="listbox" hidden></ul>
                        </div>
                        <div class="ns-multiselect__values"></div>
                        <script type="application/json" class="ns-multiselect__options"><?php
                            $tpl_opts = [];
                            foreach ($page_templates as $file => $label) {
                                $tpl_opts[] = ['value' => $file, 'label' => $label];
                            }
                            echo wp_json_encode($tpl_opts);
                        ?></script>
                        <script type="application/json" class="ns-multiselect__selected">[]</script>
                    </div>
                </div>
            </div>

            <?php if ($archive_pages !== []) : ?>
            <div class="ns-field-row">
                <div class="ns-field-row__label">
                    <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                    <label for="ns-batch-archives-input"><?php esc_html_e('Archive pages', 'noble-schema'); ?></label>
                </div>
                <div class="ns-field-row__control">
                    <div class="ns-multiselect" id="ns-batch-archives" data-name="target_archives[]" data-placeholder="<?php esc_attr_e('Search archive pages…', 'noble-schema'); ?>">
                        <div class="ns-multiselect__pills" hidden></div>
                        <div class="ns-multiselect__control">
                            <input type="text" id="ns-batch-archives-input" class="ns-multiselect__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search archive pages…', 'noble-schema'); ?>">
                            <ul class="ns-multiselect__dropdown" id="ns-batch-archives-list" role="listbox" hidden></ul>
                        </div>
                        <div class="ns-multiselect__values"></div>
                        <script type="application/json" class="ns-multiselect__options"><?php
                            echo wp_json_encode(array_values($archive_pages));
                        ?></script>
                        <script type="application/json" class="ns-multiselect__selected">[]</script>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="ns-field-row">
                <div class="ns-field-row__label">
                    <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                    <label for="ns-batch-specialties-input"><?php esc_html_e('Specialty pages', 'noble-schema'); ?></label>
                </div>
                <div class="ns-field-row__control">
                    <div class="ns-multiselect" id="ns-batch-specialties" data-name="target_specialties[]" data-placeholder="<?php esc_attr_e('Search specialty pages…', 'noble-schema'); ?>">
                        <div class="ns-multiselect__pills" hidden></div>
                        <div class="ns-multiselect__control">
                            <input type="text" id="ns-batch-specialties-input" class="ns-multiselect__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search specialty pages…', 'noble-schema'); ?>">
                            <ul class="ns-multiselect__dropdown" id="ns-batch-specialties-list" role="listbox" hidden></ul>
                        </div>
                        <div class="ns-multiselect__values"></div>
                        <script type="application/json" class="ns-multiselect__options"><?php
                            echo wp_json_encode(array_map(
                                static fn ($opt) => [
                                    'value' => $opt['value'],
                                    'label' => $opt['label'],
                                ],
                                array_values($specialty_pages)
                            ));
                        ?></script>
                        <script type="application/json" class="ns-multiselect__selected">[]</script>
                    </div>
                </div>
            </div>

            <div class="ns-field-row">
                <div class="ns-field-row__label">
                    <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                    <label for="ns-batch-posts-input"><?php esc_html_e('Specific posts', 'noble-schema'); ?></label>
                </div>
                <div class="ns-field-row__control">
                    <div class="ns-multiselect" id="ns-batch-posts" data-name="target_posts[]" data-remote="posts" data-placeholder="<?php esc_attr_e('Search by title or post ID…', 'noble-schema'); ?>">
                        <div class="ns-multiselect__pills" hidden></div>
                        <div class="ns-multiselect__control">
                            <input type="text" id="ns-batch-posts-input" class="ns-multiselect__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search by title or post ID…', 'noble-schema'); ?>">
                            <ul class="ns-multiselect__dropdown" id="ns-batch-posts-list" role="listbox" hidden></ul>
                        </div>
                        <div class="ns-multiselect__values"></div>
                        <script type="application/json" class="ns-multiselect__options">[]</script>
                        <script type="application/json" class="ns-multiselect__selected">[]</script>
                    </div>
                </div>
            </div>
        </div>

        <p class="description" style="margin-top:1rem;">
            <?php esc_html_e('Specific posts regenerate immediately, while post types and page templates enqueue a background job. Selected archives expand to concrete URLs (terms, authors, dates) and join that cancelable queue. Virtual specialty pages (404 / Search) refresh their cached schema graphs. Page-backed specialty pages regenerate their assigned posts.', 'noble-schema'); ?>
        </p>

        <p class="submit ns-batch-regen__submit" style="margin-bottom:0;">
            <button type="submit" class="button button-primary" id="ns-batch-regen-submit">
                <?php esc_html_e('Start regeneration', 'noble-schema'); ?>
            </button>
            <span id="ns-batch-regen-count" class="ns-batch-regen__count" aria-live="polite">
                <?php esc_html_e('0 posts selected', 'noble-schema'); ?>
            </span>
        </p>
    </form>
</div>
