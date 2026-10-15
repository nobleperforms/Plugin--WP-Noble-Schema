<?php

declare(strict_types=1);

namespace NobleSchema\Admin;

use NobleSchema\Db\Config_Repository;
use NobleSchema\Db\Field_Repository;
use NobleSchema\Db\Manual_Value_Repository;
use NobleSchema\Db\Target_Repository;
use NobleSchema\Targeting\Matcher;
use NobleSchema\Templates\Template_Loader;

class Manual_Values_Meta_Box {
    public const NONCE_ACTION = 'ns_save_manual_values';
    public const NONCE_FIELD  = 'ns_manual_values_nonce';
    public const POST_KEY     = 'ns_manual_values';

    public function __construct(
        private Config_Repository $configs,
        private Target_Repository $targets,
        private Field_Repository $fields,
        private Manual_Value_Repository $manuals,
        private Template_Loader $templates,
        private Matcher $matcher,
    ) {}

    public function register(): void {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    }

    public function add_meta_boxes(): void {
        foreach ($this->public_post_types() as $post_type) {
            add_meta_box(
                'noble-schema-manual-values',
                __('Noble Schema Fields', 'noble-schema'),
                [$this, 'render'],
                $post_type,
                'normal',
                'default'
            );
        }
    }

    public function render(\WP_Post $post): void {
        $sections = $this->applicable_sections((int) $post->ID);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        if ($sections === []) {
            echo '<p class="description">' . esc_html__(
                'No manual schema fields apply to this post.',
                'noble-schema'
            ) . '</p>';

            return;
        }

        foreach ($sections as $section) {
            $config = $section['config'];
            $label  = (string) ($config->name ?? '');
            if ($label === '') {
                $label = (string) ($config->schema_type ?? __('Schema', 'noble-schema'));
            }

            echo '<div class="ns-manual-values-section" style="margin-bottom:1.5em;">';
            echo '<h4 style="margin:0 0 .75em;">' . esc_html($label) . '</h4>';
            echo '<table class="form-table" role="presentation"><tbody>';

            foreach ($section['fields'] as $field) {
                $this->render_field_row((int) $config->id, $field);
            }

            echo '</tbody></table></div>';
        }
    }

