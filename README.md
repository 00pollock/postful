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
3. **Languages.** For example English (default) and Français.
4. **Fields.** Name your own fields and choose how each one looks on the page:
   - **Title.** The heading, with the entry's day shown beside it.
   - **Highlight.** A centred passage between rules, e.g. a verse.
   - **Label.** Small capitals, e.g. a reference.
   - **Body.**
   - **Plain.**
5. **Layout.** Choose one of four layouts, decide what goes in the main area and what goes in the sidebar, and adjust sizes, colours and spacing with a live preview.

Entries are managed under the Postful menu. Each content type gets its own item there.

### 2. Add entries

You can write entries one at a time from **Postful → Devotionals → Add New**. For a year of content, use **Import / export**, linked at the top of the entries list:

- **Template.** Download it to get the exact column names, such as `month,day,heading_en,heading_fr,…`.
- **Matching.** Rows are matched by month + day, so you can re-import as often as you like.
- **Empty cells.** These never overwrite existing content. Import English first and French later.
- **Delimiters.** Semicolon-separated files from French Excel work as well as comma-separated ones.

### 3. Translation drafts (optional, DeepL)

Add a DeepL API key under **Postful → Settings**. The free plan allows 500,000 characters a month. You then get:

- a **Translate from English** button on each language column in the editor
- a bulk **Translate into Français** option on the Import / export page

How drafts work:
- **Only empty fields are filled.** Nothing a person wrote is overwritten.
- **Fields can opt out.** Turn off "Include in machine translation drafts" for a field (Fields → Options), e.g. Bible verses quoted from a set French translation.
- **Drafts stay hidden until reviewed.** Every draft is marked **Needs review**, and visitors see the default language in its place. Tick **Reviewed** in the editor, or use the bulk action "Mark Français as reviewed" on the entries list.
- **Nothing is sent automatically.** Text only goes to DeepL when someone clicks Translate.

### 4. Build the page

Create one page, for example `/reading/daily-devotional/`, and add a single shortcode:

```
[postful type="devotional"]
```

**What it shows.** The shortcode displays today's entry, Previous / Today / Next links, and the month list, arranged by the type's layout:

| Layout | Arrangement |
|---|---|
| Sidebar right | The entry on the left, and the sidebar on the right. |
| Sidebar left | The same, mirrored. |
| Stacked | Everything in one column. |
| Entry only | Just the entry and the links. |

**Overriding the layout.** To use a different layout on one page: `[postful type="devotional" layout="stacked"]`.

**How navigation works.** Clicking swaps the entry in place, and the address bar never changes, so a daily email can always link to this one page. Refreshing returns to today.

**Unusual designs.** You can also place the parts separately: `[sp_current]`, `[sp_nav]` and `[sp_index]`, each with `type="…"`.

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

The Style panel in the Layout step controls:
- sizes, weights and colours for the title, day, highlight, label and body text
- spacing, background and padding for the entry and sidebar
- the navigation links and the month list

Leave a value empty to use your theme's look.

To style everything from your theme instead, turn off **Postful → Settings → Default styles**. Every field is wrapped in a class built from its key (`.sp-field-verse_reference`) and its style (`.pf-style-highlight`). For full control over the markup, the Layout step's Advanced section takes a template with `{field_key}` and `{date}` placeholders.

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
