# Invoice replacement and subscription cards

Baseline main 87f2480. New feature branch; no merge/deployment authorized.

Allow a new invoice number from finalized billing after Cancelled, keep previous invoices and original issue dates. One non-cancelled invoice per billing enforced with parent-row lock and partial unique index. Cancel unpaid issued/overdue invoices only without pending/verified payments. Invoice detail shows relation names/numbers, removes manual Record Payment form, retains recorded payment review. Fleet Details uses responsive cards matching supplied screenshot. Implementation checkpoint; tests/CI pending.
