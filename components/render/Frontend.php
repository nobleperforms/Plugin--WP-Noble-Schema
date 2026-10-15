<?php

namespace NobleSchema\Render;

use NobleSchema\Db\Snapshot_Repository;
use NobleSchema\Targeting\Archive_Context;
use NobleSchema\Targeting\Specialty_Context;

class Frontend {
    private Snapshot_Repository $snapshots;

    public function __construct(Snapshot_Repository $snapshots) {
        $this->snapshots = $snapshots;
    }

    public function register(): void {
        add_action('wp_head', [$this, 'print_json_ld'], 1);
    }

    public function print_json_ld(): void {
        if (is_admin()) {
            return;
        }

        $this->print_sitewide_json_ld();

        if (is_singular()) {
            $snapshot = $this->snapshots->get(get_queried_object_id());
            if (!$snapshot || !(int) $snapshot->is_complete || $snapshot->graph_json === '') {
                return;
            }

            echo '<script type="application/ld+json">' . $snapshot->graph_json . '</script>' . "\n";
            return;
        }

        $archive_identity = Archive_Context::current_identity();
        if ($archive_identity !== null) {
            $json = \NobleSchema\plugin()->generator()->graph_for_archive($archive_identity);
            if (is_string($json) && $json !== '') {
                echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
            }

            return;
        }

        $specialty_key = Specialty_Context::current_key();
        if ($specialty_key === null) {
            return;
        }

        $json = \NobleSchema\plugin()->generator()->graph_for_specialty($specialty_key);
        if (!is_string($json) || $json === '') {
            return;
        }

        echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    private function print_sitewide_json_ld(): void {
        $graphs = \NobleSchema\plugin()->generator()->sitewide_graphs_for_request();
        foreach ($graphs as $json) {
            if (!is_string($json) || $json === '') {
                continue;
            }
            echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
        }
    }
}
