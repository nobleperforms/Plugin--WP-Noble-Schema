<?php

if (!defined('ABSPATH')) {
    exit;
}

$selected_slug = $config ? (string) $config->template_slug : '';
$selected_name = $config ? (string) $config->name : '';
?>
<section class="ns-wizard__panel is-active" data-panel="1" aria-labelledby="ns-step-1-heading">
    <h2 id="ns-step-1-heading" class="ns-wizard__panel-title">
        <?php esc_html_e('Choose a schema template', 'noble-schema'); ?>
    </h2>
    <p class="ns-wizard__panel-desc">
        <?php esc_html_e('Select the Schema.org type that best matches the content you want to mark up. Need a more specific type? Install it from Schema Storefront.', 'noble-schema'); ?>
    </p>

    <div class="ns-field-row">
        <div class="ns-field-row__label">
            <span class="ns-badge ns-badge--required"><?php esc_html_e('Required', 'noble-schema'); ?></span>
            <label for="ns-config-name"><?php esc_html_e('Config name', 'noble-schema'); ?></label>
        </div>
        <div class="ns-field-row__control">
            <input
                type="text"
                id="ns-config-name"
                name="name"
                class="regular-text"
                value="<?php echo esc_attr($selected_name); ?>"
                required
                autocomplete="off"
                aria-describedby="ns-config-name-error"
            >
            <p class="ns-field-error" id="ns-config-name-error" role="alert" hidden></p>
        </div>
    </div>

    <?php
    $strict_validation = !$config || !isset($config->strict_validation) || (int) $config->strict_validation === 1;
    ?>
    <div class="ns-field-row">
        <div class="ns-field-row__label">
            <span class="ns-badge ns-badge--optional"><?php esc_html_e('Optional', 'noble-schema'); ?></span>
            <label for="ns-strict-validation"><?php esc_html_e('Strict schema validation', 'noble-schema'); ?></label>
        </div>
        <div class="ns-field-row__control">
            <label class="ns-wizard__enabled" for="ns-strict-validation">
                <span class="ns-wizard__switch">
                    <input
                        type="checkbox"
                        name="strict_validation"
                        value="1"
                        id="ns-strict-validation"
                        <?php checked($strict_validation); ?>
                    >
                    <span class="ns-wizard__switch-ui" aria-hidden="true"></span>
                </span>
                <span class="ns-wizard__enabled-label"><?php esc_html_e('Enable strict schema validation?', 'noble-schema'); ?></span>
            </label>
            <p class="description">
                <?php esc_html_e('When enabled, this config is omitted from output if any required field resolves empty. When disabled, available fields are still outputted and missing required fields are skipped.', 'noble-schema'); ?>
            </p>
        </div>
    </div>

    <fieldset class="ns-template-cards" id="ns-template-fieldset" aria-describedby="ns-template-error">
        <legend class="screen-reader-text"><?php esc_html_e('Schema template', 'noble-schema'); ?></legend>
        <?php foreach ($templates as $slug => $template) : ?>
            <?php $checked = $selected_slug === $slug; ?>
            <label class="ns-template-card<?php echo $checked ? ' is-selected' : ''; ?>">
                <input
                    type="radio"
                    name="template_slug"
                    value="<?php echo esc_attr($slug); ?>"
                    <?php checked($checked); ?>
                    data-schema-type="<?php echo esc_attr($template['schema_type']); ?>"
                >
                <span class="ns-template-card__body">
                    <strong class="ns-template-card__title"><?php echo esc_html($template['label']); ?></strong>
                    <?php if (!empty($template['description'])) : ?>
                        <span class="ns-template-card__desc"><?php echo esc_html($template['description']); ?></span>
                    <?php endif; ?>
                    <?php
                    $fields = is_array($template['fields'] ?? null) ? $template['fields'] : [];
                    $field_count = count($fields);
                    $required_count = 0;
                    foreach ($fields as $field) {
                        if (!empty($field['required'])) {
                            $required_count++;
                        }
                    }
                    ?>
                    <span class="ns-template-card__meta">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: total field count, 2: required field count */
                                _n('%1$d field (%2$d required)', '%1$d fields (%2$d required)', $field_count, 'noble-schema'),
                                $field_count,
                                $required_count
                            )
                        );
                        ?>
                    </span>
                </span>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <p class="ns-field-error" id="ns-template-error" role="alert" hidden></p>
</section>
