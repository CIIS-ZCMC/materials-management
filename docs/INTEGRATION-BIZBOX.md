# BizBox integration note (for ZCMC IT)

Status: DRAFT, 2026-11-13. Written by the MMS system developer.
Nothing in the system calls BizBox today. The "Fetch from BizBox" button is visible but disabled.
The BizBox side of every row below is **to be confirmed by IT**: we have not seen BizBox and have not guessed any BizBox field names.

## Why we need BizBox

In the PO-to-DV flow (Part 1, Step 1), Procurement either creates the PO in the MMS system or posts it in BizBox. When it is posted in BizBox, the MMS system must read it so the PO can be verified, delivered, inspected and paid without typing it twice.

## What we need from BizBox

| What we need | What we use it for | Where it goes in the MMS system | BizBox side |
|---|---|---|---|
| PO number | Identify the PO everywhere (lists, search, IAR, DV). Must be unique. | `purchase_orders.po_number` | To be confirmed by IT |
| BizBox's own PO identifier (if different from the PO number) | Find the same PO again and avoid duplicates when re-reading. | `purchase_orders.external_id` (unique together with `source = bizbox`) | To be confirmed by IT |
| Supplier (name, and an identifier or email if it exists) | Link the PO to our Suppliers list; show the supplier; supplier performance reports. | `purchase_orders.supplier_id` (matched to `suppliers`) | To be confirmed by IT |
| PO date | Shown on the PO; list filter. | `purchase_orders.po_date` | To be confirmed by IT |
| Date the PO was issued or emailed to the supplier | Starts the delivery clock: due date = this date + delivery term. | `purchase_orders.emailed_date` | To be confirmed by IT (may not exist in BizBox) |
| Delivery term (number of days) | Computes the due date, overdue and grace period. | `purchase_orders.delivery_term_days` | To be confirmed by IT |
| Schedule dates (the dates delivery is expected, for example Oct 1, Oct 15, Oct 30) | Delivery in batches; spotting a missed batch. | `po_schedules` (one row per date) | To be confirmed by IT |
| Item list: description, unit price, quantity ordered | Delivery tracking (delivered and undelivered totals), property documents, DV amounts. | `po_items` (description, unit_price, quantity_ordered) | To be confirmed by IT |
| Unit of measure and category per item, if BizBox has them | Our lines require a unit and a category. A BizBox line may not match our item catalog (`item_id` stays empty then). | `po_items.unit_id`, `po_items.category_id` | To be confirmed by IT |
| IAR checklist: the list of supporting documents required for a PO | Part 2 completeness gate (the system blocks the step if a required document is missing). The required list is not defined yet. | Not stored yet | To be confirmed by IT (does BizBox hold such a list, per PO or per supplier?) |

We do **not** need prices or totals to be calculated by BizBox: the MMS system computes line and PO totals itself.

## What the MMS system will do with it (Phase 13, not built)

- A future import creates the PO in the MMS database with `source = bizbox` and `external_id` set.
- A PO from BizBox cannot be edited in the MMS system (already built); corrections are made in BizBox and read again.
- Status changes (verified, cancelled, and so on) stay inside the MMS system.
- Which starting status an imported PO gets is decided in Phase 13.

## Questions for IT

**Access**
1. Is there an API, a database view, or only file exports (CSV or similar)? Is there a test environment we can use?
2. Can the MMS server reach BizBox over the hospital network? Any firewall or VPN rule needed?

**Authentication**
3. How does a system log in to BizBox (API key, service account, token)? Who issues and renews it?

**Read-only or read-write**
4. Do we get read-only access? The flowchart only needs the MMS system to read POs. Is any write-back to BizBox wanted or allowed?

**How POs are identified**
5. Is the PO number unique and stable, or can it repeat across years or document types? (Our sample sheet has contracts and donations with "DRF NO." forms instead of PO numbers.)
6. Does BizBox have its own internal ID besides the PO number?

**How updates are noticed**
7. If a PO is changed, cancelled or partly cancelled in BizBox after we read it, how do we find out (a "last changed" date, a change list, a notification)? Or must we read it again on a schedule?
8. How are line-level cancellations represented?

**Data**
9. Which of the fields in the table above exist in BizBox, and what are their names and formats (dates, amounts, quantities)?
10. Do item lines have a unit of measure and a category? Are quantities always whole numbers?

**Practical**
11. Who is the BizBox contact for questions, and what are the working hours or maintenance windows?
12. Any limits (requests per minute, records per call)?

## Where this lives in the code (so IT knows what to change)

- Switch: `BIZBOX_ENABLED` in `.env` (default `false`), read through `config/mms.php` (`bizbox.enabled`). Run `php artisan config:clear` after changing it. Leave it `false` until Phase 13.
- Interface: `app/Contracts/PurchaseOrderProvider.php`.
- BizBox code goes only in `app/Integrations/PurchaseOrders/BizBoxPurchaseOrderProvider.php` (today a stub that throws "BizBox is not configured").
- The shape the rest of the app expects: `app/Data/PurchaseOrderData.php` and `PurchaseOrderLineData.php`.
- The choice between the two providers: `app/Providers/AppServiceProvider.php`.