    /**
     * @return array<int, array{config: object, fields: array<int, array{map: object, def: array|null, value: mixed}>}>
     */
    public function applicable_sections(int $post_id): array {
        $context = Matcher::context_from_post($post_id);
        $stored  = $this->index_stored_values($post_id);
        $sections = [];

        foreach ($this->configs->all(['status' => 'active', 'orderby' => 'priority', 'order' => 'ASC']) as $config) {
            $config_id   = (int) $config->id;
            $target_rows = $this->targets->for_config($config_id);

            if (!$this->matcher->matches($config, $target_rows, $context)) {
                continue;
            }

            $manual_maps = array_values(array_filter(
                $this->fields->for_config($config_id),
                static fn(object $row): bool => ($row->source_type ?? '') === 'manual_post'
            ));

            if ($manual_maps === []) {
                continue;
            }

            $template = $this->templates->get((string) ($config->template_slug ?? ''));
            $field_defs = [];

            if (is_array($template)) {
                foreach ($template['fields'] ?? [] as $def) {
                    if (is_array($def) && !empty($def['path'])) {
                        $field_defs[(string) $def['path']] = $def;
                    }
                }
            }

            $fields = [];
            foreach ($manual_maps as $map) {
                $field_path = (string) ($map->field_path ?? '');
                if ($field_path === '') {
                    continue;
                }

                $fields[] = [
                    'map'   => $map,
                    'def'   => $field_defs[$field_path] ?? null,
                    'value' => $stored[$config_id][$field_path] ?? null,
                ];
            }

            if ($fields !== []) {
                $sections[] = [
                    'config' => $config,
                    'fields' => $fields,
                ];
            }
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function save_from_request(int $post_id, array $raw): void {
        if (!isset($raw[self::POST_KEY]) || !is_array($raw[self::POST_KEY])) {
            return;
        }

        $submitted = $raw[self::POST_KEY];
        $sections  = $this->applicable_sections($post_id);

        foreach ($sections as $section) {
            $config_id = (int) $section['config']->id;
            $config_raw = $submitted[$config_id] ?? $submitted[(string) $config_id] ?? null;

            if (!is_array($config_raw)) {
                continue;
            }

            foreach ($section['fields'] as $field) {
                $field_path = (string) ($field['map']->field_path ?? '');
                if ($field_path === '' || !array_key_exists($field_path, $config_raw)) {
                    continue;
                }

                $value = $this->normalize_submitted_value(
                    $config_raw[$field_path],
                    (string) (($field['def']['value_type'] ?? '') ?: 'text')
                );

                $this->manuals->upsert([
                    'config_id'       => $config_id,
                    'post_id'         => $post_id,
                    'block_client_id' => null,
                    'field_path'      => $field_path,
                    'value'           => $value,
                ]);
            }
        }
    }

    /**
     * @param array{map: object, def: array|null, value: mixed} $field
     */
    private function render_field_row(int $config_id, array $field): void {
        $map        = $field['map'];
        $def        = is_array($field['def']) ? $field['def'] : [];
        $field_path = (string) ($map->field_path ?? '');
        $label      = (string) ($def['label'] ?? $field_path);
        $help       = (string) ($def['help'] ?? '');
        $required   = $this->is_required($def, $map);
        $value_type = (string) ($def['value_type'] ?? 'text');
        $name       = self::POST_KEY . '[' . $config_id . '][' . $field_path . ']';
        $value      = $field['value'];

        echo '<tr><th scope="row">';
        echo esc_html($label);
        if ($required) {
            echo ' <span class="required" style="color:#d63638;">*</span>';
        }
        echo '</th><td>';
        $this->render_input($name, $value_type, $value);
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private function render_input(string $name, string $value_type, mixed $value): void {
        switch ($value_type) {
            case 'question_array':
                $this->render_question_array($name, is_array($value) ? $value : []);
                break;
            case 'geo':
            case 'postal_address':
            case 'place':
                $encoded = is_array($value) ? wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) ($value ?? '');
                echo '<textarea name="' . esc_attr($name) . '" rows="6" class="large-text code">' . esc_textarea($encoded) . '</textarea>';
                echo '<p class="description">' . esc_html__('Enter valid JSON.', 'noble-schema') . '</p>';
                break;
            default:
                echo '<input type="text" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr(is_scalar($value) ? (string) $value : '') . '">';
                break;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function render_question_array(string $name, array $items): void {
        if ($items === []) {
            $items = [['question' => '', 'answer' => '']];
        }

        echo '<div class="ns-question-array" data-name="' . esc_attr($name) . '">';

        foreach ($items as $index => $item) {
            $question = is_array($item) ? (string) ($item['question'] ?? '') : '';
            $answer   = is_array($item) ? (string) ($item['answer'] ?? '') : '';

            echo '<div class="ns-question-row" style="margin-bottom:.75em;padding:.75em;border:1px solid #dcdcde;background:#fff;">';
            echo '<p><label><strong>' . esc_html__('Question', 'noble-schema') . '</strong><br>';
            echo '<input type="text" class="large-text" name="' . esc_attr($name . '[' . $index . '][question]') . '" value="' . esc_attr($question) . '"></label></p>';
            echo '<p style="margin-bottom:0;"><label><strong>' . esc_html__('Answer', 'noble-schema') . '</strong><br>';
            echo '<textarea name="' . esc_attr($name . '[' . $index . '][answer]') . '" rows="3" class="large-text">' . esc_textarea($answer) . '</textarea></label></p>';
            echo '</div>';
        }

        echo '<p><button type="button" class="button ns-add-question">' . esc_html__('Add question', 'noble-schema') . '</button></p>';
        echo '</div>';
        echo '<script>
        (function(){
            document.querySelectorAll(".ns-add-question").forEach(function(btn){
                if(btn.dataset.bound){return;}
                btn.dataset.bound="1";
                btn.addEventListener("click",function(){
                    var wrap=btn.closest(".ns-question-array");
                    if(!wrap){return;}
                    var index=wrap.querySelectorAll(".ns-question-row").length;
                    var name=wrap.getAttribute("data-name");
                    var row=document.createElement("div");
                    row.className="ns-question-row";
                    row.style.cssText="margin-bottom:.75em;padding:.75em;border:1px solid #dcdcde;background:#fff;";
                    row.innerHTML="<p><label><strong>Question<\/strong><br><input type=\\"text\\" class=\\"large-text\\" name=\\""+name+"["+index+"][question]\\" value=\\"\\"><\/label><\/p><p style=\\"margin-bottom:0;\\"><label><strong>Answer<\/strong><br><textarea name=\\""+name+"["+index+"][answer]\\" rows=\\"3\\" class=\\"large-text\\"><\/textarea><\/label><\/p>";
                    btn.parentNode.insertBefore(row, btn);
                });
            });
        })();
        </script>';
    }

    private function normalize_submitted_value(mixed $raw, string $value_type): mixed {
        switch ($value_type) {
            case 'question_array':
                if (!is_array($raw)) {
                    return [];
                }

                $items = [];
                foreach ($raw as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $items[] = [
                        'question' => sanitize_text_field((string) ($item['question'] ?? '')),
                        'answer'   => sanitize_textarea_field((string) ($item['answer'] ?? '')),
                    ];
                }

                return $items;
            case 'geo':
            case 'postal_address':
            case 'place':
                if (!is_string($raw)) {
                    return '';
                }

                $raw = trim($raw);
                if ($raw === '') {
                    return '';
                }

                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : sanitize_textarea_field($raw);
            default:
                return sanitize_text_field(is_scalar($raw) ? (string) $raw : '');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function index_stored_values(int $post_id): array {
        $indexed = [];

        foreach ($this->manuals->for_post($post_id) as $row) {
            $config_id  = (int) ($row->config_id ?? 0);
            $field_path = (string) ($row->field_path ?? '');

            if ($config_id <= 0 || $field_path === '') {
                continue;
            }

            $indexed[$config_id][$field_path] = $this->decode_manual_value($row->value ?? '');
        }

        return $indexed;
    }

    private function decode_manual_value(mixed $value): mixed {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $def
     */
    private function is_required(array $def, object $map): bool {
        if ($map->required_override !== null) {
            return (bool) (int) $map->required_override;
        }

        return (bool) ($def['required'] ?? false);
    }

    /**
     * @return string[]
     */
    private function public_post_types(): array {
        $types = get_post_types(['public' => true], 'names');

        unset($types['attachment']);

        return array_values($types);
    }
}
