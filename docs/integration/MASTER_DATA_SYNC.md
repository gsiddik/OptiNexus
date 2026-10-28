# Vehicle and driver master data across the platforms

Status: stage 1 (contract), stage 2 (OptiDeli <-> OptiFleet vehicles) stage 3 (OptiRadar -> OptiFleet) and stage 4 (drivers) are implemented. The flows that existed before are listed under "Today".

## Goal
A tenant keys a vehicle or a driver once, in whichever platform it is working in; the other platforms receive what each of them needs.

## Today
- OptiFleet -> OptiNexus gateway: vehicle directory (id, registration number, VIN) for telematics linking.
- OptiRadar -> OptiNexus -> OptiFleet: devices matched to vehicles by registration number; odometer flows to OptiFleet.
- OptiFleet -> OptiDeli: `optifleet.vehicle.availability_changed` (status only) and a live availability query.
- Vehicles and drivers themselves are not shared: OptiDeli and OptiFleet each hold their own.

## Ownership (owner decision)
| Attribute group | Owner | Examples |
|---|---|---|
| Asset | OptiFleet | brand, model, year, VIN, chassis/engine number, fuel, odometer, documents of the asset, branch, category, operational status |
| Transport | OptiDeli | transport type (carrier type), payload capacity, box dimensions, vendor ownership |
| GPS / telematics | OptiRadar | device, position, ignition, telematics odometer |
| Identity | nobody (the key) | registration number, normalised: upper case, without spaces and dashes, per tenant |

A platform only ever overwrites the attributes it owns; an event from a non-owner never changes an owned attribute, it can only create a
missing vehicle. Drivers follow the same pattern: OptiFleet owns the person and licence data (for predictive maintenance), OptiDeli owns
the transport eligibility (verification, vendor), OptiRadar owns driver identification by tag. The driver key is the licence (SIM) number.

## Transport
OptiNexus is the broker, it does not store the master. A platform publishes `<own prefix>.vehicle.upserted` (`optideli.`, `optifleet.`,
`optiradar.`) to `POST /events`; the event broker (INTEGRATION_GUIDE section 4b) delivers it to the other platforms' webhook subscriptions
(`*.vehicle.*`, `*.driver.*`). A producer never receives its own event. Delivery is at least once and unordered; consumers de-duplicate on
`event_id` and compare `version`.

Event keys: `optifleet.vehicle.upserted`, `optideli.vehicle.upserted`, `optiradar.vehicle.upserted` (and `.driver.upserted`). Registration of
the keys: event catalog seeders of each product (same pattern as `OptiFleetEventCatalogSeeder`).

## Payload (`data.payload`)
```json
{ "key": "B1234XY", "registration_number": "B 1234 XY", "version": 12, "changed_at": "UTC",
  "asset": { "brand": "...", "model": "...", "year": 2021, "vin": "...", "chassis_number": "...", "engine_number": "...", "fuel_type": "..." },
  "transport": { "carrier_type_code": "CDD", "capacity_g": 2500000, "length_mm": 4200, "width_mm": 2000, "height_mm": 2000 },
  "telematics": { "device_ref": "...", "device_name": "..." } }
```
A producer sends only the groups it owns (OptiDeli: `transport`; OptiFleet: `asset`; OptiRadar: `telematics`) plus the key. A group that is
absent means "not mine to say", never "empty". `version` increases per vehicle per producer; a consumer ignores a version older than the one
it holds for that producer. Drivers: `key` = normalised licence number, groups `person` (name, phone, licence class and expiry, birth date)
and `eligibility` (OptiDeli: verified until, vendor).

## Consumers
- OptiFleet: unknown key -> create the vehicle as a **DRAFT** (see below), filled with whatever the event carried; known key -> apply only the
  `transport` and `telematics` groups to its read-only side fields. Its staff complete a draft, which then becomes an ordinary vehicle.
- OptiDeli: unknown key from OptiFleet -> create its own vehicle as a transport draft (no carrier type yet, not assignable) for the tenant's own
  fleet; known key -> take `asset` fields (brand, model, year, VIN). The planner still needs the transport group, which stays OptiDeli's.
- OptiRadar: uses the key to link a device; never creates from an event except the optional device-linked placeholder.
- A tenant that is not linked to OptiNexus (standalone) is unaffected: no events, no drafts.
- Vendor-owned vehicles in OptiDeli are never published to OptiFleet: they are not the tenant's assets and it does not maintain them. They go to OptiRadar only (stage 4).

## What stage 2 must change in OptiFleet (found while reading the code)
`vehicles` requires `branch_id`, `vehicle_category_id`, `brand` and `model` (NOT NULL) and `status` has no draft value. A draft vehicle
therefore needs: a `DRAFT` status (excluded from availability, maintenance planning and counts until completed), those four columns nullable
while DRAFT (enforced complete by a CHECK when not DRAFT), a "complete draft" screen, and a `source_application` column showing where it came
from. Same idea for drivers.

