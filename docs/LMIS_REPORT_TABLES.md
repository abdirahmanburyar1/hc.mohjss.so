# LMIS Report: How Tables Work Together

## Tables involved

### 1. `facility_inventory_movements` (source of truth for movements)

Records every **received** and **issued** movement at facility level:

| Field | Purpose |
|-------|--------|
| `facility_id` | Which facility |
| `product_id` | Which product |
| `movement_type` | `facility_received` \| `facility_issued` |
| `source_type` | `transfer` \| `order` \| `dispense` \| `moh_dispense` |
| `source_id` / `source_item_id` | Link to source document |
| `facility_received_quantity` | Qty received (0 when movement_type = issued) |
| `facility_issued_quantity` | Qty issued (0 when movement_type = received) |
| `movement_date` | When the movement happened |
| `batch_number`, `expiry_date`, `uom`, `barcode` | Optional product details |

- **Received** = stock entering the facility (from transfer, order, etc.).
- **Issued** = stock leaving the facility (dispense, transfer out, etc.).

### 2. `monthly_consumption_reports` (one report per facility per month)

| Field | Purpose |
|-------|--------|
| `id` | PK |
| `facility_id` | Facility |
| `month_year` | Period, e.g. `2026-03` |
| `generated_by` | User who created the report |
| **Workflow** | `status`, `submitted_at`, `submitted_by`, `reviewed_at`, `reviewed_by`, `approved_at`, `approved_by`, `rejected_at`, `rejected_by` |

**Status flow:** Draft → Submitted → Reviewed → Approved / Rejected.

### 3. `monthly_consumption_items` (one row per product per report)

| Field | Purpose |
|-------|--------|
| `parent_id` | FK to `monthly_consumption_reports.id` |
| `product_id` | Product |
| `uom`, `batch_number`, `expiry_date` | From movements (optional) |
| `beginning_balance` | Stock at start of period |
| `received_quantity` | Sum of **received** from movements in period |
| `issued_quantity` | Sum of **issued** from movements in period |
| `other_quantity_out` | Other out (e.g. losses; can be 0 if not tracked) |
| `positive_adjustment` / `negative_adjustment` | Adjustments (can be 0) |
| `closing_balance` | beginning + received - issued - other - neg_adj + pos_adj |
| `total_closing_balance` | Same or aggregated across batches |
| `average_monthly_consumption` | AMC (calculated or from another source) |
| `months_of_stock` | MOS |
| `stockout_days` | Days out of stock |
| `quantity_in_pipeline` | On-order or in-transit |

---

## How they work together

1. **Create LMIS Report (button)**  
   - User selects **Facility** (e.g. current user’s facility) and **Period** (e.g. `2026-03`).  
   - Backend:  
     - Reads **facility_inventory_movements** for that `facility_id` and `movement_date` in that month.  
     - Groups by `product_id` and sums:  
       - `received_quantity` = SUM(`facility_received_quantity`)  
       - `issued_quantity` = SUM(`facility_issued_quantity`)  
     - Optionally gets **beginning_balance** from previous month’s `monthly_consumption_items.closing_balance` for the same facility, or from current inventory at start of month.  
     - Computes **closing_balance** = beginning + received - issued (and adjustments if any).  
   - Inserts/updates **monthly_consumption_reports** (one row: facility + month_year, status = Draft) and **monthly_consumption_items** (one row per product with the computed figures).

2. **Approval workflow**  
   - Report is created in **Draft**.  
   - User **Submits** → status = Submitted.  
   - Reviewer **Reviews** → status = Reviewed.  
   - Approver **Approves** or **Rejects** → status = Approved / Rejected (with timestamps and user IDs).

3. **Facility LMIS Report page**  
   - Shows the report for the selected period from **monthly_consumption_reports** + **monthly_consumption_items**.  
   - If no report exists, shows **Create LMIS Report**; after creation, shows the table and workflow actions (Submit / Review / Approve / Reject) according to status and permissions.

---

## Mapping: movements → report items

| Movement data | Report item field |
|---------------|-------------------|
| SUM(`facility_received_quantity`) in period | `received_quantity` |
| SUM(`facility_issued_quantity`) in period | `issued_quantity` |
| Previous month `closing_balance` or opening stock | `beginning_balance` |
| beginning + received - issued ± adjustments | `closing_balance` |
| Product from movements | `product_id` (and optional uom, batch_number, expiry_date) |

Adjustments and **other_quantity_out** are not in `facility_inventory_movements`; they can be left 0 or filled later from another source (e.g. manual entry or adjustments table).
