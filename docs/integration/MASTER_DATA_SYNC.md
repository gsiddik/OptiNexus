# Vehicle and driver master data across the platforms

Status: stage 1 (contract) and stage 2 (OptiDeli <-> OptiFleet vehicles) are implemented; OptiRadar (stage 3) and drivers (stage 4) are not. The flows that existed before are listed under "Today".

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
  "telematics": { "device_ref": "...", "last_seen_at": "UTC" } }
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
- Vendor-owned vehicles in OptiDeli are never published: they are not the tenant's assets.

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
3. OptiRadar: telematics group out, key-based linking; Java change.
4. Drivers end to end, including data for predictive maintenance.

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

Not yet: OptiRadar, drivers.
