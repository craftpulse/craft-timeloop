# Timeloop Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 5.1.0 - Unreleased

### Important upgrade notes

- **The storage upgrade is one-way.** Stored field values are migrated to a new RRULE-based format on `craft up`. Downgrading to 5.0.0 afterwards reads every migrated value as empty, so pin your plugin version before deploying. The migration is lossless, idempotent, and skips (rather than breaks on) any value it cannot parse; skipped values are upgraded at read time instead.
- **Entries saved with 4.x or a 5.0.0 beta get the #62 end-time repair applied during migration.** No manual resave is needed for that fix on this upgrade path.
- Four narrow output changes versus 5.0.0, each toward correctness: an occurrence landing exactly on the end date is now included; monthly loops starting on day 29/30 (not the month's last day) skip short months instead of clamping; an occurrence landing exactly on "now" counts as upcoming; `recurringDates()` backed by a fresh occurrence index returns all in-window dates instead of capping at 100 for never-ending rules.
- The GraphQL `loopReminder` field previously resolved to `null` for every value due to a bug; it now returns the reminder period (for example `days`). Schema shape is unchanged.
- Set up the nightly horizon roll on new installs: `php craft timeloop/occurrences/refresh` (cron), so the occurrence index keeps covering future dates and next years' public holidays.

### Added
- RRULE-based recurrence engine (`rlanvin/php-rrule`): timezone-correct expansion, `COUNT`/`UNTIL` end conditions, exclusion dates (`EXDATE`), extra dates (`RDATE`), and round-tripping of hand-written RRULEs the editor does not cover ([#9](https://github.com/craftpulse/craft-timeloop/issues/9), [#12](https://github.com/craftpulse/craft-timeloop/issues/12))
- Public-holiday exclusions per value (spatie/holidays): locale-aware country resolution with region support, resolved fresh at read time so future years stay correct without re-saving entries
- Screens API on the field value: `isActiveNow`, `isActiveAt()`, `currentOccurrence`, `nextOccurrence`, `occurrences(from, to, limit)`
- Occurrence index table with element-query params `activeTimeloop()`, `timeloopBetween()` and next-occurrence ordering, a save-time indexer with queue offloading, and `timeloop/occurrences/refresh` + `timeloop/occurrences/rebuild` console commands
- Element-index sorting by next upcoming occurrence
- Localized rule summaries: `summary` on the field value (18 locales via the rrule engine) and a live summary preview in the editor
- Dutch, French and German control panel translations ([#14](https://github.com/craftpulse/craft-timeloop/issues/14), [#32](https://github.com/craftpulse/craft-timeloop/issues/32))
- Additive GraphQL fields: `rrule`, `timezone`, `summary(locale)`, `isActiveNow`, `nextOccurrence`, `occurrences(rangeStart, rangeEnd, limit)`, `exceptions`; mutation input now also accepts `rrule`, `timezone`, `exdates`, `rdates` and `holidays`
- ICS calendar export per field value behind a signed, tokenized URL (`craft.timeloop.icsUrl(entry, 'fieldHandle')`), with holiday exclusions baked in

### Changed
- The field editor was rebuilt on Craft form macros and Garnish; it now renders correctly on first load inside Matrix and Neo ([#41](https://github.com/craftpulse/craft-timeloop/issues/41), [#54](https://github.com/craftpulse/craft-timeloop/issues/54))
- Vue and the Vite buildchain were removed, along with the `nystudio107/craft-plugin-vite` dependency
- Field values are stored in a new RRULE-based format; legacy values (4.x, 5.0.0 betas, 5.0.0) are migrated by `craft up` and normalized at read time wherever the migration has not run yet
- Raw RRULE input from GraphQL mutations is validated at save time instead of failing on first render
- Code-quality sweep: section headers, PHPDoc completeness and `@throws` chains across the codebase ([#69](https://github.com/craftpulse/craft-timeloop/issues/69), [#75](https://github.com/craftpulse/craft-timeloop/issues/75), [#84](https://github.com/craftpulse/craft-timeloop/issues/84))

### Fixed
- Two Timeloop fields on one entry type no longer collide in the GraphQL schema ([#82](https://github.com/craftpulse/craft-timeloop/issues/82))
- The GraphQL `loopReminder` field resolves to the reminder period instead of always `null` ([#83](https://github.com/craftpulse/craft-timeloop/issues/83))

## 5.0.0 - 2026-07-16

### Important upgrade notes

Updating runs no migrations and does not touch stored field data. A few behaviors changed, though — review these before deploying:

- **Entries saved with an older version may store the loop end date with the wrong time** ([#62](https://github.com/craftpulse/craft-timeloop/issues/62)): the end date was saved with the *start* time instead of the end time. 5.0.0 fixes this on save, and existing entries are corrected by resaving them. After updating, run this for each section that uses a Timeloop field:

  ```sh
  php craft resave/entries --section=<sectionHandle>
  ```

  The resave re-serializes the field, merging the stored end time (or the 23:59 default) back into the end date.
- **Empty Timeloop fields now normalize to a `TimeloopModel` instead of `null`.** A presence check like `{% if entry.myField %}` is now always true — check the start date instead: `{% if entry.myField.loopStartDate %}`.
- **`craft.timeloop.getUpcoming()` returns `null` instead of `false`** when there is no upcoming date. Truthiness checks are unaffected; strict `is same as(false)` comparisons need updating.
- **`recurringDates()` returns an array (possibly empty) instead of `null`**, and dates that fall exactly on the start or end boundary are now included.
- **Upgrading from Craft 4 (Timeloop 4.x):** `loopStart` and `loopEnd` return `DateTime` objects instead of strings — output them with a date filter, e.g. `{{ entry.myField.loopStart | date('d/m/Y') }}`. All other template variables (`dates`, `upcoming`, `nextUpcoming`, `period`, `timestring`, `reminder`, `getDates()`) keep their signatures and types.
- **Generated dates are corrected**, not identical: weekly loops no longer overshoot the `limit` argument or emit dates before the start date. If templates compensated for those bugs, they can be simplified.

### Fixed
- Fixed a data bug where the loop end date was saved with the start time instead of the end time ([#62](https://github.com/craftpulse/craft-timeloop/issues/62))
- Fixed an autoload failure when SEOmatic is not installed ([#78](https://github.com/craftpulse/craft-timeloop/issues/78))
- Fixed crashes when the field value is empty or has no loop period ([#63](https://github.com/craftpulse/craft-timeloop/issues/63), [#64](https://github.com/craftpulse/craft-timeloop/issues/64), [#65](https://github.com/craftpulse/craft-timeloop/issues/65), [#66](https://github.com/craftpulse/craft-timeloop/issues/66), [#74](https://github.com/craftpulse/craft-timeloop/issues/74))
- Fixed `isValueEmpty()` so the field can be treated as empty and required validation works ([#79](https://github.com/craftpulse/craft-timeloop/issues/79))
- Fixed `upcoming` returning nothing when exactly one upcoming date exists
- Fixed upcoming dates never being computed for loops without an end date
- Fixed weekly loops returning more dates than the `limit` parameter allows
- Fixed weekly loops returning dates that fall before the loop start date
- Fixed `recurringDates()` excluding dates that fall exactly on the start or end boundary
- Fixed the reminder date not being validated after `DateTime::modify()` ([#67](https://github.com/craftpulse/craft-timeloop/issues/67))
- Fixed the plugin component registration conflict between the constructor and `init()` ([#76](https://github.com/craftpulse/craft-timeloop/issues/76))
- Fixed the README containing content from another plugin

### Changed
- The Timeloop field value now always normalizes to a `TimeloopModel`, including empty values
- Upcoming dates are now computed lazily instead of on every field load
- `craft.timeloop.getUpcoming()` now returns `null` instead of `false` when there is no upcoming date
- Removed the unused GraphQL interface stub and dead code ([#68](https://github.com/craftpulse/craft-timeloop/issues/68), [#73](https://github.com/craftpulse/craft-timeloop/issues/73))

### Release
- Stable Craft CMS 5 release

## 5.0.0-beta.3 - 2025-03-12

### Release
- Craft CMS 5 release update
