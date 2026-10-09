# Postful

Structured, multilingual content types for WordPress. The first structure, **Recurring day**, powers a bilingual daily devotional: one entry per calendar day, English and French written side by side, read on a single page whose address never changes.

The full product spec is in [docs/structured-posts-spec.md](docs/structured-posts-spec.md).

## Install

Download `postful.zip` from the [latest release](https://github.com/00pollock/postful/releases/latest), then go to Plugins → Add New → Upload. After that, updates show up in the WordPress dashboard like any other plugin.

## Setup

### 1. Create the content type

Go to **Postful → Add content type**. The defaults are set up for the devotional:

- Name: Devotionals / Devotional
- Structure: Recurring day
- Languages: English (default) and Français
- Fields: Heading, Verse text, Verse reference, Body, Scripture reading. All are translated.

### 2. Add entries

You can write entries one at a time under **Devotionals → New Devotional**. For a year of content, use **Devotionals → Import / Export**:

- **Template.** Download it to get the exact column names, such as `month,day,heading_en,heading_fr,…`.
- **Matching.** Rows are matched by month + day, so you can re-import as often as you like.
- **Empty cells.** These never overwrite existing content. Import English first and French later if that's easier.
- **Delimiters.** Semicolon-separated files from French Excel work as well as comma-separated ones.

### 3. Build the page

Create one page, for example `/reading/daily-devotional/`, and add:

```
[sp_current type="devotional"]
[sp_nav type="devotional"]
[sp_index type="devotional"]
```

- `sp_current` shows today's entry.
- `sp_nav` adds Previous / Today / Next.
- `sp_index` lists every entry month by month.

Clicking any of these swaps the entry in place, and the address bar never changes, so the daily email can always link to this one page. Refreshing the page returns to today.

Entries have no URLs of their own, and they are left out of search, feeds and sitemaps. To turn per-entry URLs on, open the type, go to Structure → Advanced.

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

Postful ships light default styles. Turn them off under Postful → Settings to style everything from your theme.

Every field is wrapped in a class built from its key, such as `.sp-field-verse_reference`. For full control, each type also has a template under Fields → Advanced: display, using `{field_key}` and `{date}` placeholders.

## Development

- No build step and no Composer. It is plain PHP 8.1+ and vanilla JS.
- Updates come from GitHub releases via the vendored [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) 5.7 in `lib/`.
- Code layout: `includes/class-*.php`, autoloaded from the `Postful\` namespace, and `assets/` for CSS and JS.

### Releasing

1. Bump `Version:` in `postful.php`, `POSTFUL_VERSION`, and `Stable tag` in `readme.txt`.
2. Commit with the message `vX.Y.Z: summary`, then tag it `vX.Y.Z`.
3. Build the zip: `git archive --format=zip --prefix=postful/ -o postful.zip HEAD`
4. Push the commit and the tag, then create a GitHub release with `postful.zip` attached.
