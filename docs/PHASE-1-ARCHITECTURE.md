# SchoolPOS Online — Phase 1 Architecture Blueprint

**Status:** Architecture baseline for implementation phases 2–5  
**Repository:** `HelloObinna/SchoolPOS`  
**Deployment target:** Internet-facing WordPress on VPS, cloud hosting, or cPanel with HTTPS

## 1. Scope and architectural boundary

SchoolPOS is a cloud-operated school supply-chain and POS application with six roles:

1. WordPress Super Admin (`administrator`)
2. School Admin (`school_admin`)
3. School Secretary (`school_secretary`)
4. Warehouse Manager (`warehouse_manager`)
5. School Bursar (`school_bursar`)
6. Store Seller / Storekeeper (`store_seller`)

The custom plugin `school-inventory-pos-online` owns custom MySQL tables, roles and capabilities, inventory state transitions, voucher issuance and redemption, REST/AJAX authorization, rate limiting, alerts, and immutable audit data. The custom theme `school-pos-online-theme` owns only the responsive application shell and 80mm print presentation. WordPress posts and `wp_postmeta` must not store financial records, stock balances, stock movements, voucher state, or POS line items.

All monetary values are Nigerian Naira and are stored as integer kobo in database columns (`BIGINT`, never floating point). Presentation uses `₦` with comma separators and exactly two decimal places, for example `₦15,500.00`.

## 2. Data model

All tables use the WordPress database prefix and InnoDB. The installer must obtain names through `$wpdb->prefix`, use `dbDelta()` for creation, and add an explicit schema version option for migrations. Foreign keys are not required because WordPress installations vary, but every relationship must have indexed ID columns and application-level validation.

### 2.1 `school_pos_products`

Master SKU and packaging definition.

| Column | Type / invariant |
|---|---|
| `product_id` | `BIGINT UNSIGNED` primary key |
| `sku` | `VARCHAR(64)` unique, immutable after publication |
| `name` | `VARCHAR(190)` |
| `category` | enum-like `VARCHAR(40)`: `TEXTBOOKS`, `STATIONERIES`, `UNIFORMS`, `SCIENCE_LAB`, `GENERAL` |
| `bulk_unit_label` | `VARCHAR(32)`, such as Pack, Box, Carton, Dozen |
| `base_unit_label` | `VARCHAR(32)`, such as Piece, Book, Pen, Sheet |
| `units_per_pack` | `INT UNSIGNED`, minimum 1 |
| `pack_price_kobo` | `BIGINT UNSIGNED`, nullable while not sellable |
| `piece_price_kobo` | `BIGINT UNSIGNED`, nullable while not sellable |
| `low_stock_threshold_base_units` | `INT UNSIGNED`, minimum 0 |
| `is_active` | `TINYINT(1)` |
| `created_by`, `updated_by` | `BIGINT UNSIGNED` |
| `created_at`, `updated_at` | UTC `DATETIME` |

Prices are snapshots for new sales; historical sales retain their own line prices. A product cannot be activated without valid packaging and at least one valid price.

### 2.2 `school_pos_intakes`

Secretary-entered procurement record and warehouse handover state.

| Column | Type / invariant |
|---|---|
| `intake_id` | `BIGINT UNSIGNED` primary key |
| `intake_code` | unique human-readable reference |
| `product_id` | indexed product reference |
| `supplier_name` | `VARCHAR(190)` |
| `waybill_ref` | `VARCHAR(100)` |
| `packaging_type` | `VARCHAR(32)` |
| `units_per_pack_snapshot` | `INT UNSIGNED` |
| `quantity_packs` | `INT UNSIGNED` |
| `quantity_base_units` | `INT UNSIGNED`, computed at submission |
| `unit_cost_kobo` | `BIGINT UNSIGNED` |
| `status` | `PENDING_WAREHOUSE_HANDOVER`, `ACCEPTED`, `DISCREPANCY`, `CANCELLED` |
| `submitted_by`, `verified_by` | `BIGINT UNSIGNED` nullable |
| `submitted_at`, `verified_at` | UTC `DATETIME` nullable |
| `discrepancy_note` | `TEXT` nullable |
| `created_at`, `updated_at` | UTC `DATETIME` |

