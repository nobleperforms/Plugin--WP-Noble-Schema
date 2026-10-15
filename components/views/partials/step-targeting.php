<?php

if (!defined('ABSPATH')) {
    exit;
}

$placement = $config ? (string) $config->placement : 'global';
$is_sitewide = $placement === 'sitewide';
$is_global = $placement === 'global';
$is_targeted = !$is_sitewide && !$is_global;
$pt_values = $placement_targets['post_types'] ?? [];
$tpl_values = $placement_targets['page_templates'] ?? [];
?>
<section class="ns-wizard__panel" data-panel="2" aria-labelledby="ns-step-2-heading" hidden>
    <h2 id="ns-step-2-heading" class="ns-wizard__panel-title">
        <?php esc_html_e('Where should this schema appear?', 'noble-schema'); ?>
    </h2>
    <p class="ns-wizard__panel-desc">
        <?php esc_html_e('Let’s configure where your schema should output. Choose sitewide coverage, all pages with page-specific fields, or target any combination of post types, page templates, archives, specialty pages and posts.', 'noble-schema'); ?>
    </p>

    <div class="ns-field-row">
        <div class="ns-field-row__label">
            <span class="ns-badge ns-badge--required"><?php esc_html_e('Required', 'noble-schema'); ?></span>
            <span class="ns-field-row__heading"><?php esc_html_e('Placement', 'noble-schema'); ?></span>
        </div>
        <div class="ns-field-row__control">
            <fieldset class="ns-placement-mode">
                <legend class="screen-reader-text"><?php esc_html_e('Placement mode', 'noble-schema'); ?></legend>
                <label class="ns-placement-mode__option">
                    <input
                        type="radio"
                        name="placement"
                        value="sitewide"
                        id="ns-placement-sitewide"
                        <?php checked($is_sitewide); ?>
                    >
                    <span class="ns-placement-mode__title"><?php esc_html_e('Sitewide', 'noble-schema'); ?></span>
                    <span class="ns-placement-mode__help"><?php esc_html_e('Same schema on every page (not page-specific). Best for Organization, WebSite, etc.', 'noble-schema'); ?></span>
                </label>
                <label class="ns-placement-mode__option">
                    <input
                        type="radio"
                        name="placement"
                        value="global"
                        id="ns-placement-global"
                        <?php checked($is_global); ?>
                    >
                    <span class="ns-placement-mode__title"><?php esc_html_e('All pages', 'noble-schema'); ?></span>
                    <span class="ns-placement-mode__help"><?php esc_html_e('Every page, with page-specific field values. Best for WebPage, etc.', 'noble-schema'); ?></span>
                </label>
                <label class="ns-placement-mode__option">
                    <input
                        type="radio"
                        name="placement"
                        value="targeted"
                        id="ns-placement-targeted"
                        <?php checked($is_targeted); ?>
                    >
                    <span class="ns-placement-mode__title"><?php esc_html_e('Targeted', 'noble-schema'); ?></span>
                    <span class="ns-placement-mode__help"><?php esc_html_e('Choose post types, templates, archives, specialty pages and/or posts.', 'noble-schema'); ?></span>
                </label>
            </fieldset>
            <p class="ns-field-error" id="ns-placement-error" role="alert" hidden></p>
        </div>
    </div>

    <div class="ns-placement-targets" id="ns-placement-targets"<?php echo $is_targeted ? '' : ' hidden'; ?>>
        <div class="ns-field-row">
            <div class="ns-field-row__label">
                <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                <label for="ns-post-types-input"><?php esc_html_e('Post types', 'noble-schema'); ?></label>
            </div>
            <div class="ns-field-row__control">
                <div
                    class="ns-multiselect"
                    id="ns-post-types"
                    data-name="target_post_types[]"
                    data-placeholder="<?php esc_attr_e('Search post types…', 'noble-schema'); ?>"
                >
                    <div class="ns-multiselect__pills" hidden></div>
                    <div class="ns-multiselect__control">
                        <input
                            type="text"
                            id="ns-post-types-input"
                            class="ns-multiselect__input regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e('Search post types…', 'noble-schema'); ?>"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="ns-post-types-list"
                        >
                        <ul class="ns-multiselect__dropdown" id="ns-post-types-list" role="listbox" hidden></ul>
                    </div>
                    <div class="ns-multiselect__values"></div>
                    <script type="application/json" class="ns-multiselect__options"><?php
                        echo wp_json_encode(array_map(
                            static fn ($slug, $pt) => ['value' => $slug, 'label' => $pt->labels->singular_name],
                            array_keys($post_types),
                            array_values($post_types)
                        ));
                    ?></script>
                    <script type="application/json" class="ns-multiselect__selected"><?php
                        echo wp_json_encode(array_values($pt_values));
                    ?></script>
                </div>
                <p class="ns-field-error" id="ns-post-types-error" role="alert" hidden></p>
            </div>
        </div>

        <div class="ns-field-row">
            <div class="ns-field-row__label">
                <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                <label for="ns-page-templates-input"><?php esc_html_e('Page templates', 'noble-schema'); ?></label>
            </div>
            <div class="ns-field-row__control">
                <div
                    class="ns-multiselect"
                    id="ns-page-templates"
                    data-name="target_page_templates[]"
                    data-placeholder="<?php esc_attr_e('Search page templates…', 'noble-schema'); ?>"
                >
                    <div class="ns-multiselect__pills" hidden></div>
                    <div class="ns-multiselect__control">
                        <input
                            type="text"
                            id="ns-page-templates-input"
                            class="ns-multiselect__input regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e('Search page templates…', 'noble-schema'); ?>"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="ns-page-templates-list"
                        >
                        <ul class="ns-multiselect__dropdown" id="ns-page-templates-list" role="listbox" hidden></ul>
                    </div>
                    <div class="ns-multiselect__values"></div>
                    <script type="application/json" class="ns-multiselect__options"><?php
                        $tpl_opts = [];
                        foreach ($page_templates as $file => $label) {
                            $tpl_opts[] = ['value' => $file, 'label' => $label];
                        }
                        echo wp_json_encode($tpl_opts);
                    ?></script>
                    <script type="application/json" class="ns-multiselect__selected"><?php
                        echo wp_json_encode(array_values($tpl_values));
                    ?></script>
                </div>
                <p class="ns-field-error" id="ns-page-templates-error" role="alert" hidden></p>
            </div>
        </div>

        <?php
        $archive_options = $archive_pages ?? [];
        $archive_values = $placement_targets['archives'] ?? [];
        if ($archive_options !== []) :
            ?>
        <div class="ns-field-row">
            <div class="ns-field-row__label">
                <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                <label for="ns-archives-input"><?php esc_html_e('Archive pages', 'noble-schema'); ?></label>
            </div>
            <div class="ns-field-row__control">
                <div
                    class="ns-multiselect"
                    id="ns-archives"
                    data-name="target_archives[]"
                    data-placeholder="<?php esc_attr_e('Search archive pages…', 'noble-schema'); ?>"
                >
                    <div class="ns-multiselect__pills" hidden></div>
                    <div class="ns-multiselect__control">
                        <input
                            type="text"
                            id="ns-archives-input"
                            class="ns-multiselect__input regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e('Search archive pages…', 'noble-schema'); ?>"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="ns-archives-list"
                        >
                        <ul class="ns-multiselect__dropdown" id="ns-archives-list" role="listbox" hidden></ul>
                    </div>
                    <div class="ns-multiselect__values"></div>
                    <script type="application/json" class="ns-multiselect__options"><?php
                        echo wp_json_encode(array_values($archive_options));
                    ?></script>
                    <script type="application/json" class="ns-multiselect__selected"><?php
                        echo wp_json_encode(array_values($archive_values));
                    ?></script>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $specialty_options = $specialty_pages ?? \NobleSchema\Targeting\Specialty_Context::available();
        $specialty_values = $placement_targets['specialties'] ?? [];
        ?>
        <div class="ns-field-row">
            <div class="ns-field-row__label">
                <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                <label for="ns-specialties-input"><?php esc_html_e('Specialty pages', 'noble-schema'); ?></label>
            </div>
            <div class="ns-field-row__control">
                <div
                    class="ns-multiselect"
                    id="ns-specialties"
                    data-name="target_specialties[]"
                    data-placeholder="<?php esc_attr_e('Search specialty pages…', 'noble-schema'); ?>"
                >
                    <div class="ns-multiselect__pills" hidden></div>
                    <div class="ns-multiselect__control">
                        <input
                            type="text"
                            id="ns-specialties-input"
                            class="ns-multiselect__input regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e('Search specialty pages…', 'noble-schema'); ?>"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="ns-specialties-list"
                        >
                        <ul class="ns-multiselect__dropdown" id="ns-specialties-list" role="listbox" hidden></ul>
                    </div>
                    <div class="ns-multiselect__values"></div>
                    <script type="application/json" class="ns-multiselect__options"><?php
                        echo wp_json_encode(array_map(
                            static fn ($opt) => [
                                'value' => $opt['value'],
                                'label' => $opt['label'],
                            ],
                            array_values($specialty_options)
                        ));
                    ?></script>
                    <script type="application/json" class="ns-multiselect__selected"><?php
                        echo wp_json_encode(array_values($specialty_values));
                    ?></script>
                </div>
            </div>
        </div>

        <div class="ns-field-row">
            <div class="ns-field-row__label">
                <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                <label for="ns-posts-input"><?php esc_html_e('Specific posts', 'noble-schema'); ?></label>
            </div>
            <div class="ns-field-row__control">
                <div
                    class="ns-multiselect"
                    id="ns-posts"
                    data-name="target_posts[]"
                    data-remote="posts"
                    data-placeholder="<?php esc_attr_e('Search by title or post ID…', 'noble-schema'); ?>"
                >
                    <div class="ns-multiselect__pills" hidden></div>
                    <div class="ns-multiselect__control">
                        <input
                            type="text"
                            id="ns-posts-input"
                            class="ns-multiselect__input regular-text"
                            autocomplete="off"
                            placeholder="<?php esc_attr_e('Search by title or post ID…', 'noble-schema'); ?>"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="ns-posts-list"
                        >
                        <ul class="ns-multiselect__dropdown" id="ns-posts-list" role="listbox" hidden></ul>
                    </div>
                    <div class="ns-multiselect__values"></div>
                    <script type="application/json" class="ns-multiselect__options"><?php
                        echo wp_json_encode(array_values($selected_post_options ?? []));
                    ?></script>
                    <script type="application/json" class="ns-multiselect__selected"><?php
                        echo wp_json_encode(array_values($placement_targets['posts'] ?? []));
                    ?></script>
                </div>
            </div>
        </div>
    </div>

    <div class="ns-target-section" id="ns-additional-filters"<?php echo $is_sitewide ? ' hidden' : ''; ?>>
        <h3 class="ns-target-section__title"><?php esc_html_e('Additional filters', 'noble-schema'); ?></h3>
        <p class="description">
            <?php esc_html_e('Apply additional filters to further refine your content selection.', 'noble-schema'); ?>
        </p>

        <?php
        $filter_groups = $placement_targets['filter_groups'] ?? [];
        if ($filter_groups === []) {
            $filter_groups = [[]];
        }
        $post_meta_operators = [
            'is'           => __('is equal to', 'noble-schema'),
            'is_not'       => __('is not equal to', 'noble-schema'),
            'contains'     => __('contains', 'noble-schema'),
            'not_contains' => __('does not contain', 'noble-schema'),
            'exists'       => __('exists', 'noble-schema'),
            'not_exists'   => __('does not exist', 'noble-schema'),
            'is_empty'     => __('is empty', 'noble-schema'),
            'is_not_empty' => __('is not empty', 'noble-schema'),
            'gt'           => __('is greater than', 'noble-schema'),
            'gte'          => __('is greater than or equal to', 'noble-schema'),
            'lt'           => __('is less than', 'noble-schema'),
            'lte'           => __('is less than or equal to', 'noble-schema'),
        ];
        $meta_no_value_ops = ['exists', 'not_exists', 'is_empty', 'is_not_empty'];
        $wpml_languages = is_array($wpml_languages ?? null) ? $wpml_languages : [];
        $wpml_enabled = $wpml_languages !== [];
        $language_operators = [
            'is'     => __('is equal to', 'noble-schema'),
            'is_not' => __('is not equal to', 'noble-schema'),
        ];
        ?>
        <div class="ns-rule-groups" id="ns-rule-groups">
            <?php foreach ($filter_groups as $group_index => $group_rules) : ?>
                <?php if ($group_index > 0) : ?>
                    <p class="ns-rule-groups__or"><?php esc_html_e('or', 'noble-schema'); ?></p>
                <?php endif; ?>
                <div class="ns-rule-group" data-group-index="<?php echo (int) $group_index; ?>">
                    <div class="ns-rule-group__rules">
                        <?php
                        if ($group_rules === []) {
                            $group_rules = [['type' => 'taxonomy', 'taxonomy' => '', 'term' => '', 'operator' => 'is']];
                        }
                        foreach ($group_rules as $rule_index => $rule) :
                            $raw_type = (string) ($rule['type'] ?? 'taxonomy');
                            $allowed_types = ['taxonomy', 'has_block', 'post_meta'];
                            if ($wpml_enabled || $raw_type === 'language') {
                                $allowed_types[] = 'language';
                            }
                            $rule_type = in_array($raw_type, $allowed_types, true)
                                ? $raw_type
                                : 'taxonomy';
                            $meta_operator = (string) ($rule['operator'] ?? 'is');
                            if ($rule_type === 'post_meta' && !isset($post_meta_operators[$meta_operator])) {
                                $meta_operator = 'is';
                            }
                            $language_operator = (string) ($rule['operator'] ?? 'is');
                            if ($rule_type === 'language' && !isset($language_operators[$language_operator])) {
                                $language_operator = 'is';
                            }
                            $meta_value_hidden = $rule_type === 'post_meta' && in_array($meta_operator, $meta_no_value_ops, true);
                            $show_language_fields = $wpml_enabled || $rule_type === 'language';
                            ?>
                            <div class="ns-rule-row" data-rule-type="<?php echo esc_attr($rule_type); ?>">
                                <select class="ns-rule-type" aria-label="<?php esc_attr_e('Rule type', 'noble-schema'); ?>">
                                    <option value="taxonomy" <?php selected($rule_type, 'taxonomy'); ?>><?php esc_html_e('Taxonomy', 'noble-schema'); ?></option>
                                    <option value="has_block" <?php selected($rule_type, 'has_block'); ?>><?php esc_html_e('Has block', 'noble-schema'); ?></option>
                                    <option value="post_meta" <?php selected($rule_type, 'post_meta'); ?>><?php esc_html_e('Post meta', 'noble-schema'); ?></option>
                                    <?php if ($wpml_enabled || $rule_type === 'language') : ?>
                                        <option value="language" <?php selected($rule_type, 'language'); ?>><?php esc_html_e('Is in language', 'noble-schema'); ?></option>
                                    <?php endif; ?>
                                </select>                                <div class="ns-rule-fields ns-rule-fields--taxonomy"<?php echo $rule_type !== 'taxonomy' ? ' hidden' : ''; ?>>
                                    <select class="ns-rule-taxonomy" aria-label="<?php esc_attr_e('Taxonomy', 'noble-schema'); ?>">
                                        <option value=""><?php esc_html_e('Select taxonomy…', 'noble-schema'); ?></option>
                                        <?php foreach ($taxonomies as $tax_slug => $tax) : ?>
                                            <option value="<?php echo esc_attr($tax_slug); ?>" <?php selected(($rule['taxonomy'] ?? ''), $tax_slug); ?>>
                                                <?php echo esc_html($tax->labels->singular_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="ns-rule-operator" aria-label="<?php esc_attr_e('Operator', 'noble-schema'); ?>">
                                        <option value="is" <?php selected(($rule['operator'] ?? 'is'), 'is'); ?>><?php esc_html_e('is equal to', 'noble-schema'); ?></option>
                                        <option value="is_not" <?php selected(($rule['operator'] ?? 'is'), 'is_not'); ?>><?php esc_html_e('is not equal to', 'noble-schema'); ?></option>
                                    </select>
                                    <div class="ns-search-select" data-search-mode="terms">
                                        <div class="ns-search-select__control">
                                            <input
                                                type="text"
                                                class="ns-search-select__input regular-text"
                                                autocomplete="off"
                                                placeholder="<?php esc_attr_e('Search terms…', 'noble-schema'); ?>"
                                                <?php echo empty($rule['taxonomy']) ? ' disabled' : ''; ?>
                                            >
                                            <button type="button" class="ns-search-select__clear" hidden aria-label="<?php esc_attr_e('Clear selection', 'noble-schema'); ?>">&times;</button>
                                            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
                                        </div>
                                        <input type="hidden" class="ns-rule-term" value="<?php echo esc_attr((string) ($rule['term_id'] ?? $rule['term'] ?? '')); ?>" data-label="<?php echo esc_attr((string) ($rule['term_label'] ?? '')); ?>">
                                    </div>
                                </div>
                                <div class="ns-rule-fields ns-rule-fields--has_block"<?php echo $rule_type !== 'has_block' ? ' hidden' : ''; ?>>
                                    <div class="ns-search-select" data-search-mode="blocks" data-allow-custom="1">
                                        <div class="ns-search-select__control">
                                            <input
                                                type="text"
                                                class="ns-search-select__input regular-text"
                                                autocomplete="off"
                                                placeholder="<?php esc_attr_e('Search block name…', 'noble-schema'); ?>"
                                            >
                                            <button type="button" class="ns-search-select__clear" hidden aria-label="<?php esc_attr_e('Clear selection', 'noble-schema'); ?>">&times;</button>
                                            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
                                        </div>
                                        <input type="hidden" class="ns-rule-block" value="<?php echo esc_attr((string) ($rule['block'] ?? '')); ?>">
                                    </div>
                                </div>
                                <div class="ns-rule-fields ns-rule-fields--post_meta"<?php echo $rule_type !== 'post_meta' ? ' hidden' : ''; ?>>
                                    <div class="ns-search-select" data-search-mode="meta_keys" data-allow-custom="1">
                                        <div class="ns-search-select__control">
                                            <input
                                                type="text"
                                                class="ns-search-select__input regular-text"
                                                autocomplete="off"
                                                placeholder="<?php esc_attr_e('Search meta key…', 'noble-schema'); ?>"
                                            >
                                            <button type="button" class="ns-search-select__clear" hidden aria-label="<?php esc_attr_e('Clear selection', 'noble-schema'); ?>">&times;</button>
                                            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
                                        </div>
                                        <input type="hidden" class="ns-rule-meta-key" value="<?php echo esc_attr((string) ($rule['key'] ?? '')); ?>">
                                    </div>
                                    <select class="ns-rule-meta-operator" aria-label="<?php esc_attr_e('Meta operator', 'noble-schema'); ?>">
                                        <?php foreach ($post_meta_operators as $op_value => $op_label) : ?>
                                            <option value="<?php echo esc_attr($op_value); ?>" <?php selected($meta_operator, $op_value); ?>>
                                                <?php echo esc_html($op_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input
                                        type="text"
                                        class="ns-rule-meta-value regular-text"
                                        value="<?php echo esc_attr((string) ($rule['value'] ?? '')); ?>"
                                        placeholder="<?php esc_attr_e('Value', 'noble-schema'); ?>"
                                        aria-label="<?php esc_attr_e('Meta value', 'noble-schema'); ?>"
                                        <?php echo $meta_value_hidden ? ' hidden' : ''; ?>
                                    >
                                </div>
                                <?php if ($show_language_fields) : ?>
                                    <div class="ns-rule-fields ns-rule-fields--language"<?php echo $rule_type !== 'language' ? ' hidden' : ''; ?>>
                                        <select class="ns-rule-language-operator" aria-label="<?php esc_attr_e('Language operator', 'noble-schema'); ?>">
                                            <?php foreach ($language_operators as $op_value => $op_label) : ?>
                                                <option value="<?php echo esc_attr($op_value); ?>" <?php selected($language_operator, $op_value); ?>>
                                                    <?php echo esc_html($op_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select class="ns-rule-language" aria-label="<?php esc_attr_e('Language', 'noble-schema'); ?>">
                                            <option value=""><?php esc_html_e('Select language…', 'noble-schema'); ?></option>
                                            <?php
                                            $selected_language = (string) ($rule['language'] ?? '');
                                            $language_options = $wpml_languages;
                                            if ($selected_language !== '' && !isset($language_options[$selected_language])) {
                                                $language_options[$selected_language] = $selected_language;
                                            }
                                            foreach ($language_options as $lang_code => $lang_label) :
                                                ?>
                                                <option value="<?php echo esc_attr((string) $lang_code); ?>" <?php selected($selected_language, (string) $lang_code); ?>>
                                                    <?php echo esc_html((string) $lang_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <button type="button" class="button ns-rule-and"><?php esc_html_e('and', 'noble-schema'); ?></button>
                                <button type="button" class="ns-icon-delete ns-rule-remove" aria-label="<?php esc_attr_e('Remove rule', 'noble-schema'); ?>">
                                    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                                        <path fill="currentColor" d="M6.5 3.5A1.5 1.5 0 0 1 8 2h4a1.5 1.5 0 0 1 1.5 1.5V4H16a1 1 0 1 1 0 2h-.5l-.7 10.1A2 2 0 0 1 12.8 18H7.2a2 2 0 0 1-2-1.9L4.5 6H4a1 1 0 0 1 0-2h2.5V3.5ZM8 4h4V3.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5V4Zm-.4 3.1a1 1 0 0 1 1 .9l.3 7a1 1 0 1 1-2 .1l-.3-7a1 1 0 0 1 1-1Zm4.8 0a1 1 0 0 1 1 1l-.3 7a1 1 0 1 1-2-.1l.3-7a1 1 0 0 1 1-.9Z"/>
                                    </svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button ns-add-rule-group" id="ns-add-rule-group">
            <?php esc_html_e('Add rule group', 'noble-schema'); ?>
        </button>
        <div class="ns-filter-values" id="ns-filter-values" hidden></div>
    </div>

    <div class="ns-target-summary" aria-live="polite">
        <span class="ns-target-summary__label"><?php esc_html_e('Summary', 'noble-schema'); ?>:</span>
        <span class="ns-target-summary__chips"></span>
        <span id="ns-target-summary-count" class="ns-chip ns-chip--count" hidden></span>
    </div>
</section>

<template id="ns-rule-row-template">
    <div class="ns-rule-row" data-rule-type="taxonomy">
        <select class="ns-rule-type" aria-label="<?php esc_attr_e('Rule type', 'noble-schema'); ?>">
            <option value="taxonomy"><?php esc_html_e('Taxonomy', 'noble-schema'); ?></option>
            <option value="has_block"><?php esc_html_e('Has block', 'noble-schema'); ?></option>
            <option value="post_meta"><?php esc_html_e('Post meta', 'noble-schema'); ?></option>
            <?php if ($wpml_enabled) : ?>
                <option value="language"><?php esc_html_e('Is in language', 'noble-schema'); ?></option>
            <?php endif; ?>
        </select>
        <div class="ns-rule-fields ns-rule-fields--taxonomy">
            <select class="ns-rule-taxonomy" aria-label="<?php esc_attr_e('Taxonomy', 'noble-schema'); ?>">
                <option value=""><?php esc_html_e('Select taxonomy…', 'noble-schema'); ?></option>
                <?php foreach ($taxonomies as $tax_slug => $tax) : ?>
                    <option value="<?php echo esc_attr($tax_slug); ?>"><?php echo esc_html($tax->labels->singular_name); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="ns-rule-operator" aria-label="<?php esc_attr_e('Operator', 'noble-schema'); ?>">
                <option value="is"><?php esc_html_e('is equal to', 'noble-schema'); ?></option>
                <option value="is_not"><?php esc_html_e('is not equal to', 'noble-schema'); ?></option>
            </select>
            <div class="ns-search-select" data-search-mode="terms">
                <div class="ns-search-select__control">
                    <input type="text" class="ns-search-select__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search terms…', 'noble-schema'); ?>" disabled>
                    <button type="button" class="ns-search-select__clear" hidden aria-label="<?php esc_attr_e('Clear selection', 'noble-schema'); ?>">&times;</button>
                    <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
                </div>
                <input type="hidden" class="ns-rule-term" value="">
            </div>
        </div>
        <div class="ns-rule-fields ns-rule-fields--has_block" hidden>
            <div class="ns-search-select" data-search-mode="blocks" data-allow-custom="1">
                <div class="ns-search-select__control">
                    <input type="text" class="ns-search-select__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search block name…', 'noble-schema'); ?>">
                    <button type="button" class="ns-search-select__clear" hidden aria-label="<?php esc_attr_e('Clear selection', 'noble-schema'); ?>">&times;</button>
                    <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
                </div>
                <input type="hidden" class="ns-rule-block" value="">
            </div>
        </div>
        <div class="ns-rule-fields ns-rule-fields--post_meta" hidden>
            <div class="ns-search-select" data-search-mode="meta_keys" data-allow-custom="1">
                <div class="ns-search-select__control">
                    <input type="text" class="ns-search-select__input regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Search meta key…', 'noble-schema'); ?>">
                    <button type="button" class="ns-search-select__clear" hidden aria-label="<?php esc_attr_e('Clear selection', 'noble-schema'); ?>">&times;</button>
                    <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
                </div>
                <input type="hidden" class="ns-rule-meta-key" value="">
            </div>
            <select class="ns-rule-meta-operator" aria-label="<?php esc_attr_e('Meta operator', 'noble-schema'); ?>">
                <?php foreach ($post_meta_operators as $op_value => $op_label) : ?>
                    <option value="<?php echo esc_attr($op_value); ?>"><?php echo esc_html($op_label); ?></option>
                <?php endforeach; ?>
            </select>
            <input
                type="text"
                class="ns-rule-meta-value regular-text"
                value=""
                placeholder="<?php esc_attr_e('Value', 'noble-schema'); ?>"
                aria-label="<?php esc_attr_e('Meta value', 'noble-schema'); ?>"
            >
        </div>
        <?php if ($wpml_enabled) : ?>
            <div class="ns-rule-fields ns-rule-fields--language" hidden>
                <select class="ns-rule-language-operator" aria-label="<?php esc_attr_e('Language operator', 'noble-schema'); ?>">
                    <?php foreach ($language_operators as $op_value => $op_label) : ?>
                        <option value="<?php echo esc_attr($op_value); ?>"><?php echo esc_html($op_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="ns-rule-language" aria-label="<?php esc_attr_e('Language', 'noble-schema'); ?>">
                    <option value=""><?php esc_html_e('Select language…', 'noble-schema'); ?></option>
                    <?php foreach ($wpml_languages as $lang_code => $lang_label) : ?>
                        <option value="<?php echo esc_attr((string) $lang_code); ?>"><?php echo esc_html((string) $lang_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <button type="button" class="button ns-rule-and"><?php esc_html_e('and', 'noble-schema'); ?></button>
        <button type="button" class="ns-icon-delete ns-rule-remove" aria-label="<?php esc_attr_e('Remove rule', 'noble-schema'); ?>">
            <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M6.5 3.5A1.5 1.5 0 0 1 8 2h4a1.5 1.5 0 0 1 1.5 1.5V4H16a1 1 0 1 1 0 2h-.5l-.7 10.1A2 2 0 0 1 12.8 18H7.2a2 2 0 0 1-2-1.9L4.5 6H4a1 1 0 0 1 0-2h2.5V3.5ZM8 4h4V3.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5V4Zm-.4 3.1a1 1 0 0 1 1 .9l.3 7a1 1 0 1 1-2 .1l-.3-7a1 1 0 0 1 1-1Zm4.8 0a1 1 0 0 1 1 1l-.3 7a1 1 0 0 1 1-.9Z"/>
            </svg>
        </button>
    </div>
</template>
