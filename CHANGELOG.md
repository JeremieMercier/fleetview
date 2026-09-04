# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The vehicle to technician associations table is paginated (GLPI "number
  of items per page" preference, standard pager), with its filters and sort
  handled server-side on the whole fleet: only one page of rows, each
  carrying two dropdowns, is rendered, which keeps the tab fast on large
  fleets. Associations are saved page by page.
- Entity (with the "child entities" flag) on each vehicle to technician
  association, in the associations tab: an association is only taken into
  account for the tickets of its entity. Existing associations are attached to the root
  entity with child entities enabled on update, which keeps the previous
  behaviour.
- "Maximum search radius" setting, the upper limit of the radius selector of
  the map (150 km by default, 500 km at most: the provider limit). The
  existing "Search radius" setting is documented as what it always was: the
  default value of the selector. Existing installations get the 150 km
  limit on update: raise it in the display settings if the map users need a
  wider search.

### Changed

- The routing service (driving times, road routes) is disabled by default:
  the URL of the public OSRM demo server was the default value, so the GPS
  coordinates of the ticket sites and of the vehicles (coordinates only, no
  identifier) were sent to a third party from the first map opening, without
  an explicit decision. Enabling it is
  now the administrator's choice; the configuration form warns when the URL
  points outside the organisation network. Existing installations keep
  their configured URL (marketplace security review of 0.5.0,
  [#12](https://github.com/JeremieMercier/fleetview/issues/12)).

### Fixed

- Name-matching suggestions of the associations tab are no longer applied
  by themselves: the user dropdown of a suggested row stays empty and the
  suggestion is a button filling it, so saving the tab (for other rows)
  does not silently turn every suggestion into an association.

### Security

- The GLPI identity of the vehicle technicians (association, name matching,
  displayed name, assignment) is scoped to the entity of the ticket, as the
  native actor dropdown: the associations were global and the name matching
  considered every active user, so a technician of one entity could follow
  the live positions of the named technicians of unrelated entities. The fleet itself stays global
  (one Masternaut account): out of reach, a vehicle is "not linked", without
  name, and hidden by default (marketplace security review of 0.5.0,
  [#12](https://github.com/JeremieMercier/fleetview/issues/12)).
- The assignment from the map only accepts the technicians the map may
  offer for the ticket (linked to a vehicle, holding the "own ticket" right
  in the ticket entity, as the native actor dropdown requires): the assignee
  was taken from the request and only checked as an existing active user,
  so any active account (another entity, a service account...) could be
  assigned and notified (marketplace security review of 0.5.0,
  [#12](https://github.com/JeremieMercier/fleetview/issues/12)).
- The planned tasks of the marker popup follow the ticket read rights of the
  user (see all / see group / see mine / see assigned...), as the GLPI
  planning does, and need a task right: a profile allowed to see all
  plannings but not all tickets could read the numbers and titles of tickets
  it may not open (marketplace security review of 0.5.0,
  [#12](https://github.com/JeremieMercier/fleetview/issues/12)).
- The profiles granted the map right on install are documented and pinned by
  a test: with stock GLPI profiles, Observer and Supervisor are granted too,
  as they may assign tickets (Observer to itself), which the documentation
  did not list. The administrator is invited to review the grant after the
  installation (marketplace security review of 0.5.0,
  [#12](https://github.com/JeremieMercier/fleetview/issues/12)).
- The radius selector of the map is bounded by the new maximum radius
  setting: the configured radius was only a default, any map user could
  widen the search to the provider maximum of 500 km whatever the
  administrator intended (GLPI marketplace security review of 0.5.0,
  [#12](https://github.com/JeremieMercier/fleetview/issues/12)).

## [0.5.0] - 2026-09-02

### Added

- "Vehicles without a technician" toggle in the nearby technicians modal, next
  to the search radius. When off, only vehicles linked to a GLPI technician
  (explicit association or name matching) are displayed.
- "Show vehicles without a linked technician" display setting, the default
  state of the modal toggle. Off by default.
- "Nearby technicians map" right (`plugin_fleetview_map`), managed in a
  Fleetview tab of the profile form. Granted on install to the profiles
  allowed to assign tickets, to others or to themselves.

### Changed

- Unlinked vehicles are hidden by default in the modal: enable the new display
  setting to restore the previous behavior. The filter is applied before the
  closest vehicles are ranked and before the maximum number of vehicles is
  applied, so the top 3 and the result limit only count displayed vehicles.

### Security

- The map endpoints (ticket context, nearby vehicles, assignment) now require
  the new map right: live fleet positions and driver names were reachable by
  any user allowed to view the ticket, requesters included (GLPI marketplace
  security review, [#12](https://github.com/JeremieMercier/fleetview/issues/12)).
- The planned interventions of the marker popup follow the GLPI planning
  right (see all / see group / see mine), as the external events already did;
  the section is hidden for technicians whose planning the user may not
  consult.

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

[Unreleased]: https://github.com/JeremieMercier/fleetview/compare/0.5.0...HEAD
[0.5.0]: https://github.com/JeremieMercier/fleetview/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/JeremieMercier/fleetview/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/JeremieMercier/fleetview/compare/0.2.13...0.3.0
[0.2.13]: https://github.com/JeremieMercier/fleetview/releases/tag/0.2.13
