# EasyBusy Connect (by Marclie)

WordPress plugin that puts a multi-step appointment booking form on a clinic
website and syncs the result to [EasyBusy](https://easybusy.software) over its
B2B REST API — real services, real specialists, real free slots, with a lead
fallback when booking is not available.

Built by **Marclie**. Not affiliated with or endorsed by EasyBusy; it talks to
the EasyBusy B2B API using the clinic's own key.

- **Requires:** WordPress 6.0+, PHP 8.0+
- **License:** GPL-2.0-or-later
- **Text domain:** `easybusy-connect` (Croatian translation included)

## What it does

**Front end** — `[easybusy_booking]`, or the *EasyBusy Booking* element in
Bricks. Three steps:

1. **Service** (prices come straight from the clinic) and, revealed inline, the
   **specialist** — or "no preference".
2. **Month calendar** + the times of the chosen day. Free days are outlined,
   the rest are disabled; times are grouped per specialist.
3. **Your details** — message, optional file upload (ortopan/X-ray), contact,
   **country**, consent.

Every step opens on a sensible default (first service, no preference, earliest
free day and time), so the summary is populated from the start. On submit the
visitor is redirected to a thank-you page (`[easybusy_thank_you]`) and a
`dataLayer` event is pushed, which is what Google Ads / GA4 measure. Failures
render an alert with the reason and never clear the visitor's input.

The calendar is drawn client-side: EasyBusy exposes only a flat
`available-slots` list, so the whole horizon is fetched once and month
navigation costs no further API calls.

**Admin** — top-level **EasyBusy** menu:

- **Entries** — every submission, including failed sends, with stat tiles
  (total, 7/30 days, deduplicated people, returning visitors, failed), status
  and type filters, search, CSV export, expandable technical detail per row.
- **Settings** — tabbed: *Connection* (capability probe, API key, dry run),
  *Booking form*, *Email* (editable templates + 12 placeholders), *Privacy &
  data*, *Advanced* (slot cache TTL, paged API log).
- Dashboard widget with the connection state and the last five requests.
- WP-CLI: `wp easybusy check|slots|entries|purge-cache|purge-entries`.

## Install

1. Copy the plugin folder to `wp-content/plugins/easybusy-connect` (or upload
   the release zip), then activate it.
2. Put the API key in `wp-config.php`:

   ```php
   define('EASYBUSY_API_KEY', 'your-key-from-easybusy');
   ```

   A key can also be pasted in **Settings → Connection → API key**, but a
   constant defined in code always wins over the stored one.
3. Open **EasyBusy → Settings**. The plugin probes the live API and shows which
   endpoint groups the key grants; every feature is gated on that probe, so a
   widened key needs no code change — just re-probe.
4. Create a page with `[easybusy_booking]` and another with
   `[easybusy_thank_you]`, then select the second one in
   **Settings → Booking form → Thank-you page**.
5. Leave **Dry run** on until you are ready: submissions are validated,
   recorded and logged, but nothing is written to EasyBusy and no e-mail is
   sent.

### Caching

Exclude the booking page, the thank-you page and `/wp-json/easybusy/v1/` from
any page/HTML cache. A cached `slotId` is a dead booking.

Services and specialists are **never** cached server-side: each request to
`/wp-json/easybusy/v1/services` and `/doctors` calls EasyBusy, so enabling a
service or assigning a specialist in the clinic system shows up on the next
visit. The last successful answer is kept for 24 h and used only when the API
call fails, so an outage shows the previous catalogue instead of an empty step.

### E-mail

Notifications go out through `wp_mail()`. On hosts where PHP `mail()` is
throttled or disabled this fails silently — the plugin captures the reason,
logs it and shows it in **Settings → Email**. Use an SMTP plugin in production.

## Privacy

Submissions are health-adjacent data, so **Settings → Privacy & data** sets how
much of the person an entry keeps, and the masking happens *when the row is
written* — the database never holds what the level excludes:

| Level | Kept | Never written |
| --- | --- | --- |
| `minimal` (default) | initials (`A. H.`), e-mail provider (`@gmail.com`), masked phone (`+385 ••• 33`), message length, salted one-way contact hash | name, e-mail address, phone number, message text |
| `full` | the clinic's working copy: name, e-mail, phone, message | — |
| `none` | the request only | anything about the person |

OIB is never stored in any level. The contact hash
(`hash_hmac('sha256', …, wp_salt())`) is not reversible and is site-specific;
it exists so the list can count distinct people and flag a returning visitor
without identifying anyone. Rows are deleted after `retention_days` by a daily
cron, and the CSV export contains exactly the stored columns — nothing is
re-derived.

## Architecture

```
easybusy-connect.php     bootstrap, autoloader, constants
src/Plugin.php           container + hook wiring
src/Api/                 Client (retries, logging), Capabilities probe,
                         Catalog, Slots, Booking, Leads
src/Form/                Definition (fields, validation), Draft (server-side
                         state), Submit (booking → lead fallback)
src/Rest/Controller.php  /wp-json/easybusy/v1/* proxy — the API key never
                         reaches the browser; rate limited, no-store
src/Store/Submissions.php  entries table, masking, stats, export
src/Admin/               Menu, SettingsPage, EntriesPage, Shell (UI kit)
src/Frontend/            Shortcode, Bricks element, assets, thank-you page
src/Integrations/        Fluent Forms → EasyBusy leads
src/Support/             Countries, Log, RateLimit, Tz, Uploads
src/Cli/Commands.php     WP-CLI
assets/                  form.css/js (front end), admin.css/js
languages/               Croatian translation
```

Design rules worth keeping:

- **Capability-driven.** Nothing assumes an endpoint exists; `Capabilities`
  probes and features gate on the result. Every probe uses a call that is
  actually authorised — the Leads group is tested with an attachment upload to
  lead id 0, which cannot create anything but still passes the access filter —
  and a group can still be demoted later by `Capabilities::deny()` the first
  time a live call returns 403.
- **Overlapping slots are collapsed.** EasyBusy hands out several blocks for
  the same specialist and hour, so `Slots::grouped()` keeps one entry per
  specialist and start time (smallest container that fits) and returns the day
  as a flat, sorted `times` list.
- **No slot, no dead end.** When the clinic has no free slot at all, step 2 only invites the visitor onward if the Leads group is granted (an inquiry can actually be sent). Otherwise it explains the situation and links to **Settings → Booking form → Contact page URL**.
- **Green is the state, gold is the brand, brown is the action.** Hover/selected/focus are drawn in `#276c2b` (6.43:1 on white) as a border plus a soft halo — never a fill; gold marks prices and days that have appointments; buttons use the page's brown with white labels (5.84:1). Bricks' own `body.bricks-is-frontend :focus-visible` outranks a bare `.ebc-card:focus-visible`, so every focus selector is prefixed with `.ebc-form`.
- **The step indicator is a timeline, not buttons.** Numbered dots joined by connectors — filled green for the current step (white number + halo), green with a ✓ for completed steps and their connector, outlined for upcoming ones. On phones only the current label is printed; the `<nav>` keeps its "Step X of Y" label for assistive tech.
- **One EasyBusy account can serve two clinics.** `Settings → Booking form → Services shown on this website` lists the live catalogue with a checkbox each; unticked ids land in `hidden_services` and are dropped from `Catalog::services()`, so they disappear from the form, from the specialist list **and** from the server-side validation — a hidden id answers `ebc_unknown_service` even if posted directly.
- **A free service cannot be priced 0 in EasyBusy.** Clinics enter a token amount instead (0.01 €). Anything at or below `free_price_max` is flagged `free`, printed as "Free of charge" on the card and in the summary, and reported to Ads/GA4 with `value: 0`.
- **Two keys, one integration.** EasyBusy issues a key per endpoint group: the booking key is denied on `/lead`, the leads key is denied on everything else. `Settings::apiKey()` signs booking/company/price-list calls and `Settings::leadApiKey()` signs `POST /lead` and `/lead/{id}/upload` (`Client::withKey()`); both can come from a constant (`EASYBUSY_API_KEY`, `EASYBUSY_LEAD_API_KEY`) or from **Settings → Connection**. Without a leads key the capability probe reports `leads: false` and the inquiry/upload features stay off.
- **The catalogue follows the form's language, not WordPress'.** `Capabilities::resolveLanguage()` asks, in order: the explicit `lang` parameter → `language_map[<site locale>]` → **`ui_locale`** (the language the form itself speaks) → the site locale → the clinic's default. A language the clinic enabled but never filled in answers `200` with an **empty** list, so `Catalog` retries an empty catalogue once in the clinic's default language and logs it.
- **Vendor text is formatted, not printed raw.** `Support\Text` collapses whitespace, de-shouts ALL-CAPS labels while keeping acronyms (`CT`, `RTG`, `CBCT`), lower-cases academic titles and title-cases the personal name after them, and spaces out qualification strings — `"KONZULTACIJE ESTETIKA \nDR. IVANKA KOVAČIĆ "` renders as *Konzultacije estetika dr. Ivanka Kovačić*, `dr.med.dent.` as *dr. med. dent.* Mixed-case names the clinic typed deliberately are left untouched.
- **Country is mandatory.** EasyBusy answers `400 must not be null` to an
  appointment request whose `patientInfo.address.countryCode` is missing, so
  the form always asks for a country and the server always sends one —
  falling back to **Settings → Booking form → Default country** if the browser
  sent nothing usable.
- **Nothing secret in the browser.** The front end only ever talks to the
  plugin's own REST namespace.
- **Front-end sizes in px.** Themes that set `html { font-size: 10px }` would
  otherwise shrink the form to ~60 %.
- **Admin UI is the plugin's own**, scoped under `.ebc-admin`; every table
  pages at 20 rows through `Shell::pagination()`.
- **Bump the version on any asset change** — `?ver=` is the only thing a host
  cache keys on.