## Loops and conflicts
Events carry `origin` (the producing application) and a consumer never republishes an event it applied from another application; it
republishes only when its own staff change a group it owns. Concurrent creation of the same key in two platforms converges: both keep their
own row, the key matches, and each applies the other's groups.

## Stages
1. This contract, the event catalog entries in OptiNexus, and the shared key normaliser.
2. OptiDeli <-> OptiFleet: publishers, receivers, OptiFleet draft status, backfill command (`master:publish --all`).
3. OptiRadar: telematics group out (done: OptiRadar publishes, OptiFleet keeps it; the existing gateway keeps linking odometer readings).
4. Drivers: OptiDeli owns them; OptiFleet reads the history of who drove a vehicle; OptiRadar's drivers come into OptiDeli as drafts (see below).

## Stage 2 as built (vehicles, OptiDeli <-> OptiFleet)
- **OptiDeli** queues `optideli.vehicle.upserted` (transport group) in `master_events` when a vehicle of its own fleet is registered or its
  transport data is changed (`PUT fleet/vehicles/{id}/transport`); `optideli:master:relay` (every minute) posts it to OptiNexus
  (`POST /events`, scope `event.write`); `optideli:master:publish [--tenant=]` queues every own vehicle (first start-up, outage).
  From `optifleet.vehicle.upserted` it takes brand, model, year and VIN; an unknown vehicle becomes a transport draft
  (`transport_complete = false`, source `OPTIFLEET`) that the dispatch refuses until the transport data is completed.
- **OptiFleet** queues `vehicle.upserted` (asset group) in its integration outbox when a vehicle is created, imported, changed or
  completed; the existing `optinexus:relay-events` sends it. `optifleet:master:publish [--tenant=]` is the backfill. From
  `optideli.vehicle.upserted` it keeps the transport group in `vehicle_transport_profiles`; an unknown vehicle becomes a `DRAFT`
  (source `OPTIDELI`), outside the subscription capacity, finished with `POST /app/vehicles/{id}/complete` (branch, category, brand,
  model). A draft is visible to users whose data scope is the whole tenant. The database refuses a non-draft without branch,
  category, brand and model.
- Both receive at `POST /api/v1/integration/optinexus/events` (OptiFleet) and the same path (OptiDeli),
  signature-checked; versions per vehicle and producer (`master_versions`) make older or repeated deliveries harmless.

