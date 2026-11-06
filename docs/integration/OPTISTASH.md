# OptiStash (warehouse management) in OptiNexus

OptiStash is the WMS of the Opti platform. It runs standalone (local users) or as a SaaS tenant of OptiNexus (single sign-on, roles and
modules from here).

## What is registered here

| Item | Where | Notes |
|---|---|---|
| Application `optistash` | `EcosystemCatalogSeeder`, manifest `backend/database/seeders/manifests/optistash.manifest.json` | Created once; an operator's real URLs are never overwritten. Frontend URL in the manifest is the local default (`http://stash.localtest.me:5210`). |
| Modules (capabilities) | same manifest | `optistash.analysis` (inventory analysis), `optistash.quality` (CAPA, calibration, controlled documents, ISO audit trail), `optistash.iot` (gateways, telemetry), `optistash.supplier_portal`. The core (master data, inbound, inventory, outbound) is not a module: it belongs to every subscription. |
| Permissions | same manifest | 34 keys `optistash.*`, each module's permissions point at its capability. |
| Events | `OptiStashEventCatalogSeeder` | `optistash.pickup.requested`. |
| Suite product and plan | `EcosystemCatalogSeeder` | `OPTI-SUITE` / `SUITE-MONTHLY` gain the OptiStash capabilities. |

Refresh after a release of OptiStash: `php artisan optistash:nexus:manifest > optistash.manifest.json` (run in OptiStash with
`OPTISTASH_FRONTEND_URL` set), replace the file here and seed again (`php artisan db:seed --class=EcosystemCatalogSeeder`).

## Event `optistash.pickup.requested`

Sent by OptiStash when a shipment is dispatched and the tenant's transport mode is OPTIDELI. `aggregate_type` `shipment`; payload:
`shipment_number`, `warehouse{code,name,address,city}` (the place only, never its zones, aisles, racks or bins) and
`items[]{product_code,product_name,quantity,uom}`. The event id is stable, so a retry never makes a second request.

OptiDeli consumes it (`OptiDeli/docs/integration/PICKUP_REQUESTS.md`): it lands in the inbox **Permintaan jemput** and becomes an order and an SPM
once the warehouse is linked and the tenant's defaults are known. Subscription, once per installation:
`ecosystem:register-event-subscription optideli "optistash.pickup.*" --url=<deli-api>/api/v1/integration/optinexus/events --secret-file=...`.
OptiDeli has **one** receiver secret (`OPTINEXUS_EVENT_WEBHOOK_SECRET`), so after creating the subscription its `signing_secret` is set to that same
value (the secret OptiNexus generates is discarded); otherwise OptiDeli answers 401 to the delivery.

## Operator steps for SaaS use

1. Seed the catalog (`ProductionSeeder` does it on a deployment).
2. Register the OIDC client and the service account of `optistash` (`ecosystem:register-oidc-client`, `ecosystem:register-service-account`).
   Redirect URI `<stash console>/api/v1/auth/sso/callback`, back-channel logout `<stash console>/api/v1/integration/optinexus/backchannel-logout`.
   The service account needs scopes `authorization.check`, `commercial.read` and `event.write`.
3. Put the client id/secret and service account credentials in `deploy/optiecosystem/.state/stash.env`, set
   `OPTISTASH_IDENTITY_MODE=optinexus` and `OPTISTASH_DEPLOYMENT_MODE=saas`, and recreate the containers (`up -Products stash`).
4. Assign the application to a tenant in OptiNexus; OptiStash provisions the tenant from the assignment event.

Deployment: `deploy/optiecosystem` knows the product `stash` (`up -Products deli,stash`, console 5210, API 8400).
