<?php

namespace NobleSchema\Templates;

use NobleSchema\Support\Paths;
use NobleSchema\Tools\Log;

class Template_Loader {
    /** @var array<string, array>|null */
    private ?array $templates = null;
    private string $custom_templates_dir;

    public function __construct(
        private string $templates_dir,
        ?string $custom_templates_dir = null
    ) {
        $this->custom_templates_dir = $custom_templates_dir !== null && $custom_templates_dir !== ''
            ? rtrim($custom_templates_dir, '/\\')
            : Paths::custom_templates_dir();
    }

    /**
     * @return array<string, array>
     */
    public function all(): array {
        return $this->load();
    }

    public function get(string $slug): ?array {
        return $this->load()[$slug] ?? null;
    }

    public function validate(array $template): bool {
        foreach (['slug', 'label', 'schema_type', 'fields'] as $key) {
            if (!array_key_exists($key, $template)) {
                return false;
            }
        }

        if (!is_array($template['fields'])) {
            return false;
        }

        return $template['slug'] !== '' && $template['label'] !== '' && $template['schema_type'] !== '';
    }

    /**
     * @return array<string, array>
     */
    private function load(): array {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $this->templates = [];

        // Bundled templates first; custom uploads override same slug.
        $this->load_dir($this->templates_dir);
        if ($this->custom_templates_dir !== '') {
            $this->load_dir($this->custom_templates_dir);
        }

        return $this->templates;
    }

    private function load_dir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob(rtrim($dir, '/\\') . '/*.json') ?: [] as $path) {
            $this->register_file($path);
        }
    }

    private function register_file(string $path): void {
        $raw = file_get_contents($path);
        if ($raw === false) {
            Log::error('Failed to read template file', ['category' => 'template_load_failed', 'path' => $path]);
            return;
        }

        $template = json_decode($raw, true);
        if (!is_array($template)) {
            Log::error('Invalid template JSON', ['category' => 'template_load_failed', 'path' => $path]);
            return;
        }

        if (!$this->validate($template)) {
            Log::error('Template missing required keys', ['category' => 'template_load_failed', 'path' => $path]);
            return;
        }

        $this->templates[$template['slug']] = $template;
    }
}
