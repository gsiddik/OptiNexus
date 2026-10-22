# Lifecycle, invoices and notifications

Baseline main: 6e84cc5053d05eebf82510ae2616ca6601cada59 (Nexus), 5114e8e3ab9b8145e84042be02ca0f732bfca779 (Fleet), cb4c2cb71ec37dd306cef7bb54c2b1b8540afb52 (Entry).

New branches only; no merge authorized. Scope: replace void invoices while retaining paid history/downloads; restart managed temporary-password activation for tenant users on reactivation/email change; sync verified email projections by immutable subject in Fleet/Entry; queued operational mail to registered tenant admins and authorized Nexus operators; derived tenant applications; consistent display timestamps. Postmark SMTP configuration requires real credentials and verified sender domain. Never claim guaranteed inbox placement.

Implementation and verification in progress. Original Entry checkout has a pre-existing modified QA screenshot; all Entry changes use an isolated worktree. PHP installer attempted per backend AGENTS.md but exited without installing; system package tooling is being prepared. Boost is already a dev dependency.
