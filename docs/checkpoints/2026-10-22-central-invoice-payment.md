# Central invoice/payment checkpoint

Baseline main a6f15c0; branch fix/central-invoices-payments. No merge or deployment authorized.

Nexus is the financial source for CENTRAL Fleet: finalized billing invoices, details and full-amount payment submissions; private proof; Submitted→Verified/Rejected; rejected invoices can be submitted again. LOCAL Fleet retains existing finance. Tenant/application/actor binding on every central call, exact decimal amounts, invoice locks and idempotent submission keys. New service-account scope commercial.payment.submit. UI and backend validation in progress; not ready for deployment.
