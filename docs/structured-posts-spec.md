# Build spec: structured multilingual content types (WordPress plugin)

Working title: **Structured Posts**. Rename freely.

A WordPress plugin that lets a non-developer create a custom content type, with fields and
multiple languages, through a short onboarding flow — instead of configuring a post type
in CPT UI, fields in ACF, and translation in a fourth plugin. Self-contained: no companion
plugin, no theme changes, no build step.

The driving use case is a bilingual daily devotional (one entry per calendar day, repeating
every year, English + French hand-written side by side). That is **one preset**, not the
whole plugin. Build the general mechanism; ship the devotional case as a preset that proves
it.

---

## 1. Core concepts

**Content type** — a user-created post type. Stored as a config record in plugin options
and registered at `init` from that config. Users never touch `register_post_type`.

**Preset** — a starting shape for a content type. It decides three things a generic field
builder can't: what identifies an entry (its *key*), how entries are ordered and grouped,
and what "the current entry" means. It also supplies a starting field set.

**Field** — a named input belonging to the type. Each field is translatable or not.

**Language** — the type's supported languages, one marked default.

---

## 2. Onboarding flow

Triggered by "Add content type". Keep it to a few screens, each with a sensible default,
and an **Advanced** section collapsed by default on every screen.

### Screen 1 — Name

- Plural and singular label ("Devotionals" / "Devotional"). Derive the internal key from
  the name; show it, let it be edited, lock it after creation.
- Advanced: menu icon, menu position, URL slug.

### Screen 2 — Structure

Pick one preset. Show each with a one-line description and a small diagram of how entries
are keyed and listed.

| Preset | Key | Current entry | Index / grouping | Good for |
|---|---|---|---|---|
| **Recurring day** | month + day, no year | today's | accordion of 12 months, days inside | devotionals, saint of the day, daily quotes, on-this-day |
| **Dated entry** | publish date | newest | reverse chronological, optional year/month archive | news, blog, announcements |
| **Sequence** | position number | first unread, else #1 | ordered list, 1…n | lessons, a 30-day challenge, chapters |
| **Evergreen** | none | n/a | alphabetical or manual order | staff bios, FAQs, glossary, service pages |
| **Scheduled event** | start date-time (+ optional end) | next upcoming | upcoming, then past | events, classes, services |

**Recurring day is the preset that must be exactly right**, since it's the live use case:

- Store month and day as separate integers. Never a year, never the WordPress publish date
  — the March 5 entry is the same entry every year.
- Validate with `checkdate($m, $d, 2024)` so Feb 29 is allowed.
- "Today" resolves with `wp_date()`, so it follows the site timezone.
- Entries have **no public URL of their own**: `publicly_queryable: false`,
  `has_archive: false`, `exclude_from_search: true`, and out of feeds and sitemaps.
  Everything is read on one page whose URL never changes (see §5).
- Advanced: let the user turn individual URLs back on, since other users of this preset may
  want them.

For the other presets, individual URLs and archives default **on**.

### Screen 3 — Languages

- Multi-select from the common WordPress locales, one marked default.
- A single-language type is valid and must skip all language UI thereafter.
- Note on screen that field *labels* stay in one language (see §4) — only the content is
  multilingual.
- Advanced: fallback behaviour — per-field fallback to the default language (the default),
  or hide the entry entirely when a translation is missing.

### Screen 4 — Fields

Preloaded with the preset's starting fields. The user can rename, reorder, remove, and
(phase 2) add their own.

Starting fields for **Recurring day**, matching the devotional case:

| Label | Type | Translatable |
|---|---|---|
| Heading | single line | yes |
| Verse text | long text | yes |
| Verse reference | single line | yes |
| Body | rich text | yes |
| Scripture reading | single line | yes |

Note that reference fields are translatable — "John 1:1" is "Jean 1:1" in French. Don't
assume short or reference-looking fields are language-neutral.