The intake stores packaging and quantity snapshots so later product edits cannot rewrite procurement history.

### 2.3 `school_pos_stock_balances`

Current locked balance per product and location.

| Column | Type / invariant |
|---|---|
| `balance_id` | `BIGINT UNSIGNED` primary key |
| `location` | `WAREHOUSE` or `STOREFRONT` |
| `product_id` | indexed product reference |
| `quantity_base_units` | `INT UNSIGNED`, never negative |
| `updated_at` | UTC `DATETIME` |

Unique key: `(location, product_id)`. Every mutation begins a transaction, selects this row `FOR UPDATE`, validates the resulting balance, updates the row, and appends the corresponding ledger entry before commit.

### 2.4 `school_pos_handovers`

Workflow records for secretary-to-warehouse and warehouse-to-store custody changes.

| Column | Type / invariant |
|---|---|
| `handover_id` | `BIGINT UNSIGNED` primary key |
| `handover_code` | unique reference |
| `handover_type` | `SECRETARY_WAREHOUSE` or `WAREHOUSE_STOREFRONT` |
| `source_record_id` | indexed intake or dispatch reference |
| `product_id` | indexed product reference |
| `quantity_base_units` | `INT UNSIGNED` |
| `status` | `PENDING`, `ACCEPTED`, `DISCREPANCY`, `CANCELLED` |
| `initiated_by`, `accepted_by` | `BIGINT UNSIGNED` nullable |
| `discrepancy_note` | `TEXT` nullable |
| timestamps | UTC `DATETIME` |

Acceptance is idempotent by status and record ID. A discrepancy never silently changes stock; it creates an audit event and requires an authorized resolution workflow.

### 2.5 `school_pos_stock_ledger`

Append-only inventory journal.

| Column | Type / invariant |
|---|---|
| `ledger_id` | `BIGINT UNSIGNED` primary key |
| `location` | `WAREHOUSE` or `STOREFRONT` |
| `product_id` | indexed product reference |
| `action_type` | controlled value: intake, handover, sale, adjustment, reversal |
| `qty_change_base_units` | signed `INT` |
| `balance_before`, `balance_after` | `INT UNSIGNED` snapshots |
| `performed_by_user_id` | indexed WordPress user ID |
| `ip_address` | `VARCHAR(45)` |
| `user_agent` | bounded `TEXT` or `VARCHAR(512)` |
| `reference_code` | indexed business reference |
| `notes` | `TEXT` |
| `created_at` | UTC `DATETIME` |

No update or delete endpoint is permitted. Corrections use a compensating ledger row referencing the original record. The application must verify `balance_before + qty_change_base_units = balance_after` before insert.

### 2.6 `school_pos_vouchers`

Bursar payment and single-use redemption state.

| Column | Type / invariant |
|---|---|
| `voucher_id` | `BIGINT UNSIGNED` primary key |
| `voucher_code` | unique, opaque, indexed |
| `code_digest` | unique keyed digest for lookup without relying on plaintext |
| `sequence_no` | unique sequential counter |
| `student_full_name` | `VARCHAR(190)` |
| `admission_number` | indexed `VARCHAR(100)` |
| `class_grade` | `VARCHAR(100)` |
| `amount_paid_kobo` | `BIGINT UNSIGNED` |
| `payment_method` | `CASH`, `BANK_TRANSFER`, or `TELLER` |
| `teller_ref_no` | `VARCHAR(100)` nullable |
| `status` | `ISSUED`, `REDEEMED`, `VOIDED` |
| `issued_by`, `redeemed_by` | `BIGINT UNSIGNED` nullable |
| `redeemed_sale_id` | indexed sale reference nullable |
| `issued_at`, `redeemed_at` | UTC `DATETIME` nullable |
| `issued_ip`, `issued_user_agent` | audit metadata |
| `redeemed_ip`, `redeemed_user_agent` | audit metadata |

