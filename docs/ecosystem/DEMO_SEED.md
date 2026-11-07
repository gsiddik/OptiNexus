# Demo data for the whole ecosystem

For trying the products together on a local installation. It never touches an existing tenant: it creates its own customer and tenant
("Demo Ekosistem") and can be run again at any time (what exists is kept, nothing is reset).

```powershell
cd OptiNexus\deploy\optiecosystem
.\seed-demo.ps1            # add -Reissue for a new temporary password of the administrator
```

Local only: the commands refuse to run unless `APP_ENV` is `local`/`testing` or `ECOSYSTEM_ALLOW_DEMO_SEED=true` (the script sets it).

## What it does, in order

1. **OptiNexus** `php artisan ecosystem:seed-demo` acts as the superadmin through the operator API (in this process, so the validation,
   events and audit are the real ones): customer, tenant, one active `SUITE-MONTHLY` subscription to OptiFleet, OptiDeli, OptiStash,
   OptiEntry and OptiRadar, and the tenant administrator `admin@demo-ekosistem.test`. The temporary password goes to
   `deploy/optiecosystem/.state/demo-ecosystem.txt` only (it expires in two days; run again with `-Reissue`).
   The assignment events provision the tenant in OptiDeli and OptiStash.
2. **OptiDeli** `php artisan ecosystem:demo-data`: regions, cities, two customers, warehouses/stores/pool, two fleet types, the units;
   two own vehicles (with tracker ids) and two drivers; the transporter *CV Transporter Demo* with a verified vehicle; the transporter
   price mapping of the own fleet and of the vendor (unit and margin per row), a customer margin and a contract tariff; four orders:
   priced by the mapping and confirmed (price locked), a contract customer, the vendor's vehicle to Surabaya, and a drum with no row in
   the matrix (manual price waiting for approval). The vehicles are announced by the real master events: OptiRadar gets a device for each.
3. **OptiStash** `php artisan ecosystem:demo-data`: a warehouse with its structure, products, a supplier and a customer, a received and
   put-away delivery, an outbound order that is allocated, picked, packed and dispatched for **OptiDeli** (the pickup request arrives in
   the *Permintaan jemput* inbox of OptiDeli, ready to be turned into an order), a shipment still being loaded, a CAPA, an instrument, a
   document, a stocktake and an IoT gateway.

Actors that appear in the audit trail (`demo.perencana@…`, `demo.penyetuju@…`, `demo.gudang@…`) cannot sign in: they only stand for the
people who "did" the steps.

## What is not in this version

* **OptiFleet and OptiEntry** create the tenant when the administrator signs in for the first time, so there is nothing to load before
  that, and their demo data is not part of this version.
* **OptiRadar** receives the devices through the sync; positions need a real tracker or a device simulator.

## After the first sign-in

Sign in to any product with `admin@demo-ekosistem.test` through OptiNexus. To see the maker-checker flows (tariffs, manual order prices)
you need a second person: create another user of the tenant in OptiNexus.
