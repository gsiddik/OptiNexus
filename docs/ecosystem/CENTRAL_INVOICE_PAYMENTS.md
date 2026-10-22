# Integrated Fleet invoice payments

Nexus is the financial source for CENTRAL Fleet. LOCAL Fleet keeps its existing contract, subscription, billing, invoice and payment services. No central invoice/payment is copied into Fleet financial tables.

Invoices generated from FINALIZED billing are visible immediately to the matching tenant and application; the existing Nexus Issue step is preserved. Only Issued/Overdue invoices (or historical Partially Paid invoices) with a positive balance and no Submitted payment are payable. The submission must equal the entire remaining balance; new partial payments are refused.

Fleet sends its signed-in user's Nexus subject with its service credential. Nexus rechecks active tenant membership, current application subscription/access and the specific account.invoice.view, account.payment.view or account.payment.submit permission. Tenant-bound service credentials cannot cross tenants. Invoice product must cover the calling Fleet application. Every response is checked against tenant and requested document IDs.

New scope commercial.payment.submit is registered. Migration adds it only to existing Fleet service-account allowlists that already contain commercial.read; null legacy allowlists and all other applications remain unchanged. It never bypasses the acting-user permission check.

Submission keys make identical retries idempotent. A pending submission prevents a second pending attempt. Invoice row locks serialize submission, manual settlement, verification and void. Submitted does not change invoice balance. Nexus Mark Fully Paid changes the selected payment to Verified and the invoice to Paid/zero balance. Reject preserves balance/history and makes the invoice available for a fresh submission. Verification cannot be repeated. Legacy confirmed payments become Verified during migration.

Proof is optional (matching existing Fleet behavior), max 5 MiB, JPG/PNG/WEBP/PDF determined from content. It is stored on Nexus's private local disk. Resource responses never expose its storage path. Nexus authorized users may view/download it; Fleet downloads it through an authenticated tenant/payment/proof-bound proxy. JSON base64 transport is bounded and works with the existing 20m API request limit. No public proof URL is created.

Central lists/details are live reads; Fleet invoice/payment lists/details and Nexus recorded-payment lists refresh every 30 seconds while open. No queue-based financial replica is needed. Standalone paths continue to use local records. Central invoice PDF download is not exposed; central detail and payment-proof download are supported.

Deploy Nexus before Fleet after owner-authorized merges, using updater v11. It preserves existing backups, machine/connection checks, volumes, databases, storage and signing keys. No merge or deployment is authorized by implementation alone.

Acceptance: generate finalized billing invoice, open matching Fleet tenant, Issue in Nexus, submit full balance with proof, inspect Nexus Payments row/detail, Reject and resubmit, Verify and confirm Paid in Fleet. Try another tenant/application or an actor without permission and confirm refusal. Repeat a submission key and verification to check duplicate protection. Exercise standalone PaymentTest separately.