Voucher codes are generated with `random_bytes()` and encoded using a restricted uppercase alphabet, combined with the year, a sequence segment, and a database uniqueness check. The sequence is not treated as a security control. The plaintext code is printed for the student; the digest is used for indexed comparison where practical.

### 2.7 `school_pos_sales` and `school_pos_sale_lines`

POS transaction header and immutable item snapshots.

`school_pos_sales` fields: `sale_id`, unique `receipt_no`, `voucher_id`, `voucher_code_snapshot`, student identity snapshots, `seller_user_id`, `subtotal_kobo`, `voucher_applied_kobo`, `total_kobo`, `status` (`COMPLETED`, `VOIDED`), IP/user-agent, and UTC timestamps.

`school_pos_sale_lines` fields: `line_id`, `sale_id`, `product_id`, `product_name_snapshot`, `unit_type` (`PACK` or `PIECE`), `quantity`, `base_units_consumed`, `unit_price_kobo`, `line_total_kobo`, `pack_size_snapshot`, and timestamps.

The sale header and all lines are immutable after completion except through a controlled void/reversal operation. The sum of line totals must equal the sale subtotal, and `voucher_applied_kobo` must equal the completed sale total for the current single-voucher flow.

### 2.8 `school_pos_audit_events`

Append-only security and operational audit trail for actions not fully represented by the stock ledger.

Required fields: `event_id`, `event_type`, `actor_user_id`, `entity_type`, `entity_id`, `reference_code`, `ip_address`, `user_agent`, `metadata_json`, and UTC `created_at`.

Events include login-sensitive POS actions, voucher lookup failures, voucher issuance, voucher redemption, handover acceptance, discrepancy flagging/resolution, stock adjustment, role/capability changes, and CSV export.

## 3. Transaction and concurrency rules

All stock, voucher, and sale mutations use `$wpdb` transactions where supported:

1. Validate capability, nonce or authenticated REST permission, input shape, and business status.
2. Start a transaction.
3. Lock all affected balance rows and the voucher row with `SELECT ... FOR UPDATE` in deterministic order.
4. Re-check status and available quantities inside the transaction.
5. Insert immutable snapshots and ledger/audit rows.
6. Update balances and voucher/sale status.
7. Commit; on any failure, roll back and expose an explicit error.

Warehouse transfers decrement `WAREHOUSE` and create a pending storefront handover; store acceptance then increments `STOREFRONT`. POS completion locks the voucher and storefront balances, rejects `REDEEMED`/`VOIDED` vouchers, rejects totals above the voucher amount, decrements base units, marks the voucher `REDEEMED`, and commits the sale atomically. No request may return success before commit.

## 4. Internet and cloud security baseline