Field types to support: single line, long text, rich text (`wp_editor`), number, date,
URL, email, image, file, select (fixed choices), toggle. Repeaters are out of scope.

Per field: label, key (auto-slugged, editable before first save, locked after), type,
required, help text, translatable toggle.

### Screen 5 — Done

Show the shortcodes for this type (§5) with copy buttons, and a link to add the first entry.

Editing a type afterwards reopens the same screens. Changing a field key after entries exist
would orphan data — lock keys, and offer delete-and-recreate with a clear warning instead.

---

## 3. Phasing

**Phase 1** — presets, languages, entry editor, display, importer. Users can rename,
reorder and remove preset fields, and add fields from the type list, but the full visual
builder can wait.

**Phase 2** — arbitrary field creation, field reordering by drag, conditional fields.

Ship phase 1 complete rather than both half-done.

---

## 4. Entry editor

The person entering content is typically one bilingual-ish English speaker pasting
translated copy in. So:

- **Field labels are always in the admin language — not in the field's own language.** The
  French section shows "Heading", "Verse text", "Body", the same as English. What marks the
  section is a **language heading** ("English", "Français") above it, nothing else.
- Non-translatable fields render once, at the top, outside any language section.
- Translatable fields render once per language, grouped under that language's heading, in
  the same field order each time, so the two sections are visually parallel and easy to
  paste between.
- The key field (month/day picker, sequence number, event date) sits in the sidebar, labelled
  so its behaviour is obvious — for Recurring day, make clear the entry repeats every year.
- Warn in the editor when the key is unset, since such an entry may never appear.
- Save all languages in one save. No per-language posts, no duplicate-post translation model
  — one post holds every language in meta.

Admin list screen, per type:

- A key column (`March 5`, `Lesson 4`, the event date), with a red "not set" when missing.
- One column per non-default language showing complete / incomplete, so translation gaps are
  visible at a glance.
- Sortable and filterable by the key (by month, for Recurring day).

The default language's first text field also writes to `post_title` on save, so the list
screen, search and revisions stay usable.

---

## 5. Display

Generic shortcodes, each taking the type key:

- `[sp_current type="devotional"]` — the current entry per the preset (today's, the next
  event, item #1)
- `[sp_index type="devotional"]` — the preset's index: month accordion, numbered list,
  A-Z, upcoming events
- `[sp_nav type="devotional"]` — previous / next

There is deliberately **no language switcher shortcode** — see §6.

**Single-URL behaviour (required for Recurring day, available to all presets as an option):**
clicking an index entry or previous/next **replaces the content in place** via a REST fetch.
The address bar must not change — no query string, no History API push. A refresh returns to
the current entry. This exists because a daily email links to the same URL every day; the
client must not paste a different URL into each send.

Previous/next walks the sorted set of published entries, skips gaps, and wraps around
(December 31 → January 1 for Recurring day). The index highlight stays in sync, and the
relevant month opens.

Empty and error states are localised and never leave a blank box.

### Field output

Each field renders in its configured order, wrapped with a class derived from its key
(`.sp-field-verse_reference`), so a theme can style parts individually. Give each type an
optional template textarea in its settings, using `{field_key}` placeholders, for users who
want to control order and markup. Default template: fields in order, no wrapper chrome
beyond the classes.

---

## 6. Language: follow, never set

**The plugin is a follower, not a source of truth.** The site already decides what language
a visitor sees — on the target site that's **GTranslate**, which switches on browser language
and offers a switcher in the header. The plugin's only job is to detect that decision and
show the matching copy.

It must therefore **not**:

- set a language cookie or write to `localStorage`
- add `?lang=` or any other parameter to URLs
- add a `/fr/` path or any URL structure of its own
- render a language switcher of its own
- remember a visitor's previous choice

It never changes the page's language. It only reads it.

### Detection

Read the page's current language, first match wins:

1. GTranslate's `googtrans` cookie (format `/{source}/{target}` — take the target)
2. The `<html lang>` attribute, plus any translation classes GTranslate sets on `<html>`
3. A language segment in the path, if the site runs GTranslate in subdirectory mode (`/fr/…`)
4. `navigator.languages`
5. The type's default language

Match on the primary subtag only: `fr-CA`, `fr-FR` and `fr` all resolve to French.

**Any language the type doesn't support falls back to the default.** A Spanish or German
browser gets English, because English is the default — not a partial or mixed page.

### Reacting to change

Detection is not a one-time read on load. Re-run it when:

- GTranslate's cookie or the `<html>` attributes change, because the visitor used the site's
  own switcher — watch with a `MutationObserver` on `<html>` plus a lightweight cookie check,
  since GTranslate's JS widget can switch without a full page load
- content is swapped in place by the index or previous/next (§5), so navigating doesn't drop
  back to the default language
- the page is restored from bfcache (`pageshow`)

There is a **first-paint race**: on a first visit the plugin's script may run before GTranslate
has set its cookie. Falling through to `navigator.languages` reaches the same answer GTranslate
is about to reach, so the two agree, and the observer confirms it a moment later. Don't paper
over this with a loading state — the fallback should already be right.

### Rendering

- Render **all supported languages into the page** and reveal one with CSS. Never branch
  server-side on visitor language: the page is cached, so a server-side branch would serve one
  visitor's language to everyone.
- Wrap all plugin output in `class="notranslate" translate="no"`, so GTranslate doesn't machine-
  translate hand-written copy on top of itself. GTranslate keeps handling the rest of the site.

### Plugin strings

Strings the plugin generates (previous, next, index prompt, empty and error messages) need a
version per supported language, editable in the type's settings. They follow the same
detection — not `.po` files, and not GTranslate.

### Optional, off by default

A `?lang=` **read-only preview override**, so an editor can check French copy without changing
their browser settings: honoured when present, never persisted, never written into a link by
the plugin. Ship it behind a setting that's off by default, or leave it out — it's a QA
convenience, not part of the visitor experience.

---

## 7. Import / export

A CSV importer per type, under that type's menu:

- Columns: the key field(s), then one column per field per language (`body_en`, `body_fr`).
- Downloadable header template matching the type's current fields.
- Re-runnable: match on the key and update, so a second pass can add the French to entries
  that came in English-only.
- Report row-level errors without aborting the whole import.

This is how a year of content actually gets in — 365 entries across several fields and two
languages is not hand-entry work. Also export the same shape, and export/import the type
config itself as JSON so a type can be moved between sites.

---

## 8. Performance

- Build the key → post ID index once per request, cached in a transient, invalidated on
  `save_post`, `deleted_post`, `trashed_post`, `untrashed_post`.
- `update_meta_cache()` before reading meta across a set. No meta query per entry when
  rendering an index.
- Document that a page showing "today's" entry should expire from page cache daily.

---

## 9. Code requirements

- No framework, no build step, no external dependencies, no CDN assets, nothing phoning home.
- Clear file separation: type registry and config, onboarding UI, field rendering/saving,
  front-end display, REST, importer.
- Escape all output (`esc_html`, `esc_attr`, `wp_kses_post`); nonce and capability checks on
  every save; `$wpdb->prepare` for any direct SQL.
- Prefix everything consistently.
- Uninstall cleanly on request, but **never delete user content on deactivation**.
- Must pass `php -l`, run on PHP 8.1+ and current WordPress, and pass WordPress Plugin Check
  if it's going to the directory.
- `readme.txt` in WordPress plugin format, plus setup notes covering page setup, timezone,
  cache expiry, and the GTranslate interaction.

## 10. Non-goals

- Not a page builder or a theme. Output is plain semantic HTML with classes.
- No machine translation, and no translation API.
- No email sending — existing tools link to the page.
- No front-end submission or editing.
