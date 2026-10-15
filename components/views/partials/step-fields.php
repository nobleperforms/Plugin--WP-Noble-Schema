<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="ns-wizard__panel" data-panel="3" aria-labelledby="ns-step-3-heading" hidden>
    <h2 id="ns-step-3-heading" class="ns-wizard__panel-title">
        <?php esc_html_e('Map field sources', 'noble-schema'); ?>
    </h2>
    <p class="ns-wizard__panel-desc">
        <?php esc_html_e('Connect each schema field to a WordPress data source. Required fields must have a complete source mapping before saving.', 'noble-schema'); ?>
    </p>
    <p class="ns-schema-docs" id="ns-schema-docs" hidden>
        <a
            id="ns-schema-docs-link"
            class="ns-schema-docs__link"
            href="#"
            target="_blank"
            rel="noopener noreferrer"
        ></a>
    </p>

    <div id="ns-field-mappings" class="ns-field-mappings">
        <p class="ns-field-mappings__empty">
            <?php esc_html_e('Select a template in step 1 to configure field mappings.', 'noble-schema'); ?>
        </p>
    </div>
</section>

<template id="ns-field-row-template">
    <div class="ns-field-mapping" data-field-path="">
        <div class="ns-field-mapping__header">
            <div class="ns-field-mapping__label">
                <span class="ns-badge ns-badge--required ns-field-mapping__required" hidden><?php esc_html_e('Required', 'noble-schema'); ?></span>
                <span class="ns-badge ns-badge--recommended ns-field-mapping__recommended" hidden><?php esc_html_e('Recommended', 'noble-schema'); ?></span>
                <span class="ns-badge ns-badge--optional ns-field-mapping__optional" hidden><?php esc_html_e('Optional', 'noble-schema'); ?></span>
                <div class="ns-field-mapping__title">
                    <strong class="ns-field-mapping__name"></strong>
                    <span class="ns-field-mapping__type"></span>
                </div>
            </div>
            <p class="ns-field-mapping__help description"></p>
        </div>
        <div class="ns-field-row">
            <div class="ns-field-row__label">
                <label><?php esc_html_e('Source', 'noble-schema'); ?></label>
            </div>
            <div class="ns-field-row__control">
                <select class="ns-source-type" data-name="source_type">
                    <option value=""><?php esc_html_e('Select source…', 'noble-schema'); ?></option>
                    <?php foreach ($source_types as $type => $label) : ?>
                        <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="ns-source-config"></div>
        <p class="ns-field-error ns-field-mapping__error" role="alert" hidden></p>
    </div>
</template>
