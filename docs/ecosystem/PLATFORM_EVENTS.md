# Events OptiNexus publishes itself

Product events reach OptiNexus through `POST /api/v1/events` and are fanned out by the event broker. OptiNexus also publishes a few
events of its own. They use the same broker, so an application receives one only when it **subscribed** to the key
(`php artisan ecosystem:register-event-subscription <application_code> <event_key_or_prefix.*> --url=<endpoint> --secret-file=<0600 file>`)
and is **assigned to the event's tenant**. The webhook carries `source_system: optinexus` and is signed like every broker webhook
(`Opti-Webhook-Signature: v1=<HMAC-SHA256(secret, "<delivery id>.<timestamp>.<body>")>`, five-minute window, de-duplicate on `event_id`).
The keys are in the event catalog without an application (seeded by `EcosystemCatalogSeeder`).

| Event key | When | `data.payload` |
|---|---|---|
| `cgo.tenant.application_assigned` | An application was assigned to a tenant because its subscription now covers it | `application_code`, `application_name`, `tenant_code`, `tenant_name` |
| `cgo.tenant.application_removed` | An application was removed from a tenant because no subscription covers it any more | same |

These two events are delivered to an application assigned to the tenant even while the subscription is still being created (DRAFT), unlike product
events of an application whose subscription is required: the assignment happens at creation, and the point is to prepare the tenant early.

Applications are assigned only by subscriptions (`SubscriptionApplicationService::sync`), so that is the single place the events are
published, after the database commit. A sync that changes nothing publishes nothing. A failure to publish is reported but never fails the
subscription change. The removal event can reach only applications that are still assigned to the tenant (the removed one no longer is).

Used by OptiRadar to create a tenant's group the moment OptiRadar is assigned (see `OptiRadar/docs/optinexus-sso.md`).
