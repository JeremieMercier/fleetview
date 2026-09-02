# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- "Vehicles without a technician" toggle in the nearby technicians modal, next
  to the search radius. When off, only vehicles linked to a GLPI technician
  (explicit association or name matching) are displayed.
- "Show vehicles without a linked technician" display setting, the default
  state of the modal toggle. Off by default.

### Changed

- Unlinked vehicles are hidden by default in the modal: enable the new display
  setting to restore the previous behavior. The filter is applied before the
  closest vehicles are ranked and before the maximum number of vehicles is
  applied, so the top 3 and the result limit only count displayed vehicles.

## [0.4.0] - 2026-08-22

### Added

- Road routes of the 3 closest vehicles drawn on the map, in their marker
  color (OSRM route requests; can be disabled in the display settings).
- Configurable marker popup title: linked GLPI technician name (default) or
  Masternaut vehicle name.
- Optional registration plate in the marker popup.
- Production install and update documentation.

### Changed

- The assign button is disabled, labelled "Technician already assigned", for
  technicians already assigned to the ticket.
- Generic label for the external events setting and GLPI "assign" wording.

### Fixed

- Planned tasks test no longer depends on the time of day.

## [0.3.0] - 2026-08-21

### Added

- Planned interventions (ticket tasks) of the linked technician listed in the
  marker popup, with "in progress", "today" and "tomorrow" badges. The number
  of entries is configurable (0 hides the section).
- GLPI planning external events of the linked technician merged
  chronologically with the tasks (optional, on by default; GLPI planning
  rights apply).
- Marketplace readiness: plugin logo and self-updating manifest.

## [0.2.13] - 2026-08-20

First tagged release.

### Added

- Masternaut (Michelin Connected Fleet) Connect API integration with
  server-side position cache.
- "Nearby technicians" button under the assignee field of geolocated tickets,
  opening a Leaflet map of the fleet vehicles around the ticket location.
- Driving time and road distance estimations through an OSRM-compatible
  routing service, vehicles sorted by driving time.
- Search radius selector in the modal, with configurable default radius and
  maximum number of vehicles.
- Configurable marker colors for the ticket location and the 3 closest
  vehicles.
- Assign a technician straight from the vehicle popup.
- Explicit vehicle to GLPI user associations managed in a dedicated
  configuration tab, with name-matching suggestions and an optional
  name-matching fallback.
- Map filters on Masternaut vehicle groups and statuses, fleet status shown
  in vehicle popups.
- French and English translations.
- Unit and functional tests, GLPI quality tooling (php-cs-fixer, phpstan,
  rector, lints).

[Unreleased]: https://github.com/JeremieMercier/fleetview/compare/0.4.0...HEAD
[0.4.0]: https://github.com/JeremieMercier/fleetview/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/JeremieMercier/fleetview/compare/0.2.13...0.3.0
[0.2.13]: https://github.com/JeremieMercier/fleetview/releases/tag/0.2.13
