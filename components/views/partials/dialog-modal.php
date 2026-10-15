<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    id="ns-dialog"
    class="ns-dialog"
    hidden
    role="dialog"
    aria-modal="true"
    aria-labelledby="ns-dialog-title"
    aria-describedby="ns-dialog-message"
>
    <div class="ns-dialog__backdrop" data-ns-dialog-dismiss></div>
    <div class="ns-dialog__panel">
        <h2 id="ns-dialog-title" class="ns-dialog__title"></h2>
        <p id="ns-dialog-message" class="ns-dialog__message"></p>
        <ul id="ns-dialog-details" class="ns-dialog__details" hidden></ul>
        <div id="ns-dialog-confirm-phrase" class="ns-dialog__confirm-phrase" hidden>
            <label for="ns-dialog-confirm-input" class="ns-dialog__confirm-phrase-label">
                <?php
                printf(
                    /* translators: %s: confirmation phrase (e.g. DELETE, OFFBOARD) */
                    esc_html__('Type %s to confirm:', 'noble-schema'),
                    '<code id="ns-dialog-confirm-phrase-value">DELETE</code>'
                );
                ?>
            </label>
            <input
                id="ns-dialog-confirm-input"
                class="ns-dialog__confirm-input regular-text"
                type="text"
                autocomplete="off"
                spellcheck="false"
            >
        </div>
        <div class="ns-dialog__actions">
            <button type="button" class="button ns-dialog__cancel" data-ns-dialog-cancel hidden>
                <?php esc_html_e('Cancel', 'noble-schema'); ?>
            </button>
            <button type="button" class="button button-primary ns-dialog__confirm" data-ns-dialog-confirm>
                <?php esc_html_e('OK', 'noble-schema'); ?>
            </button>
        </div>
    </div>
</div>
