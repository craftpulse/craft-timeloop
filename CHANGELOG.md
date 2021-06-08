# Timeloop Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

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
