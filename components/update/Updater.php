<?php

declare(strict_types=1);

namespace NobleSchema\Update;

/**
 * Automatic plugin updates from a public or private GitHub repository release.
 */
class Updater {
    public const OPT_LAST_CHECK = 'ns_gh_update_last_check';
    public const OPT_PLUGIN_VERSION = 'ns_plugin_version';

    private string $github_username;
    private string $repo_name;
    private string $access_token;
    private string $plugin_file;
    private string $temp_dir;

    /**
     * @param array{file_path:string,github_username:string,repo_name:string,access_token?:string} $args
     */
    public function __construct(array $args) {
        $this->plugin_file = (string) ($args['file_path'] ?? '');
        $this->github_username = (string) ($args['github_username'] ?? '');
        $this->repo_name = (string) ($args['repo_name'] ?? '');
        $this->access_token = (string) ($args['access_token'] ?? '');
        $uploads = wp_upload_dir();
        $basedir = is_array($uploads) && !empty($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $this->temp_dir = $basedir !== '' ? $basedir . '/ns-plugin-updater/' : '';

        if ($this->plugin_file === '' || $this->github_username === '' || $this->repo_name === '') {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('upgrader_package_options', [$this, 'override_update_package']);
        add_filter('upgrader_source_selection', [$this, 'rename_package_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'on_upgrader_process_complete'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_release_details_link']);
    }

    /**
     * Detect a version bump and write a system log entry when logging is enabled.
     *
     * Seeds OPT_PLUGIN_VERSION on first run without logging. Safe to call on every boot.
     *
     * @return bool True when an update was logged.
     */
    public static function maybe_log_version_change(string $plugin_file, ?string $current_version = null): bool {
        if ($current_version === null) {
            if ($plugin_file === '' || !is_readable($plugin_file) || !function_exists('get_file_data')) {
                return false;
            }
            $plugin_data = get_file_data($plugin_file, ['Version' => 'Version']);
            $current_version = (string) ($plugin_data['Version'] ?? '');
        }

        $current_version = self::normalize_version($current_version);
        if ($current_version === '') {
            return false;
        }

        $previous = self::normalize_version((string) get_option(self::OPT_PLUGIN_VERSION, ''));
        if ($previous === '') {
            update_option(self::OPT_PLUGIN_VERSION, $current_version, false);
            return false;
        }

        if ($previous === $current_version) {
            return false;
        }

        \NobleSchema\Tools\Log::info(
            sprintf(
                /* translators: 1: previous plugin version, 2: new plugin version */
                __('Plugin updated from %1$s to %2$s', 'noble-schema'),
                $previous,
                $current_version
            ),
            [
                'category' => 'plugin_updated',
                'action'   => 'update',
                'from'     => $previous,
                'to'       => $current_version,
            ]
        );

        update_option(self::OPT_PLUGIN_VERSION, $current_version, false);

        return true;
    }

    /**
     * @param object|null $transient
     * @return object|null
     */
    public function check_for_update($transient = null) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $plugin_data = get_file_data($this->plugin_file, ['Version' => 'Version']);
        $current_version = (string) ($plugin_data['Version'] ?? '');
        $release_data = $this->get_latest_release();

        if ($release_data === null || $current_version === '') {
            return $transient;
        }

        $latest_version = self::normalize_version((string) $release_data->tag_name);
        if ($latest_version === '') {
            $this->store_last_check([
                'ok'      => false,
                'code'    => 'empty_tag',
                'message' => __('Latest GitHub release has an empty tag name.', 'noble-schema'),
            ]);
            return $transient;
        }

        $basename = plugin_basename($this->plugin_file);
        if (version_compare($current_version, $latest_version, '<')) {
            $release_url = (string) ($release_data->html_url ?? '');
            if ($release_url === '') {
                $release_url = self::release_page_url(
                    $this->github_username,
                    $this->repo_name,
                    (string) $release_data->tag_name
                );
            }
            $transient->response[$basename] = (object) [
                'slug'        => dirname($basename) !== '.' ? dirname($basename) : $basename,
                'plugin'      => $basename,
                'new_version' => $latest_version,
                'package'     => (string) $release_data->browser_url,
                'url'         => $release_url,
                'icons'       => $this->plugin_icons(),
            ];
            $this->store_last_check([
                'ok'             => true,
                'code'           => 'update_available',
                'message'        => sprintf(
                    /* translators: 1: installed version, 2: available version */
                    __('Update available: %1$s → %2$s.', 'noble-schema'),
                    $current_version,
                    $latest_version
                ),
                'current'        => $current_version,
                'latest'         => $latest_version,
                'package_source' => (string) ($release_data->package_source ?? ''),
                'release_url'    => $release_url,
            ]);
        } else {
            unset($transient->response[$basename]);
            $this->store_last_check([
                'ok'      => true,
                'code'    => 'up_to_date',
                'message' => sprintf(
                    /* translators: 1: installed version, 2: latest GitHub release version */
                    __('Up to date (%1$s). Latest GitHub release is %2$s.', 'noble-schema'),
                    $current_version,
                    $latest_version
                ),
                'current' => $current_version,
                'latest'  => $latest_version,
            ]);
        }

        return $transient;
    }

    /**
     * Icon URLs shown next to the plugin on Dashboard → Updates.
     *
     * @return array<string, string>
     */
    private function plugin_icons(): array {
        if ($this->plugin_file === '' || !function_exists('plugins_url')) {
            return [];
        }

        return [
            '1x'      => plugins_url('assets/icon-128x128.png', $this->plugin_file),
            '2x'      => plugins_url('assets/icon-256x256.png', $this->plugin_file),
            'default' => plugins_url('assets/icon-256x256.png', $this->plugin_file),
        ];
    }

    /**
     * Force WordPress to re-run plugin update checks (clears cached transient).
     *
     * @return array<string, mixed>
     */
    public function force_check(): array {
        delete_site_transient('update_plugins');
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        } else {
            // Fallback for odd environments: invoke our checker against a synthetic transient.
            $transient = (object) [
                'checked'  => [plugin_basename($this->plugin_file) => '0'],
                'response' => [],
            ];
            $this->check_for_update($transient);
        }

        return self::last_check();
    }

