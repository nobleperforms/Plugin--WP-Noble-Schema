<?php

declare(strict_types=1);

namespace NobleSchema\Debug;

use NobleSchema\Targeting\Archive_Context;
use NobleSchema\Targeting\Specialty_Context;

/**
 * Resolve a same-site URL or explicit keys into a Page Debugger context.
 */
final class Debug_Context_Resolver {
    /**
     * @return array{
     *   ok:bool,
     *   code:string,
     *   message:string,
     *   context:?array{
     *     type:string,
     *     label:string,
     *     url:string,
     *     post_id?:int,
     *     archive_key?:string,
     *     specialty_key?:string
     *   }
     * }
     */
    public static function from_url(string $input): array {
        $eligible = Debug_Url::eligibility($input);
        if (!$eligible['ok']) {
            return [
                'ok'      => false,
                'code'    => $eligible['code'],
                'message' => $eligible['message'],
                'context' => null,
            ];
        }

        $url = (string) $eligible['normalized'];
        $parts = wp_parse_url($url);
        $query = [];
        if (is_array($parts) && !empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        if (isset($query['s'])) {
            return self::ok_context(self::specialty_context('search', $url));
        }

        $post_id = function_exists('url_to_postid') ? (int) url_to_postid($url) : 0;
        if ($post_id > 0) {
            $specialty = self::specialty_key_for_post($post_id);
            if ($specialty !== null) {
                return self::ok_context(self::specialty_context($specialty, $url));
            }

            return self::ok_context(self::singular_context($post_id, $url));
        }

        foreach (Specialty_Context::definitions() as $key => $_def) {
            $key = sanitize_key((string) $key);
            if ($key === '' || $key === '404') {
                continue;
            }
            $meta = Specialty_Context::meta_for_key($key);
            if (self::urls_match($url, (string) ($meta['url'] ?? ''))) {
                return self::ok_context(self::specialty_context($key, (string) $meta['url']));
            }
        }

        $archive = self::match_archive_url($url);
        if ($archive !== null) {
            return self::ok_context($archive);
        }

        return [
            'ok'      => false,
            'code'    => 'unresolved',
            'message' => __('Could not resolve that URL to a post, archive, or specialty page.', 'noble-schema'),
            'context' => null,
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   code:string,
     *   message:string,
     *   context:?array{
     *     type:string,
     *     label:string,
     *     url:string,
     *     post_id?:int,
     *     archive_key?:string,
     *     specialty_key?:string
     *   }
     * }
     */
    public static function from_request(array $request): array {
        $url  = isset($request['url']) ? trim((string) $request['url']) : '';
        $type = sanitize_key((string) ($request['context'] ?? ''));

        // An explicit target always wins over the URL we echo back alongside it.
        if ($url !== '' && $type === '') {
            return self::from_url($url);
        }

        $specialty_key = sanitize_key((string) ($request['specialty_key'] ?? ''));
        $archive_key   = trim((string) ($request['archive_key'] ?? ''));

        if ($type === 'specialty' || $specialty_key !== '') {
            $key = $specialty_key;
            if (!Specialty_Context::is_known($key)) {
                return self::fail('invalid', __('Unknown specialty page.', 'noble-schema'));
            }
            $meta = Specialty_Context::meta_for_key($key);
            $page_id = Specialty_Context::resolve_post_id($key);
            // Page-backed specialties that print as singular on the front.
            if ($page_id > 0 && !Specialty_Context::is_virtual($key)) {
                return self::ok_context(self::singular_context($page_id, (string) $meta['url']));
            }

            return self::ok_context(self::specialty_context($key, (string) $meta['url']));
        }

        if ($type === 'archive' || $archive_key !== '') {
            $key = $archive_key;
            if ($key === '') {
                return self::fail('invalid', __('Missing archive key.', 'noble-schema'));
            }
            $ctx = Archive_Context::context_from_key($key);
            $identity = (string) ($ctx['archive_identity'] ?? '');
            if ($identity === '') {
                return self::fail('invalid', __('Unknown archive.', 'noble-schema'));
            }

            return self::ok_context([
                'type'        => 'archive',
                'label'       => (string) ($ctx['archive_title'] ?? $key),
                'url'         => (string) ($ctx['archive_url'] ?? ''),
                'archive_key' => $identity,
            ]);
        }

        $post_id = (int) ($request['post_id'] ?? 0);
        if ($post_id > 0) {
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                return self::fail('not_found', __('Post not found.', 'noble-schema'));
            }
            $permalink = get_permalink($post);

            return self::ok_context(self::singular_context(
                $post_id,
                is_string($permalink) ? $permalink : ''
            ));
        }

        if ($url !== '') {
            return self::from_url($url);
        }

        return self::fail('invalid', __('Select a post, paste a URL, or choose a specialty page.', 'noble-schema'));
    }

    /**
     * @return array{type:string,label:string,url:string,post_id:int}
     */
    private static function singular_context(int $post_id, string $url): array {
        $post = get_post($post_id);
        $title = $post instanceof \WP_Post
            ? html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
        if ($title === '') {
            $title = sprintf(__('Post #%d', 'noble-schema'), $post_id);
        }
        if ($url === '' && $post instanceof \WP_Post) {
            $permalink = get_permalink($post);
            $url = is_string($permalink) ? $permalink : '';
        }

        return [
            'type'    => 'singular',
            'label'   => $title,
            'url'     => $url,
            'post_id' => $post_id,
        ];
    }

    /**
     * @return array{type:string,label:string,url:string,specialty_key:string}
     */
    private static function specialty_context(string $key, string $url): array {
        $defs = Specialty_Context::definitions();
        $label = (string) ($defs[$key]['label'] ?? $key);
        if ($url === '') {
            $url = (string) (Specialty_Context::meta_for_key($key)['url'] ?? '');
        }

        return [
            'type'          => 'specialty',
            'label'         => $label,
            'url'           => $url,
            'specialty_key' => $key,
        ];
    }

    private static function specialty_key_for_post(int $post_id): ?string {
        foreach (Specialty_Context::definitions() as $key => $def) {
            $key = sanitize_key((string) $key);
            if ($key === '' || (($def['kind'] ?? '') === 'virtual')) {
                continue;
            }
            if (Specialty_Context::resolve_post_id($key) === $post_id) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{type:string,label:string,url:string,archive_key:string}|null
     */
    private static function match_archive_url(string $url): ?array {
        // Coarse + common concrete identities from available archives.
        $candidates = [];
        foreach (Archive_Context::available_archives() as $opt) {
            $value = (string) ($opt['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $candidates[] = $value;
            if (str_starts_with($value, 'taxonomy:')) {
                $tax = substr($value, strlen('taxonomy:'));
                // Try resolving term slug from last path segment.
                $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
                $segments = array_values(array_filter(explode('/', trim($path, '/'))));
                $slug = $segments !== [] ? sanitize_title((string) end($segments)) : '';
                if ($slug !== '' && taxonomy_exists($tax)) {
                    $term = get_term_by('slug', $slug, $tax);
                    if ($term && !is_wp_error($term)) {
                        $candidates[] = 'taxonomy:' . $tax . ':' . $slug;
                    }
                }
            }
        }

        foreach (array_unique($candidates) as $identity) {
            $ctx = Archive_Context::context_from_identity($identity);
            $archive_url = (string) ($ctx['archive_url'] ?? '');
            if ($archive_url !== '' && self::urls_match($url, $archive_url)) {
                return [
                    'type'        => 'archive',
                    'label'       => (string) ($ctx['archive_title'] ?? $identity),
                    'url'         => $archive_url,
                    'archive_key' => (string) ($ctx['archive_identity'] ?? $identity),
                ];
            }
        }

        return null;
    }

    private static function urls_match(string $a, string $b): bool {
        $na = Debug_Url::eligibility($a);
        $nb = Debug_Url::eligibility($b);
        if (!$na['ok'] || !$nb['ok']) {
            return false;
        }

        return rtrim((string) $na['normalized'], '/') === rtrim((string) $nb['normalized'], '/');
    }

    /**
     * @param array{type:string,label:string,url:string,post_id?:int,archive_key?:string,specialty_key?:string} $context
     * @return array{ok:bool,code:string,message:string,context:array<string,mixed>}
     */
    private static function ok_context(array $context): array {
        return [
            'ok'      => true,
            'code'    => 'ok',
            'message' => '',
            'context' => $context,
        ];
    }

    /**
     * @return array{ok:bool,code:string,message:string,context:null}
     */
    private static function fail(string $code, string $message): array {
        return [
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
            'context' => null,
        ];
    }
}
