=== Postful ===
Contributors: poctrl
Tags: devotional, multilingual, custom post type, daily, bilingual
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Structured, multilingual content types — like a bilingual daily devotional — set up in a minute and written side by side.

== Description ==

Postful lets you create a content type (for example "Devotionals") with its own fields and languages through a short setup wizard. No separate post type, custom fields or translation plugins needed.

* **Recurring day** structure: one entry per calendar day, repeating every year. Visitors see today's.
* Every language lives in the same entry and is edited side by side, field by field.
* One page: visitors browse by month or previous/next, and the address follows the day (?mn=10&dn=10) so a link in an email always opens on its day.
* Follows the language your site is already showing (GTranslate, browser language). It never sets a language itself.
* Excel import and export in your workbook's shape (one file per language, a sheet per month), re-runnable.
* A clean reading layout (entry plus sidebar with the month list) and per-field styling with live preview.

More structures (dated entries, sequences, evergreen pages, events) are coming.

== Installation ==

1. Upload the `postful` folder to `/wp-content/plugins/` or install the zip from Plugins → Add New → Upload.
2. Activate, then go to Postful → Add content type.
3. Put one shortcode on a page: `[postful type="devotional"]`.

== Frequently Asked Questions ==

= Which day is "today"? =

The site's timezone, under Settings → General.

= My page shows yesterday's entry =

If a page cache serves an old copy, Postful fixes the entry in the visitor's browser. It is still best to set your cache to expire that page daily, at midnight in the site's timezone.

= Does deactivating delete my entries? =

No. Entries are only deleted if you switch on Postful → Settings → "Delete data on uninstall" and then delete the plugin.

== Changelog ==

= 0.4.0 =
* Removed machine translation (DeepL): no Translate buttons, no API key setting, nothing sent to outside services. French comes from your own text or your French workbook.

= 0.3.1 =
* The address follows the day shown (?mn=10&dn=10, like awmi.net), so links in emails open on their day. Today is the plain address; Back / Forward step through days.

= 0.3.0 =
* Devotionals only: the setup starts with Title, Scripture of the day, Reference and Text; the Structure step and other presets are gone.
* Import your Excel workbooks as they are: one per language, a sheet per month, dates like "1er janvier" or "January 1". Columns are matched with a preview; paragraphs, bold and italic are kept.
* Export in the same shape: one workbook per language, a sheet per month.
* [postful] now fills the container it is placed in, and the title, date and Previous / Today / Next line up on one row.
* Month list: one month open at a time, no entry counts.

= 0.2.2 =
* A separate [sp_nav] hides itself when the page's month list already shows Previous / Today / Next, so the links never appear twice.

= 0.2.1 =
* The date sits to the right of the title.
* Previous / Today / Next now sit directly above the month list, including with [sp_index] (nav="no" to leave them out).

= 0.2.0 =
* One reading layout, like AWMI: title, date, fields; navigation and month list in the sidebar.
* Per-field styling (size, weight, italic, capitals, alignment, colour, spacing, lines) replaces style presets.
* Every field is translatable; translate per field or per column in the content screen (DeepL), or type it.
* Languages trimmed to English and Français (Canada).
* Simpler field setup: name, type, order.
* Stylesheets and scripts now refresh immediately after updates.

= 0.1.0 =
* First release: setup wizard, Recurring day structure, side-by-side multilingual editor, single [postful] shortcode with four layouts, main/sidebar placement and a Style panel, in-place navigation, GTranslate-aware language display, optional DeepL translation drafts with review, CSV import/export, type JSON import/export.