- Force HTTPS at the web server and WordPress layer; reject or redirect plaintext requests.
- Send `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and `Referrer-Policy: strict-origin-when-cross-origin` on POS, bursar, warehouse, secretary, store, and admin application routes.
- Set `DONOTCACHEPAGE` before rendering protected pages and send `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`, with equivalent proxy/CDN directives.
- Use WordPress capability checks, nonces for browser mutations, authenticated REST permission callbacks, strict input validation, prepared SQL, and escaped output.
- Do not trust forwarded IP headers unless the deployment explicitly configures trusted reverse proxies. Normalize and bound IP and user-agent values before storage.
- Rate-limit voucher lookup by authenticated user and client IP using a transient bucket: maximum 10 attempts per minute per key, with generic failure responses that do not reveal whether a code exists.
- Log lookup failures and privileged mutations in `school_pos_audit_events`; never log full payment secrets or unnecessary personal data.
- Restrict CSV exports to authorized roles, stream them without cache, and audit each export.
- Keep secrets, salts, SMTP credentials, and database credentials in environment/configuration outside committed source.

## 5. RBAC and SQL visibility isolation

Capabilities are assigned at role creation and checked at every route, not only hidden in navigation.

| Role | Capabilities | Allowed records | Explicitly denied |
|---|---|---|---|
| Administrator | `manage_options`, `create_users`, `edit_users`, `promote_users`, `assign_school_roles`, `configure_school_pos` | All configuration, users, and records | None beyond normal platform safeguards |
| School Admin | `oversee_all_transactions`, `view_secretary_records`, `view_warehouse_records`, `view_store_sales`, `view_bursar_vouchers`, `view_master_audit_ledger`, `resolve_stock_discrepancies` | Cross-chain operational records, audit data, discrepancy resolution | Assigning roles, changing user privileges, operating bursar/POS workflows |
| School Secretary | `log_stock_intake`, `view_own_intakes` | Own intake submissions and their statuses | Other intakes, balances, transfers, sales, vouchers, audit ledger |
| Warehouse Manager | `receive_warehouse_handover`, `manage_warehouse_stock`, `initiate_store_handover`, `view_warehouse_ledger` | Incoming secretary handovers, warehouse balances/ledger, outgoing transfer records | Store sales/stock after handover, voucher and bursar records, secretary records not needed for handover |
| School Bursar | `record_bursar_payment`, `print_bursar_voucher`, `view_bursar_vouchers` | Bursar payments and voucher status/print data | All stock, transfers, sales operations, warehouse and storefront balances |
| Store Seller | `receive_store_handover`, `manage_storefront_stock`, `operate_pos_checkout`, `remit_bursar_voucher`, `view_own_store_sales` | Storefront stock, assigned handovers, own sales/remittances | Warehouse balances/costs, secretary intakes, other sellers’ sales, bursar administration |

SQL queries must include ownership predicates where applicable, for example `WHERE submitted_by = current_user_id` for secretary intake reads and `WHERE seller_user_id = current_user_id` for seller sales. A capability check alone is not sufficient isolation.

## 6. Voucher and thermal print contract

The bursar print view must show school branding, voucher code, timestamp, student name, admission number, class, amount, payment method, and bursar name. The POS receipt must show branding, receipt number, timestamp, remitted voucher code, student snapshots, seller name, itemized pack/piece lines, grand total, voucher applied, and verification footer.

The theme must use local versioned assets and print CSS with:

```css
@page { size: 80mm auto; margin: 0; }
```

Content width is `72mm`; print output must hide application navigation and avoid third-party CDN dependencies.

## 7. Alerts, monitoring, and operations

The configured School Admin recipients receive `wp_mail()` notifications for discrepancy flags and products crossing below their configured base-unit threshold. Alert dispatch must not roll back a successful business transaction; failures are logged as operational audit events and can be retried. Low-stock alerts should be transition-based or deduplicated to avoid mail storms.

Production monitoring should track failed transactions, rate-limit hits, mail failures, database errors, and unexpected negative-balance attempts. Backups must include custom tables and be tested for restoration without exposing exported student or payment data.

## 8. Phase handoffs

- **Phase 2:** Implement the plugin bootstrap, `dbDelta()` installer/migrations, roles/capabilities, headers, cache bypass, rate limiter, and administrator role/configuration screens based on this schema and matrix.
- **Phase 3:** Implement intake submission, warehouse acceptance/discrepancy handling, warehouse-to-store dispatch, store acceptance, locked balance mutations, ledger entries, and alerts.
- **Phase 4:** Implement CSPRNG vouchers, rate-limited lookup, atomic POS remittance, redemption state, receipt/voucher print views, and Nigerian Naira formatting.
- **Phase 5:** Implement the responsive theme, School Admin oversight views, audit CSV export, operational dashboards, and production hardening checks.

No phase may bypass the custom-table boundary, SQL-level isolation, transaction rules, or append-only audit requirements defined here.
