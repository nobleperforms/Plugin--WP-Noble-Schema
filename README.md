# Noble Schema

Configure Schema.org JSON-LD for WordPress without fighting duplicate markup from other SEO plugins. Create schema configs in the admin, map fields to your content, and Noble Schema outputs one clean `@graph` script in the page head.

## Installation

1. Download the latest **plugin zip** from [GitHub Releases](https://github.com/noblestudios/wp-noble-schema/releases) (use the packaged `.zip` asset on the release, not the source code archive).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, and install.
3. Activate **Noble Schema**.

On first activation you’ll see a short getting-started prompt. You can follow it, or open **Settings → Schema Config** anytime.

## Getting started

Typical first setup:

1. **Import a pack** (optional) — If you have a starter export, use **Schema Tools → Export / Import**.
2. **Add templates** (optional) — Bundled types cover common cases (Article, Event, FAQ, Local Business, Place, Tourist Attraction). Add more specific types from **Schema Tools → Schema Storefront**; custom templates are saved under uploads so they survive plugin updates.
3. **Create a schema config** — **Settings → Schema Config** walks through template → targeting → field mapping.
4. **Activate** the config, then edit and save a matching post (or run **Batch regenerate** from Schema Tools).
5. Confirm output with **Page Debugger** or **Sitewide Analytics**, or view the page source for a single JSON-LD script in `<head>`.

## Using the plugin

### Schema Config

Each config answers three questions:

| Step | What you choose |
|------|-----------------|
| **Template** | Which Schema.org type(s) to output |
| **Targeting** | Where it applies (post types, templates, archives, specialty pages, taxonomies, blocks, specific posts, etc.) |
| **Field mapping** | Where each property’s value comes from |

When an active config matches a page, schema is generated on save and stored as a snapshot. Complete snapshots print once in `wp_head` as a single `@graph` JSON-LD block. **Specialty pages** (Front Page, Posts Page, Privacy Policy, 404, Search, and WooCommerce pages when active) can be targeted by stable keys; Global configs and specialty-targeted configs also print on 404 and Search via a live-generated cache. **Archive pages** targeting covers the blog posts page, post-type archives, public taxonomy archives, author archives, and date archives (Global prints on all of these with per-URL cached graphs).

**Field sources** include WordPress core fields, site info, post meta, taxonomies, static values, ACF, Yoast, The Events Calendar, block attributes/content, per-post or per-block manual values, and **callbacks** (see [Extending with callbacks](#extending-with-callbacks)).

- **Manual (post)** / **Manual (block)** fields show up in the **Noble Schema** meta box (or block UI) so editors can fill values per content.
- Use the **Schema** Gutenberg block when a config is scoped to blocks.

### Schema Tools

Open **Schema Tools** from the Schema admin screens (or the tools icon in the header):

- **Schema Storefront** — browse schema.org types and install custom templates
- **Batch regenerate** — rebuild snapshots in bulk after mapping or targeting changes
- **Page Debugger** — view and validate a post’s JSON-LD (stored or live preview); turn on **Advanced mode** for matching configs, generation details, snapshots, logs, and regen state
- **Sitewide Analytics** — coverage score, triage gaps, and scan for schema issues
- **System logs** — optional activity log (enable under Schema Settings)
- **Export / Import** — move settings, templates, and configs between sites
- **Data removal** — wipe plugin data when needed
- **Getting Started** — reopen the setup checklist if you dismissed it

### Competing schema plugins

If Yoast SEO, Rank Math, All in One SEO, or Schema Pro is active, Schema Config shows a warning. Turn off that plugin’s JSON-LD / schema output so Noble Schema is the only source of structured data.

## Configuring settings

**Schema Settings** (also linked from the Plugins list) holds project-wide options:

- **GitHub updates** — automatic update checks (details below)
- **Access** — optionally restrict Schema admin screens to selected users with `manage_options`
- **System logs** — enable logging and choose retention
- **Coverage** — **Sitewide schema types**: searchable picker of types from your installed templates that Sitewide Analytics treats as sitewide. `Organization`, `WebSite`, and `WebPage` are always included and can’t be removed. These types do not inflate page-specific coverage %. Separate from Targeting → **Global** on a schema config (global configs also count as sitewide). Included in Export / Import of plugin settings.
- **Uninstall** — optionally delete all Noble Schema data when the plugin is deleted

Schema configs themselves stay under **Settings → Schema Config**.

## Plugin updates

Release zips already include built admin assets. After the first install, you can keep updating from WordPress once GitHub updates are configured.

1. Open **Schema Settings**.
2. Enable **Allow automatic checks for plugin updates**.
3. Set:
   - **GitHub username** — owner/org (e.g. `noblestudios`)
   - **Repository name** — repo slug (e.g. `wp-noble-schema`)
   - **GitHub token** — required for **private** repositories. Leave blank on save to keep an existing token, or install a token using the token constant, noted below.
4. Use **Check for updates** on that screen, or rely on **Dashboard → Updates**.

Tips:

- For a token that shouldn’t live in the database, define it in `wp-config.php`:

  ```php
  define( 'NOBLE_SCHEMA_GIT_TOKEN', 'ghp_your_token_here' );
  ```

  When that constant is set, the token field in Schema Settings is locked.

- You can still update manually anytime by downloading the latest release zip and uploading it via **Plugins → Add New**.

## Extending with callbacks

When a field can’t be mapped with built-in sources, register a small PHP callback in your theme (or a small custom plugin). Callbacks appear in the Schema Config field mapper under **Callback**, labeled with the callback slug.

Add this to your theme’s `functions.php` (or an mu-plugin):

```php
add_filter( 'ns_schema_callback_primary_destination', function ( array $config, array $context ) {
	$post_id = (int) ( $context['post_id'] ?? 0 );
	if ( $post_id <= 0 ) {
		return null;
	}

	// Example: value from post meta (swap for ACF, options, APIs, etc.).
	$value = get_post_meta( $post_id, 'primary_destination', true );

	return $value !== '' ? $value : null;
}, 10, 2 );
```

Then in **Schema Config → Field mapping**:

1. Choose source type **Callback**.
2. Select the slug (`primary_destination` in the example — taken from the filter name after `ns_schema_callback_`).
3. Save and activate the config, then regenerate affected posts.

Notes:

- Hook format: `ns_schema_callback_{slug}` (slug = dropdown label).
- Only registered hooks are listed; unknown slugs resolve to empty.
- The callback receives `$config` (mapping options) and `$context` (includes `post_id` for post-based pages). Pass `2` as `accepted_args`.
- Return the value that should fill that schema property (string, number, array/object for nested JSON-LD, or `null` to skip).

## Uninstall

- To remove plugin data when deleting the plugin, enable **Delete all data on uninstall** in Schema Settings first.
- Or use **Schema Tools → Data removal** while the plugin is still active.
- Otherwise, deleting the plugin leaves database tables/options in place until you clear them intentionally.

Only test full uninstall / data removal on a disposable environment.

## Offboarding (Schema Lite)

Use offboarding when a client should keep their **generated JSON-LD** after Noble Schema is removed—without configs, mapping, regen, or other project tooling. The process installs a small sibling plugin, **Schema Lite**, migrates complete schema snapshots into editable storage, then removes Noble Schema and its project data.

This is irreversible. Prefer a staging site first.

### Requirements

- WordPress must be able to **write to `wp-content/plugins`** (so Schema Lite can be copied in and Noble Schema can be deleted later).
- On locked/deployed hosts where the plugins directory is not writable, **Perform offboarding** stays disabled. Offboard from a writable environment, or remove files via your deploy process after Lite is in place.

### Step 1 — Start offboarding (Noble Schema)

1. Open **Schema Tools → Data Removal**.
2. Click **Perform offboarding** (next to Delete selected data).
3. Confirm by typing `OFFBOARD`.

Noble Schema copies the bundled Schema Lite plugin into `wp-content/plugins/wp-noble-schema-lite/`, activates it, and sends you to **Settings → Schema Lite**. Noble Schema itself is still active until you finish the next step.

### Step 2 — Complete offboarding (Schema Lite)

On **Settings → Schema Lite**, click **Complete offboarding** and confirm again (phrase `OFFBOARD`). Schema Lite will:

1. Migrate **complete** post snapshots into post meta (`_ns_lite_schema`)
2. Migrate archive schema cache into a Lite option
3. Turn on Noble Schema’s uninstall wipe flag
4. Deactivate Noble Schema
5. Delete the Noble Schema plugin files when the plugins directory is writable
   — If delete isn’t possible, Noble Schema stays deactivated and you’ll need to remove those files manually (e.g. on the next deploy)

Incomplete or empty snapshots are skipped. Configs, field mappings, logs, and manual override tables are **not** kept—only the finished JSON-LD graphs.

### After offboarding

- **Schema Lite** prints the migrated JSON-LD on the front end (posts and archives).
- Editors can edit raw JSON-LD in the Schema Lite meta box on each post.
- There is no template wizard, targeting, or regeneration—treat Lite as a handoff / maintenance layer.
- If Noble Schema files remain on disk after a failed delete, remove them before they get reactivated by deploy tooling.

Offboarding is for client handoff. For a normal uninstall without preserving schema output, use **Data Removal** and/or **Delete all data on uninstall** instead.
