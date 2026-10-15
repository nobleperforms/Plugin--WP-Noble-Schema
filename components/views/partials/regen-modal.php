<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    id="ns-regen-modal"
    class="ns-regen-modal"
    hidden
    role="dialog"
    aria-modal="true"
    aria-labelledby="ns-regen-modal-title"
>
    <div class="ns-regen-modal__backdrop"></div>
    <div class="ns-regen-modal__dialog">
        <h2 id="ns-regen-modal-title" class="ns-regen-modal__title"></h2>
        <p class="ns-regen-modal__desc"></p>
        <div
            class="ns-regen-modal__bar"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="0"
            aria-labelledby="ns-regen-modal-title"
        >
            <div class="ns-regen-modal__bar-fill" style="width: 0%"></div>
        </div>
        <p class="ns-regen-modal__status" aria-live="polite"></p>
        <p class="ns-regen-modal__rescan" aria-live="polite" hidden></p>
        <div class="ns-regen-modal__actions">
            <button type="button" class="button ns-regen-modal__cancel">
                <?php esc_html_e('Cancel', 'noble-schema'); ?>
            </button>
            <button type="button" class="button ns-regen-modal__retry" hidden>
                <?php esc_html_e('Retry', 'noble-schema'); ?>
            </button>
            <button type="button" class="button button-primary ns-regen-modal__close" hidden>
                <?php esc_html_e('Close', 'noble-schema'); ?>
            </button>
        </div>
    </div>
</div>
