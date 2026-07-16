# Timeloop Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

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
