<?php

declare(strict_types=1);

namespace NobleSchema\SchemaOrg;

use NobleSchema\Support\Paths;
use NobleSchema\Templates\Template_Loader;

/**
 * Builds and writes Noble Schema template JSON from Storefront selections.
 */
class Template_Factory {
    private string $custom_templates_dir;

    public function __construct(
        private string $templates_dir,
        ?string $custom_templates_dir = null
    ) {
        $this->custom_templates_dir = $custom_templates_dir !== null && $custom_templates_dir !== ''
            ? rtrim($custom_templates_dir, '/\\')
            : Paths::custom_templates_dir();
    }

    public static function slugify(string $schema_type): string {
        $schema_type = trim($schema_type);
        if ($schema_type === '') {
            return '';
        }

        // TouristDestination -> tourist-destination; FAQPage -> faq-page
        $slug = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $schema_type) ?? $schema_type;
        $slug = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $slug) ?? $slug;
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return $slug;
    }

    public static function is_valid_slug(string $slug): bool {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    /**
     * @param string[] $ranges
     */
    public static function value_type_from_ranges(string $property_id, array $ranges): string {
        $property_id = strtolower($property_id);
        $range_ids = array_map(static fn ($r) => strtolower((string) $r), $ranges);

        if (in_array($property_id, ['image', 'photo', 'logo', 'thumbnail'], true)
            || in_array('imageobject', $range_ids, true)
        ) {
            return 'image';
        }

        if (array_intersect($range_ids, ['date', 'datetime', 'time']) !== []) {
            return 'datetime';
        }

        if (in_array('postaladdress', $range_ids, true)) {
            return 'postal_address';
        }

        if (array_intersect($range_ids, ['geocoordinates', 'geoshape']) !== []) {
            return 'geo';
        }

        if (in_array('person', $range_ids, true)
            && in_array($property_id, ['author', 'creator', 'actor', 'director', 'founder', 'member'], true)
        ) {
            return 'person';
        }

        return 'text';
    }

    /**
     * @param array{
     *   id:string,
     *   label?:string,
     *   comment?:string,
     *   properties?:array<int, array{id:string,label?:string,comment?:string,ranges?:string[]}>
     * } $type
     * @param string[] $property_ids
     * @return array<string, mixed>
     */
    public function build(
        array $type,
        array $property_ids,
        string $slug = '',
        string $label = ''
    ): array {
        $schema_type = (string) ($type['id'] ?? '');
        if ($schema_type === '') {
            throw new \InvalidArgumentException('Type id is required.');
        }

        $property_ids = array_values(array_unique(array_filter(array_map('strval', $property_ids))));
        if ($property_ids === []) {
            throw new \InvalidArgumentException('Select at least one property.');
        }

        $slug = $slug !== '' ? $slug : self::slugify($schema_type);
        if (!self::is_valid_slug($slug)) {
            throw new \InvalidArgumentException('Slug must be kebab-case (a-z, 0-9, hyphens).');
        }

        $label = trim($label) !== '' ? trim($label) : (string) ($type['label'] ?? $schema_type);
        $by_id = [];
        foreach ((array) ($type['properties'] ?? []) as $prop) {
            if (!is_array($prop) || empty($prop['id'])) {
                continue;
            }
            $by_id[(string) $prop['id']] = $prop;
        }

        $fields = [];
        foreach ($property_ids as $prop_id) {
            $prop = $by_id[$prop_id] ?? [
                'id'      => $prop_id,
                'label'   => $prop_id,
                'comment' => '',
                'ranges'  => [],
            ];
            $ranges = array_values(array_map('strval', (array) ($prop['ranges'] ?? [])));
            $fields[] = [
                'path'       => $prop_id,
                'label'      => (string) ($prop['label'] ?? $prop_id),
                'required'   => $prop_id === 'name',
                'value_type' => self::value_type_from_ranges($prop_id, $ranges),
                'help'       => (string) ($prop['comment'] ?? ''),
            ];
        }

        $template = [
            'slug'        => $slug,
            'label'       => $label,
            'schema_type' => $schema_type,
            'schema_url'  => self::default_schema_url($schema_type),
            'description' => (string) ($type['comment'] ?? ''),
            'node'        => [
                '@type' => $schema_type,
            ],
            'fields'      => $fields,
        ];

        $loader = new Template_Loader($this->templates_dir, $this->custom_templates_dir);
        if (!$loader->validate($template)) {
            throw new \RuntimeException('Generated template failed validation.');
        }

        return $template;
    }

    public static function default_schema_url(string $schema_type): string {
        $schema_type = trim($schema_type);
        if ($schema_type === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $schema_type)) {
            return '';
        }

        return 'https://schema.org/' . $schema_type;
    }

    /**
     * Resolve documentation URL for a template's schema type.
     *
     * @param array<string, mixed> $template
     */
    public static function documentation_url(array $template, string $unused = ''): string {
        $schema_type = trim((string) ($template['schema_type'] ?? ''));
        $effective = $schema_type;
        $stored = trim((string) ($template['schema_url'] ?? ''));

        if ($effective !== '' && (
            $stored === ''
            || (bool) preg_match('#^https://schema\.org/[A-Za-z][A-Za-z0-9]*$#', $stored)
        )) {
            $derived = self::default_schema_url($effective);
            if ($derived !== '') {
                return $derived;
            }
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $template
     * @return array{path:string,overwrote:bool}
     */
    public function write(array $template): array {
        $slug = (string) ($template['slug'] ?? '');
        if (!self::is_valid_slug($slug)) {
            throw new \InvalidArgumentException('Invalid slug.');
        }

        $custom_dir = $this->custom_dir();
        if ($custom_dir === '') {
            throw new \RuntimeException('Custom templates directory is not configured.');
        }
        if (!is_dir($custom_dir) && !wp_mkdir_p($custom_dir)) {
            throw new \RuntimeException('Could not create custom templates directory in uploads.');
        }

        $path = $this->custom_path($slug);
        $real_custom = realpath($custom_dir);
        if ($real_custom === false) {
            throw new \RuntimeException('Custom templates directory is not accessible.');
        }

        $overwrote = is_file($path);
        $json = wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Could not encode template JSON.');
        }

        $json .= "\n";
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Could not write template file.');
        }

        $real_path = realpath($path);
        if ($real_path === false || !str_starts_with($real_path, $real_custom . DIRECTORY_SEPARATOR)) {
            @unlink($path);
            throw new \RuntimeException('Refusing to write outside the custom templates directory.');
        }

        \NobleSchema\Tools\Log::info(
            $overwrote
                ? sprintf(
                    /* translators: %s: template slug */
                    __('Custom template updated: %s', 'noble-schema'),
                    $slug
                )
                : sprintf(
                    /* translators: %s: template slug */
                    __('Custom template installed: %s', 'noble-schema'),
                    $slug
                ),
            [
                'category' => $overwrote ? 'template_updated' : 'template_installed',
                'slug'     => $slug,
                'action'   => $overwrote ? 'update' : 'install',
            ]
        );

        return [
            'path'      => $path,
            'overwrote' => $overwrote,
        ];
    }

    public function custom_exists(string $slug): bool {
        return self::is_valid_slug($slug) && is_file($this->custom_path($slug));
    }

    /**
     * Whether the uploads custom templates dir can be created/written.
     */
    public function custom_writable(): bool {
        $custom_dir = $this->custom_dir();
        if ($custom_dir === '') {
            return false;
        }

        if (is_dir($custom_dir)) {
            return is_writable($custom_dir);
        }

        $parent = dirname($custom_dir);
        if (is_dir($parent)) {
            return is_writable($parent);
        }

        $grand = dirname($parent);
        return is_dir($grand) && is_writable($grand);
    }

    public function builtin_exists(string $slug): bool {
        if (!self::is_valid_slug($slug)) {
            return false;
        }
        return is_file($this->templates_dir . '/' . $slug . '.json');
    }

    /**
     * @return list<array{slug:string,label:string,schema_type:string}>
     */
    public function list_custom(): array {
        $dir = $this->custom_dir();
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $slug = (string) ($data['slug'] ?? '');
            if (!self::is_valid_slug($slug)) {
                $basename = basename($path, '.json');
                if (!self::is_valid_slug($basename)) {
                    continue;
                }
                $slug = $basename;
            }
            $items[] = [
                'slug'        => $slug,
                'label'       => (string) ($data['label'] ?? $slug),
                'schema_type' => (string) ($data['schema_type'] ?? ''),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcasecmp($a['label'], $b['label']);
        });

        return $items;
    }

    public function delete_custom(string $slug): bool {
        if (!self::is_valid_slug($slug)) {
            throw new \InvalidArgumentException('Invalid slug.');
        }

        $path = $this->custom_path($slug);
        if (!is_file($path)) {
            throw new \RuntimeException('Custom template not found.');
        }

        $custom_dir = $this->custom_dir();
        $real_custom = realpath($custom_dir);
        $real_path = realpath($path);
        if ($real_custom === false || $real_path === false
            || !str_starts_with($real_path, $real_custom . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException('Refusing to delete outside the custom templates directory.');
        }

        if (!unlink($path)) {
            throw new \RuntimeException('Could not delete template file.');
        }

        \NobleSchema\Tools\Log::info(
            sprintf(
                /* translators: %s: template slug */
                __('Custom template uninstalled: %s', 'noble-schema'),
                $slug
            ),
            [
                'category' => 'template_uninstalled',
                'slug'     => $slug,
                'action'   => 'uninstall',
            ]
        );

        return true;
    }

    /**
     * Delete every custom template file (and empty dir when possible).
     */
    public function delete_all_custom(): int {
        $dir = $this->custom_dir();
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $slug = basename($path, '.json');
            if (!self::is_valid_slug($slug)) {
                continue;
            }
            try {
                $this->delete_custom($slug);
                $count++;
            } catch (\Throwable $e) {
                // Continue deleting remaining files.
            }
        }

        // Remove empty templates dir and parent noble-schema/ when empty.
        $this->maybe_remove_empty_dirs($dir);

        return $count;
    }

    public function custom_path(string $slug): string {
        return $this->custom_dir() . '/' . $slug . '.json';
    }

    public function custom_dir(): string {
        return $this->custom_templates_dir;
    }

    private function maybe_remove_empty_dirs(string $custom_dir): void {
        if (is_dir($custom_dir)) {
            $left = glob($custom_dir . '/*') ?: [];
            if ($left === []) {
                @rmdir($custom_dir);
            }
        }

        $parent = dirname($custom_dir);
        if (is_dir($parent) && basename($parent) === 'noble-schema') {
            $left = glob($parent . '/*') ?: [];
            if ($left === []) {
                @rmdir($parent);
            }
        }
    }
}
