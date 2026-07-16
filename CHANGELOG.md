# Timeloop Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 5.0.0 - 2026-07-16

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
