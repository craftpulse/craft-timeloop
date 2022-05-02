# Timeloop Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 4.0.0-beta.1 - 2022-05-02

### Updated
- Updated codebase for PHP 8 and make Craft 4 ready
- Updated the normalizeValue of the field to provide the correct data for Craft 4
- Added typings to the functions and properties

## 1.0.1 - 2021-11-21

### Fixed
- Fixed documentation url type. Thanks @brandonkelly

## 1.0.0 - 2021-11-08

### Added
- Created a seperate GQL interface
- Added the posibility of creating more granular reminders
- Added a more detailed future date check

### Changed
- Cleaned up code comments
- Code cleanups

### Fixed
- Fixed a bug when the next recurring date is in the current week, month or year this would be skipped.
- Fixed an issue where the upcoming date and next upcoming date would show the same value

## 1.0.0-rc.6 - 2021-07-13

### Added
- GraphQL Mutations

## 1.0.0-rc.5 - 2021-06-30

### Changed
- When no data is entered, graphQL queries will return `null` on the fields rather than `false`

### Fixed
- Fixed the issue where the Vue app wouldn't render if there were multiple timeloop fields in a single entry
- Fixed the issue if the field was set to required, the entry would still save

## 1.0.0-rc.4 - 2021-06-08

### Added
- Added the LoopPeriod GQL Object
- Added the Timestring GQL Object

### Changed
- Updated the field labels + instructions for more clarity
- Changed the styling for the loop period field to make it clear it's grouped
- Updated the README

## 1.0.0-rc.3 - 2021-06-05

### Fixed
- Fixed an issue where the entry wouldn't autosave / save in drafts as long as there was no startTime and endTime selected. Now defaults to `00:00:00` && `23:59:00` when none is selected

## 1.0.0-rc.2 - 2021-06-05

### Changed

#### Fieldnames
- `loopStart` -> `loopStartDate`
- `loopEnd` ->  `loopEndDate`
- `loopStartHour` -> `loopStartTime`
- `loopEndHour` -> `loopEndTime`

### Fixed
- Fixed a critical issue that prevented creating a new entry

## 1.0.0-rc.1 - 2021-06-04

### Added
- Initial release