### Switching it on (operator)
1. Event catalog: `php artisan db:seed --class=OptiDeliEventCatalogSeeder` and `--class=OptiFleetEventCatalogSeeder` in OptiNexus.
2. Scope `event.write` on the OptiDeli service account (OptiFleet's has it); `OPTINEXUS_EVENTS_ENABLED=true` in OptiFleet.
3. Subscriptions: `ecosystem:register-event-subscription optifleet "optideli.vehicle.*" --url=<fleet-api>/api/v1/integration/optinexus/events --secret-file=...`
   and `ecosystem:register-event-subscription optideli "optifleet.vehicle.*" --url=<deli-api>/api/v1/integration/optinexus/events --secret-file=...`;
   put each secret in the receiver's environment (`OPTINEXUS_EVENTS_WEBHOOK_SECRET` in OptiFleet, `OPTINEXUS_EVENT_WEBHOOK_SECRET` in OptiDeli).
4. Run both backfill commands once for tenants that already have vehicles.

Screens: OptiDeli, Armada > vehicle row "Lengkapi data angkutan" (PUT `fleet/vehicles/{id}/transport`); OptiFleet, vehicle list, filter Draft, row button "Lengkapi" (branch, category, brand, model; shows the OptiDeli transport data).


## Stage 3 as built (OptiRadar -> OptiFleet)
- **OptiRadar** queues `optiradar.vehicle.upserted` (telematics group: `device_ref` = the device's unique id, `device_name`) in its existing
  OptiNexus outbox when a device is saved and carries a registration number (device attribute `registrationNumber`, or `plate`; the name alone
  never counts) and belongs to a tenant. The device form has a "Nomor polisi" field for it. `POST /api/optinexus/vehicles/publish`
  (administrator) queues every qualifying device again (first start-up, outage). OptiRadar consumes nothing: it owns no attribute that depends on another
  platform's data, so no event can loop.
- **OptiFleet** keeps the group in `vehicle_telematics_profiles` (read-only); an unknown vehicle becomes a `DRAFT` (source `OPTIRADAR`), completed by
  staff like one from OptiDeli. The same receiver, `master_versions` per producer and the owner check (an event only counts for the group its
  producer owns) apply.
- Operator steps: seed the catalog (`OptiRadarEventCatalogSeeder`), `optiradar` already has `event.write`; register
  `ecosystem:register-event-subscription optifleet "optiradar.vehicle.*" --url=<fleet-api>/api/v1/integration/optinexus/events`; OptiFleet has one receiver secret, so
  the new subscription carries the same secret as its other one (set it in OptiNexus after creating it).

## Stage 4 as built (drivers)
Owner decision: **OptiDeli owns the drivers.** OptiFleet keeps no driver register and has nothing to add, change or complete; it only reads the
history of who drove each vehicle. This replaces the earlier idea of a shared person group.

- **OptiDeli -> OptiFleet**: `optideli.vehicle.driver_assigned` is queued when a trip of the own fleet **leaves** (SPM trip `IN_TRANSIT`, or an
  order trip) and when a day of a vehicle contract is recorded as `PRESENT` with a driver. Payload: `key` + `registration_number` of the vehicle,
  `assigned_at` (UTC), `reference{type SPM_TRIP|ORDER_TRIP|ATTENDANCE, id, number}`, `driver{name, phone, license_masked}`. The licence number is masked to its
  last four characters; date of birth and address are never shared. Vendor vehicles and drivers are not part of it (OptiFleet never hears of them). It is a fact, not a versioned master:
  OptiFleet stores one row per `(reference.type, reference.id)`, so repeats are harmless and order does not matter.
- **OptiFleet** shows the facts as **periods per vehicle** (the same driver on consecutive facts is one period) in the **Drivers** tab of the vehicle, and the
  driver who last had the vehicle before a work order was opened as information on the work order (no link between a driver and a vehicle is kept
  there). Both exist **only for a tenant that OptiNexus says has bought OptiDeli** (`CentralGovernance::commercial()['applications']`, cached with the
  usual TTL, decided by the backend; the endpoint answers 404 otherwise). The history is matched to a vehicle by plate key when read, so a fact that arrives before the
  vehicle's own event is not lost.
- **OptiRadar -> OptiDeli**: `optiradar.driver.upserted` (key = normalised licence number from the driver attribute `licenseNumber`; group `telematics{tag_id}`).
  OptiDeli creates a **draft driver** (source `OPTIRADAR`, cannot be allocated: dispatch refuses it) when it does not know the licence number, or only attaches
  the tag to a known one; staff complete licence validity and eligibility in the Pengemudi screen (`PUT fleet/drivers/{id}/profile`). A vendor's driver is never touched.
- **Vendors go to OptiRadar only.** A vendor's vehicle may carry a GPS or IoT unit that has to be monitored in OptiRadar (and later shown in OptiDeli); the
  tenant does not maintain it, so OptiFleet must not hear of it. Verified vendor vehicles and drivers (published when the tenant verifies or (de)activates them)
  have keys of their own, `optideli.vendor_vehicle.upserted` and `optideli.vendor_driver.upserted`, which only OptiRadar subscribes to; the own fleet keeps
  `optideli.vehicle.*` for OptiFleet. OptiRadar keeps the latest version per tenant and key and marks the matching device (registration number) or driver
  (licence number) with the attributes `ownership=VENDOR`, `vendorCode`, `vendorName`, `carrierType`, `vendorActive`; a record that arrives before its device is applied
  when the device is saved. OptiRadar creates nothing from a record (it cannot invent a tracker's identifier) and publishes nothing back.
- Operator steps: seed the catalog (`OptiDeliEventCatalogSeeder`, `OptiRadarEventCatalogSeeder`); the existing `optifleet <- optideli.vehicle.*` subscription already
  carries the new OptiDeli driver fact; add `optideli <- optiradar.driver.*` (OptiDeli has one receiver secret, so the new subscription carries the same one) and
  `optiradar <- optideli.vendor_vehicle.*` and `optiradar <- optideli.vendor_driver.*` (OptiRadar has one webhook secret, same rule).

## Vehicles in OptiRadar (tenant and vendor)
Every vehicle OptiDeli knows reaches OptiRadar, so it is there to be tracked without a second entry: the tenant's own fleet as `optideli.fleet_vehicle.upserted`
(when its transport data is complete) and a vendor's verified vehicles as `optideli.vendor_vehicle.upserted`. OptiFleet subscribes to neither. Both carry
`active` and the optional `tracker_id`, a field on the OptiDeli vehicle forms: the identifier the tracker reports to the server (the IMEI of a GPS tracker, a serial
number or another ID, exactly as OptiRadar's device form calls it). One tracker identifier belongs to one vehicle of a tenant.

OptiRadar's rules (a device needs a unique identifier, which OptiDeli may not know):
1. A device of the tenant that already carries the registration number is only marked (`ownership` TENANT or VENDOR, vendor, carrier type, `vendorActive`); no second one is made.
2. Else a device is made in the tenant group: name = the plate, attribute `registrationNumber`, identifier = `tracker_id`, else the placeholder `BELUM-<key>`.
3. A placeholder is completed (its identifier replaced) when `tracker_id` arrives later; an operator may also replace it in the device form. A placeholder is never shared
   with OptiFleet or anybody else (`optiradar.vehicle.upserted` is not sent for it).
4. An inactive vehicle switches its device off; it is switched on again when the vehicle is active again. A device is never deleted (its history stays).
5. A `tracker_id` that belongs to a device of another tenant is never taken over (the placeholder is used); one that belongs to an unregistered device of the same tenant is adopted.
6. A tenant without a group in OptiRadar gets nothing made (no error).
Operator step: seed the catalog and add `optiradar <- optideli.fleet_vehicle.*` (same webhook secret as OptiRadar's other subscriptions).
