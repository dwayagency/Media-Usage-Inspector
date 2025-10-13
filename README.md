## Media Usage Inspector

A WordPress admin tool to scan your `wp-content/uploads` directory and identify images that are not referenced anywhere on the site. Supports single sites and multisite networks, with optional time range and subfolder filters, preview of results, and bulk deletion. The plugin adds a dedicated top‑level admin menu entry.

### Key Features
- **Top‑level admin page**: Accessible via the WordPress admin menu (not under Media)
- **Smart scanning**: Looks for references to uploads across posts, post excerpts, postmeta (builders/ACF/Elementor/WPBakery), options, widget data, menus, term meta, and user meta
- **Filters**: By date range and by subfolder within uploads
- **Previews and details**: Thumbnail, relative path, size, last modified time
- **Bulk delete**: Remove selected files and their `-WxH` variants; optional empty-folder cleanup
- **Multisite support**: Scan the current site or all sites in the network from Network Admin

### Requirements
- WordPress 5.6+
- PHP 7.4+
- Capability requirements:
  - Single site: `upload_files`
  - Network Admin page: `manage_network`

### Installation
1. Copy the plugin folder into `wp-content/plugins/media-usage-inspector`.
2. Activate the plugin in the WordPress admin.

### Where to Find It
- Single site: Admin menu → “Verifica immagini” (top‑level)
- Multisite network: Network Admin → “Verifica immagini”

If you previously used a “Media” submenu entry, this plugin now appears as a dedicated top‑level item.

### Usage
1. Open the plugin page.
2. Optionally set filters:
   - **From date / To date**: Limits files by last modified time
   - **Subfolder**: e.g. `2025/09` or `products`
3. Click “Esegui scansione” to run the scan.
4. Review results: preview, path, size, modified time (and site column in network scans).
5. Select files and click “Elimina selezionati” to delete them (and their size variants).

### Multisite Behavior
- In Network Admin, you can:
  - Scan the current network admin context only
  - Or enable “Scan all sites in the network” to aggregate unused files across subsites
- Results indicate which site each file belongs to
- Deletions switch to each respective site context to safely remove files in the correct uploads directory

### How Scanning Works
The plugin compiles a set of “used” upload URLs/paths by scanning these data sources:
- Posts: `post_content`, `post_excerpt`
- Postmeta: All rows containing `wp-content/uploads` (covers common builders including ACF/Elementor/WPBakery)
- Options: Any option containing `wp-content/uploads` (widgets and settings)
- Term meta: If the table exists
- User meta: If the table exists
- Menu fields: Selected `nav_menu_item` meta fields

Then it iterates the uploads filesystem (optionally restricted to a subfolder and date range), and lists image files that are not referenced by any of the above sources.

#### File Types Considered
`jpg, jpeg, png, gif, webp, avif, svg, bmp`

#### Size Variants Handling
- WordPress creates thumbnails or resized images in the form `filename-WxH.ext`
- If a variant is used, the original is treated as used
- If the original is used, variants are treated as used
- When deleting, the plugin also removes `-WxH` variants in the same folder

### Safety and Limitations
- Always back up your site and uploads before bulk deletions
- The plugin relies on scanning database fields for string matches to `/wp-content/uploads/...`:
  - Extremely custom serialization/encoding or off‑site CDN rewrites may not be detected
  - Programmatic or dynamic references that don’t store full/relative URLs in the database may be missed
- SVGs and non‑raster formats are treated as files but previews are not rendered inline by WordPress
- Date filters rely on filesystem modification times which may not reflect “first used” dates

### Permissions
- Single site page requires `upload_files`
- Network page and network‑wide scan require `manage_network`

### Uninstall / Cleanup
This plugin stores transient results for up to 15 minutes to show the last scan. No custom tables are created. Removing the plugin deletes its code but not your uploads.

### Changelog
- 1.0.1
  - Add top‑level admin menu entry (previously under Media)
  - Improve redirects to the new admin page

### Support
Please open an issue or contact your site maintainer for questions. Provide details about your WordPress version, PHP version, and whether you’re on multisite.


