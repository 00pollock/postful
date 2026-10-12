# Postful

Structured, multilingual content types for WordPress. The first structure, **Recurring day**, powers a bilingual daily devotional: one entry per calendar day, English and French written side by side, read on a single page whose address never changes.

The full product spec is in [docs/structured-posts-spec.md](docs/structured-posts-spec.md).

## Install

Download `postful.zip` from the [latest release](https://github.com/00pollock/postful/releases/latest), then go to Plugins → Add New → Upload. After that, updates show up in the WordPress dashboard like any other plugin.

## Setup

### 1. Create the content type

Go to **Postful → Add content type**. The wizard has five steps:

1. **Name.** Type the plural name ("Devotionals"). The singular name and internal key fill themselves in.
2. **Structure.** Pick *Recurring day*.
3. **Languages.** English and Français (Canada).
4. **Fields.** Name each part of an entry. The first field is the title. Every field can be written in each language; an empty translation shows the default language instead.
5. **Style.** Style each field: size, weight, italic, capitals, alignment, colour, space below, and a line beside or above and below it. You can also style the page, the date, the navigation links and the month list. A live preview shows the result. Leave anything empty to use your theme's look.

Entries are managed under the Postful menu.

### 2. Add devotionals

You can write devotionals one at a time from **Postful → Devotionals → Add New**. For a whole year, use **Import / export**, linked at the top of the devotionals list.

Import works with your Excel workbooks as they are: **one workbook per language, one sheet per month, a header row** (Date, Title, Scripture of the day, Reference, Text) and **one row per day**.

1. Choose the file and say which language it is in.
2. Match the columns to your fields. They're matched automatically by name, and your choices are remembered for the next file.
3. Check the preview, then import.

How importing behaves:
- **Dates.** Dates like "1er janvier", "2 janvier", "January 1", "Jan 1" or a real Excel date all work. If a sheet only has day numbers, the sheet name (Jan, Feb…) gives the month.
- **Matching by date.** Rows are matched to devotionals by date, so the English and French files fill the same devotionals, in any order. Importing a file again just updates.
- **Empty cells never overwrite.**
- **Formatting.** Paragraphs, bold and italic in the Text column are kept.
- **Problems.** A row that can't be read (for example an unreadable date) is listed with its sheet and row number; the rest still import.

**Export** downloads the same shape: one workbook per language, a sheet per month. There's also an empty template.

### 3. Translations

You can type translations yourself, or add a DeepL API key under **Postful → Settings** to get help. The free plan allows 500,000 characters a month.

- **In the content screen,** each French field has a **Translate** link, and the French column has **Translate empty fields**. The text that arrives is ordinary editable text: change it as you like, and it goes live when you save.
- **In bulk,** the Import / export page can fill every missing French field. Bulk translations are marked **Needs review** and stay hidden from visitors (they see English) until someone edits the entry, ticks **Reviewed**, or uses the bulk action "Mark Français (Canada) as reviewed".
- **Nothing is sent automatically.** Text only goes to DeepL when someone clicks Translate.

### 4. Build the page

Create one page, for example `/reading/daily-devotional/`, and add a single shortcode:

```
[postful type="devotional"]
```

**What it shows.** The page follows the AWMI layout. The entry reads in the main column: title, date, then your fields. Previous / Today / Next and the month list sit in a sidebar, which moves below the entry on phones.

**How navigation works.** Clicking a day, Previous or Next swaps the devotional in place and updates the address to that day, for example `…/daily-devotional/?mn=10&dn=10` (month 10, day 10), the same pattern as awmi.net. Use these links in emails: they open on that day whenever they're read. Today is the plain address, and the browser's Back button steps back through the days viewed.

**Placing the parts yourself.** If your theme already has a sidebar, put `[sp_current type="devotional"]` in the content and `[sp_index type="devotional"]` in the sidebar. The month list brings Previous / Today / Next along above it (add `nav="no"` to leave them out, and place `[sp_nav]` wherever you like).

**URLs.** Entries have no URLs of their own and are left out of search, feeds and sitemaps. To turn per-entry URLs on, open the type, go to Structure → Advanced.

### Timezone

"Today" follows **Settings → General → Timezone**. Set it to the ministry's local timezone.

### Page caching

A page cache (WP Rocket, LiteSpeed, Cloudflare, etc.) can keep serving yesterday's copy. Postful corrects this in the visitor's browser, but it's best to have the devotional page's cache expire every day at midnight site time, or exclude that page from the cache.

### GTranslate

Postful never sets the language. It reads it, in this order:

1. GTranslate's `googtrans` cookie
2. `<html lang>` once GTranslate has changed it
3. A `/fr/` path, if GTranslate runs in subdirectory mode
4. The browser's first language
5. The type's default language

Languages the type doesn't support fall back to the default: a Spanish visitor sees English. When someone uses GTranslate's switcher, the devotional changes language too, without reloading the page.

All Postful output is marked `notranslate`, so GTranslate doesn't machine-translate the hand-written French on top of itself. GTranslate keeps translating the rest of the site as usual.

To check the French copy without changing browser settings, turn on **Postful → Settings → Language preview** and visit the page with `?lang=fr`. The preview is read-only and never remembered.

### Styling

Use the Style step for sizes, colours, spacing and lines. To style everything from your theme instead, turn off **Postful → Settings → Default styles**. Every field is wrapped in a class built from its key (`.sp-field-verse_reference`). For full control over the markup, the Style step's Advanced section takes a template with `{field_key}` and `{date}` placeholders.

### Fonts

The admin uses DM Sans, bundled with the plugin under the SIL Open Font License. Nothing loads from Google. The public page uses your theme's font.

## Development

- No build step and no Composer. It is plain PHP 8.1+ and vanilla JS.
- Updates come from GitHub releases via the vendored [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) 5.7 in `lib/`.
- Code layout: `includes/class-*.php`, autoloaded from the `Postful\` namespace, and `assets/` for CSS and JS.

### Releasing

1. Bump `Version:` in `postful.php`, `POSTFUL_VERSION`, and `Stable tag` in `readme.txt`.
2. Commit with the message `vX.Y.Z: summary`, then tag it `vX.Y.Z`.
3. Build the zip: `git archive --format=zip --prefix=postful/ -o postful.zip HEAD`
4. Push the commit and the tag, then create a GitHub release with `postful.zip` attached.
