=== Data Shifter ===
Contributors: codixr
Tags: import, export, migration, csv, custom post types
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export and import posts, pages, custom post types, custom fields, taxonomies, comments and media as JSON, ZIP, CSV or XML.

== Description ==

Data Shifter moves WordPress content between sites. A step-by-step export screen lets you pick what to export, and a drag-and-drop import screen shows a preview before anything is written to your database.

**Export**

* Any public post type, including pages and custom post types.
* Filters by status, date range, author and taxonomy term.
* Choose which custom fields to include, with internal fields kept separate.
* Optional taxonomies, featured images, galleries and comments.
* Output as JSON, a ZIP package with media files, CSV or XML.
* Preview sample rows before you run the export.

**Import**

* Drag and drop a Data Shifter JSON or ZIP file, a CSV or an XML file.
* Large files are uploaded in chunks, so the server upload limit does not get in the way.
* Preview the first ten items before importing.
* Import as any post type, and choose to skip existing posts, update them (matched by title or slug), or always create new posts.
* Optional media import and automatic creation of missing categories and tags.
* Imports run in small batches with a progress bar, and a summary of created, updated, skipped and failed items.

Data Shifter is built and maintained by [Codixr](https://codixr.com), a software agency.

= Privacy and external requests =

Data Shifter does not send any data to Codixr or to any other service, and it does not track usage.

When you import a file with the "Download and import media files" option turned on, Data Shifter downloads each image from the URL stored in the file, unless the image data is already bundled in a ZIP package. These requests go only to the addresses listed in your import file, and they send your site address in the User-Agent header, as the standard WordPress HTTP API does. Turn the option off to import without any outgoing requests.

Export files and uploaded import files are stored briefly in `wp-content/uploads/data-shifter/temp/`, which is protected from direct browsing. Export files are deleted after download, and import files are deleted when the import finishes or the plugin is deactivated. ZIP packages may contain only `data.json` and media files in a `media` folder; a package with any other file, such as a PHP file, is refused. In CSV exports, cells that start with =, +, -, @, a tab or a carriage return get a leading single quote so spreadsheet apps do not run them as formulas.

== Installation ==

1. In your WordPress admin, go to Plugins > Add New and search for "Data Shifter", or upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open Data Shifter in the admin menu. Use the Export tab on the source site and the Import tab on the destination site.

Only administrators (users with the `manage_options` capability) can use Data Shifter.

== Frequently Asked Questions ==

= Which formats can I import? =

Data Shifter JSON and ZIP files, plus CSV and XML files. For CSV, use column names such as Title, Content, Excerpt, Status, Date, Slug, Categories, Tags and Image. Other columns are imported as custom fields.

= Does it work with very large files? =

Yes. Files are uploaded in chunks and imported in batches, so large exports work on most hosts. Very large media libraries can still take a while, so keep the browser tab open until the import finishes.

= What happens to posts that already exist? =

You choose. Data Shifter can skip them or update them, matching existing posts by title or by slug. You can also choose "Always create new", which creates every item as a new post even when a matching post exists; WordPress adds a number to repeated slugs.

= Are images copied too? =

Yes. ZIP exports include the media files. JSON, CSV and XML imports can download images from the URLs in the file when media import is turned on.

= Can I undo an import? =

No. Imports write directly to your database, so take a backup before importing into a live site.

= Where can I get help? =

Email support@codixr.com or visit https://codixr.com.

== Screenshots ==

1. Export, step 1: choose the content type to export.
2. Export, step 2: filter by status, date, author and taxonomy.
3. Export, step 3: choose fields and custom fields.
4. Export, step 4: pick the format and run the export.
5. Import: drag and drop an export file.
6. Import: preview the file and set import options.
7. Import: summary of created, updated, skipped and failed items.

== Changelog ==

= 1.0.0 =
* Initial release.