    /**
     * @return array<string, mixed>
     */
    public static function last_check(): array {
        $raw = get_option(self::OPT_LAST_CHECK, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function override_update_package(array $options): array {
        $plugin = (string) ($options['hook_extra']['plugin'] ?? '');
        if ($plugin !== plugin_basename($this->plugin_file)) {
            return $options;
        }

        $release_data = $this->get_latest_release();
        if ($release_data === null) {
            return $options;
        }

        $zip_file = $this->download_zip_to_temp(
            (string) $release_data->browser_url,
            (int) $release_data->asset_id,
            (string) ($release_data->package_source ?? 'asset')
        );

        if ($zip_file !== '') {
            $options['package'] = $zip_file;
        }

        return $options;
    }

    /**
     * GitHub zipballs extract to owner-repo-sha/; rename to the installed plugin folder.
     *
     * @param mixed $upgrader
     * @param array<string, mixed>|null $hook_extra
     * @return string|\WP_Error
     */
    public function rename_package_source($source, $remote_source, $upgrader = null, $hook_extra = null) {
        unset($upgrader);
        $plugin = is_array($hook_extra) ? (string) ($hook_extra['plugin'] ?? '') : '';
        if ($plugin !== plugin_basename($this->plugin_file)) {
            return $source;
        }

        if (!is_string($source) || $source === '' || !is_dir($source)) {
            return $source;
        }

        $desired = dirname($this->plugin_file);
        $desired_name = basename($desired);
        $current_name = basename(untrailingslashit($source));
        if ($desired_name === '' || $current_name === $desired_name) {
            return $source;
        }

        if (!is_string($remote_source) || $remote_source === '' || !is_dir($remote_source)) {
            return $source;
        }

        $destination = trailingslashit($remote_source) . $desired_name;
        if ($destination === $source) {
            return $source;
        }

        if (is_dir($destination)) {
            $this->rrmdir($destination);
        }

        if (!@rename($source, $destination)) {
            return $source;
        }

        return trailingslashit($destination);
    }

    /**
     * @param mixed $upgrader
     * @param array<string, mixed> $options
     */
    public function on_upgrader_process_complete($upgrader, array $options): void {
        if ($this->is_our_plugin_update($options)) {
            self::maybe_log_version_change($this->plugin_file);
        }
        $this->clean_up_temp_directory($upgrader, $options);
    }

    /**
     * @param mixed $upgrader
     * @param array<string, mixed> $options
     */
    public function clean_up_temp_directory($upgrader, array $options): void {
        unset($upgrader);
        if (!$this->is_our_plugin_update($options) || $this->temp_dir === '' || !is_dir($this->temp_dir)) {
            return;
        }

        $this->rrmdir($this->temp_dir);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function is_our_plugin_update(array $options): bool {
        $basename = plugin_basename($this->plugin_file);
        $plugins = $options['plugins'] ?? null;
        if (is_array($plugins)) {
            return in_array($basename, $plugins, true);
        }

        return ($options['plugin'] ?? '') === $basename;
    }

    public static function normalize_version(string $tag): string {
        return ltrim(trim($tag), "vV");
    }

    /**
     * Build a GitHub release page URL from org/user, repo, and tag or version.
     */
    public static function release_page_url(string $username, string $repo, string $tag_or_version): string {
        $username = trim($username);
        $repo = trim($repo);
        $version = self::normalize_version($tag_or_version);
        if ($username === '' || $repo === '' || $version === '') {
            return '';
        }

        return sprintf(
            'https://github.com/%s/%s/releases/tag/%s',
            rawurlencode($username),
            rawurlencode($repo),
            rawurlencode('v' . $version)
        );
    }

    /**
     * Point “View version details” at the GitHub release (new tab) instead of WP’s thickbox.
     */
    public function enqueue_release_details_link(string $hook): void {
        if ($hook !== 'plugins.php' && $hook !== 'update-core.php') {
            return;
        }

        $release_url = $this->pending_release_url();
        if ($release_url === '') {
            return;
        }

        $basename = plugin_basename($this->plugin_file);
        $slug = dirname($basename);
        if ($slug === '.' || $slug === '') {
            $slug = (string) pathinfo($basename, PATHINFO_FILENAME);
        }

        $js = sprintf(
            <<<'JS'
(function () {
  var releaseUrl = %1$s;
  var pluginSlug = %2$s;
  function rewrite() {
    document.querySelectorAll("a.open-plugin-details-modal").forEach(function (anchor) {
      var href = anchor.getAttribute("href") || "";
      if (href.indexOf("plugin=" + encodeURIComponent(pluginSlug)) === -1 && href.indexOf("plugin=" + pluginSlug) === -1) {
        return;
      }
      anchor.href = releaseUrl;
      anchor.target = "_blank";
      anchor.rel = "noopener noreferrer";
      anchor.classList.remove("thickbox", "open-plugin-details-modal");
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", rewrite);
  } else {
    rewrite();
  }
})();
JS
            ,
            wp_json_encode($release_url),
            wp_json_encode($slug)
        );

        wp_register_script('ns-gh-release-details', false, [], '0.1.1', true);
        wp_enqueue_script('ns-gh-release-details');
        wp_add_inline_script('ns-gh-release-details', $js);
    }

    private function pending_release_url(): string {
        $basename = plugin_basename($this->plugin_file);
        $transient = get_site_transient('update_plugins');
        if (!is_object($transient) || empty($transient->response[$basename]) || !is_object($transient->response[$basename])) {
            return '';
        }

        $info = $transient->response[$basename];
        if (!empty($info->url) && is_string($info->url)) {
            return $info->url;
        }

        if (!empty($info->new_version)) {
            return self::release_page_url(
                $this->github_username,
                $this->repo_name,
                (string) $info->new_version
            );
        }

        return '';
    }

    /**
     * Map a GitHub release JSON object into the package descriptor used by this updater.
     *
     * @return object{tag_name:string,html_url:string,browser_url:string,asset_id:int,package_source:string}|null
     */
    public static function package_from_release(object $release_data): ?object {
        $tag = (string) ($release_data->tag_name ?? '');
        if ($tag === '') {
            return null;
        }

        $html_url = (string) ($release_data->html_url ?? '');
        $assets = $release_data->assets ?? null;
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_object($asset) || empty($asset->name) || !is_string($asset->name)) {
                    continue;
                }
                if (!str_ends_with(strtolower($asset->name), '.zip')) {
                    continue;
                }
                $url = (string) ($asset->browser_download_url ?? '');
                if ($url === '') {
                    continue;
                }

                return (object) [
                    'tag_name'        => $tag,
                    'html_url'        => $html_url,
                    'browser_url'     => $url,
                    'asset_id'        => (int) ($asset->id ?? 0),
                    'package_source'  => 'asset',
                ];
            }
        }

        $zipball = (string) ($release_data->zipball_url ?? '');
        if ($zipball === '') {
            return null;
        }

        return (object) [
            'tag_name'       => $tag,
            'html_url'       => $html_url,
            'browser_url'    => $zipball,
            'asset_id'       => 0,
            'package_source' => 'zipball',
        ];
    }

    private function get_latest_release(): ?object {
        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode($this->github_username),
            rawurlencode($this->repo_name)
        );

        $response = wp_remote_get($api_url, [
            'headers' => $this->request_headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            $this->store_last_check([
                'ok'      => false,
                'code'    => 'http_error',
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Could not reach GitHub: %s', 'noble-schema'),
                    $response->get_error_message()
                ),
            ]);
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code !== 200) {
            $this->store_last_check([
                'ok'      => false,
                'code'    => 'http_' . $code,
                'message' => $this->message_for_http_code($code),
            ]);
            return null;
        }

        $release_data = json_decode($body);
        if (!is_object($release_data)) {
            $this->store_last_check([
                'ok'      => false,
                'code'    => 'bad_json',
                'message' => __('GitHub returned an invalid release response.', 'noble-schema'),
            ]);
            return null;
        }

        $package = self::package_from_release($release_data);
        if ($package === null) {
            $this->store_last_check([
                'ok'      => false,
                'code'    => 'no_package',
                'message' => __('Latest GitHub release has no zip asset and no zipball URL.', 'noble-schema'),
            ]);
            return null;
        }

        return $package;
    }

    private function download_zip_to_temp(string $asset_url, int $asset_id, string $package_source): string {
        if ($this->temp_dir === '') {
            return '';
        }

        if ($package_source === 'asset' && $asset_id > 0) {
            $api_url = sprintf(
                'https://api.github.com/repos/%s/%s/releases/assets/%d',
                rawurlencode($this->github_username),
                rawurlencode($this->repo_name),
                $asset_id
            );
            $headers = $this->request_headers();
            $headers['Accept'] = 'application/octet-stream';
        } else {
            if ($asset_url === '') {
                return '';
            }
            $api_url = $asset_url;
            $headers = $this->request_headers();
        }

        $response = wp_remote_get($api_url, [
            'headers' => $headers,
            'timeout' => 60,
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }

        if (!is_dir($this->temp_dir) && !wp_mkdir_p($this->temp_dir)) {
            return '';
        }

        $basename = basename(parse_url($asset_url, PHP_URL_PATH) ?: 'noble-schema-update.zip');
        if (!str_ends_with(strtolower($basename), '.zip')) {
            $basename .= '.zip';
        }
        $basename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $basename) ?: 'noble-schema-update.zip';
        $zip_filename = $this->temp_dir . $basename;

        $file_data = wp_remote_retrieve_body($response);
        if ($file_data === '' || file_put_contents($zip_filename, $file_data) === false) {
            return '';
        }

        return $zip_filename;
    }

    /**
     * @return array<string, string>
     */
    private function request_headers(): array {
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Noble-Schema-Plugin-Updater',
        ];
        if ($this->access_token !== '') {
            // Classic PATs accept "token"; fine-grained / newer tokens prefer Bearer.
            $scheme = preg_match('/^(gh[pousr]_|github_pat_)/', $this->access_token) ? 'Bearer' : 'token';
            $headers['Authorization'] = $scheme . ' ' . $this->access_token;
        }

        return $headers;
    }

    private function message_for_http_code(int $code): string {
        if ($code === 401 || $code === 403) {
            return __('GitHub rejected the credentials (401/403). Check the access token scopes for this private repository.', 'noble-schema');
        }
        if ($code === 404) {
            return __('GitHub release not found (404). For private repos this usually means the username/repo is wrong, the token is missing/invalid, or there is no published (non-draft) release yet — a git tag alone is not enough.', 'noble-schema');
        }

        return sprintf(
            /* translators: %d: HTTP status code */
            __('GitHub API returned HTTP %d when fetching the latest release.', 'noble-schema'),
            $code
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store_last_check(array $data): void {
        $data['checked_at'] = time();
        $data['repo'] = $this->github_username . '/' . $this->repo_name;
        update_option(self::OPT_LAST_CHECK, $data, false);
    }

    private function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = $fileinfo->isDir() ? 'rmdir' : 'unlink';
            $todo($fileinfo->getRealPath());
        }

        rmdir($dir);
    }
}
