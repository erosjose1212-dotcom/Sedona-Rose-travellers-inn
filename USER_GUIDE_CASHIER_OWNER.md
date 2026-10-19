# SEDONA COURT TRAVELLERS INN
## Complete system tutorial

A practical operating manual for cashiers, front desk staff, RA/admin, owners, and kitchen staff.

**Edition:** 14 October 2026. **System reviewed:** Sedona Court 3.0, application commit fde56e2. This guide describes the current shared-drawer configuration. Older installations and restricted accounts may show fewer controls.

**Where to start:** cashiers should read Lessons 1-4 and 6-18. RA/admin should also read Lessons 5 and 20-21. Owners should read Lessons 21-29. Kitchen staff should read Lesson 19. Keep Lesson 31's checklists beside the work station.

**The most important rule:** sign in as yourself, confirm the room and guest, record money only when actually received or returned, and check the saved result before retrying. One continuous drawer is shared by authorized cashiers. Sign out at the end of duty; the drawer stays open.

Use the system address supplied by the property operator. Passwords and real guest personal details are excluded. Practice examples belong in a separate training installation.

## 1 Sign in, roles, and navigation

### Sign in and out

1. Connect to the property's network and open its system address in your browser.
2. Enter your assigned email and password, then sign in.
3. Check the displayed account name before recording transactions.
4. Choose a work area in the sidebar. On a small screen, open the menu button first.
5. When leaving the station, open the account menu and choose **Logout**.

Signing out does not check out guests or close the shared drawer. The next cashier signs in using their own account. Avoid sharing one person's login: each action needs the correct operator identity.

Repeated failed logins can temporarily block another attempt. A deactivated account cannot sign in. Ask the owner to reset a forgotten password or investigate account access.

### Who does what

| Role | Main work | Important limits |
| --- | --- | --- |
| Cashier / front desk | Check-in, bills, orders, deposits, checkout, stock | Cannot edit check-in time or cancel stays; financial exceptions may need review |
| RA / admin | Time corrections, eligible stay cancellation, operational and financial review | Account changes and accounting setup edits are owner functions |
| Owner | Property reports, financial review, prices, accounts, accounting setup | RA time correction and stay cancellation require the admin role |
| Kitchen | Preparation queue and order progress | No guest payments, rates, or staff account administration |
| Customer display | Lobby display | Display access is separate from operating access |

An owner with an additional admin role can use admin-only actions. Menu visibility follows actual account permissions. A missing link or a 403 page can mean your account lacks access.

### Words and navigation

| Word | Meaning |
| --- | --- |
| Folio / SCTI | One stay's bill / its reference number |
| Pax | Number of guests |
| Tier / package | Booked length: 3h, 4h, 6h, 12h, or 24h |
| Extend | Add paid hours or convert an eligible 12h stay to 24h |
| X Order / XOrder | Cancel an eligible order |
| POS | Food, drink, and amenity sales |
| Opening float | Cash already in the register before collections |
| Tendered / change | Cash handed over / cash returned |
| Applied | An approved action has changed the record |
| Reconciliation | Comparing entries with actual money/statements |

Cashiers mainly use **Front Desk**, **Daily SCTI Register**, **Reservations**, **POS**, **Shifts & Drawer**, **Stock**, **Approvals & Tasks**, and **Transactions & Audit**.

Admin/owner also use **Reports & Audits**, **Ledger**, **Daily Finance**, **Executive P&L**, **Expenses & Petty Cash**, **Shift Reconciliations**, **Loss Slips**, **Discounts**, **Login History**, **Master Pricing Editor**, and **Staff Accounts & RBAC**. **Check-in Time Corrections** is admin-only.

## 2 Front Desk, room actions, and alarms

**Go to:** Front Desk.

Available rooms are arranged by room number. **Occupied rooms are arranged by earliest check-in first**, matching the handwritten arrival list. A corrected check-in time can change a stay's position.

Read room number/type, guest, pax, package, check-in, and expected checkout before acting. Staff quarters are separate from guest stays and excluded from guest occupancy totals.

| Control | Use |
| --- | --- |
| Check In | Start a guest stay in an available room |
| Checkout | Review the bill and complete settlement |
| Extend | Add hours or convert an eligible 12h stay to 24h |
| Discount | Apply, change, or clear a fixed discount |
| Transfer | Move an active stay to an available room |
| Add On | Add several items to this stay |
| X Order | Open add-ons/history to cancel an eligible order |
| Force | Request review of an unpaid departure/write-off |

### Alarm and checkout grace

For a room due at **1:28 AM**:

| Stage | Time | Meaning |
| --- | --- | --- |
| First alert | 1:13 AM | 15 minutes before the due time |
| Due time | 1:28 AM | Booked stay has ended |
| Grace limit | 1:43 AM | Maximum 15-minute checkout grace ends |

Excess billing begins only after grace is exceeded. Exactly 15 minutes late remains within grace. At 15 minutes and 1 second, the first excess hour applies. Billing subtracts grace before rounding billable excess time into hours.

**Example:** Classic 3h, check-in 04:04 AM, checkout 09:14 AM. Stay is 5h 10m; excess is 2h 10m. After 15m grace, billable excess is 1h 55m, rounded to 2 hours. Room charge is ₱395 + ₱260 = **₱655**, before add-ons/discount.

Enable **Sound ON / OFF** for alerts. **Stop Alarm Sound** silences the current alarm; it does not extend time, remove charges, or check out the guest. Keep server and workstation clocks correct.

## 3 Start and use the shared cashier drawer

**Go to:** Shifts & Drawer.

The normal configuration uses **one continuous shared drawer**. It remains open when operators change. Each payment records the cashier who handled it.

### If no drawer is open

1. Count cash physically in the register.
2. Enter **Starting cash in drawer**, including the actual opening float.
3. Enter 0 if it is empty.
4. Click **Start cashier shift**.
5. Confirm the open drawer.

**Example:** two ₱500 notes and five ₱100 notes total ₱1,500. Enter 1500. Opening float is existing cash, not a guest payment or sale.

### If the drawer is already open

1. Sign in using your own account.
2. Review the shared drawer and incident notes.
3. Continue authorized collections and expenses.
4. Log out at the end of duty.

There is no routine Close Shift/Accept Handover step in continuous mode. Coordinate physical counts with the supervisor under property procedure.

A frozen drawer blocks payments. Ask admin/owner to investigate; do not start another drawer to bypass it. Checkout also offers a start-drawer form when none is open and your account has access.

If your installation shows individual handover/close controls, its configuration differs from this guide. Ask the operator to check continuous mode.

## 4 Check in a walk-in guest

**Go to:** Front Desk, available room, **Check In**.

1. Confirm RA has declared the guest and the room is ready.
2. Confirm room number and type.
3. Enter guest name and **No. of Guest (Pax)**, supported range 1-8.
4. Select **Stay Duration Tier**. Default is **3hrs**; change it only for another booked package.
5. Check **Standard Room Rate**.
6. If using Senior/PWD at check-in, choose the option and enter the required ID reference. DC can be applied using the room's Discount action.
7. Click **Process Check In** once.
8. Read success and locate the occupied room.
9. Verify check-in, expected checkout, package, and SCTI.

Available new packages are 3h, 4h, 6h, 12h, and 24h. **Midnight promo is disabled.** Do not substitute another package to represent a paused promo.

Check-in creates the stay/bill, marks the room occupied, and allocates a daily SCTI. **It does not collect payment.** Record any actual deposit separately.

Cashier check-in uses server time. Cashiers cannot backdate it. For an earlier RA declaration, use Lesson 5.

If a room became unavailable, refresh and check it again; another operator may have acted first. Inspect the occupied list before repeating a slow submission.

## 5 RA/admin: correct a late-entered check-in

**Use when:** RA declared the guest earlier, but the cashier entered the stay late.

**Who:** admin role only. Cashiers and owner-only accounts cannot edit this time.

### Cashier's part

1. Record the check-in normally if the guest is not yet in the system.
2. Give RA/admin the room, guest, SCTI, and actual declaration time.
3. After correction, refresh Front Desk and checkout.
4. Verify updated time and charges.

### RA/admin's part

1. Open **Check-in Time Corrections**.
2. Find the active room and guest; verify SCTI.
3. Enter the actual declaration date/time.
4. Enter **Reason for correction**.
5. Read **New checkout**.
6. Click **Save corrected time**.
7. Verify success and retained history.

**Example:** recorded at 8:20 PM for 3h, but declared at 8:00 PM. Correction to 8:00 PM changes due checkout to 11:00 PM. Billing, alarms, stay duration, and reports use the corrected time.

Only active guest stays can be corrected. Future timestamps, conflicting occupancy, and stale records are rejected. Previous time, corrected time, reason, and admin remain audited.

SCTI receipt date is unchanged. Correction does not renumber the stay or restart cancellation windows.

## 6 Create, check in, or cancel a reservation

**Go to:** Reservations.

### Create

1. Open the new-booking control.
2. Choose room and confirm type.
3. Enter guest, requested contact details, and pax.
4. Choose package.
5. Enter **Arrival Date & Time** and useful notes.
6. Review and save.
7. Confirm it appears under **Scheduled (Active)**.

Overlapping scheduled bookings and reservations overlapping an active guest's expected occupancy are rejected. A future slot after due checkout may be allowed; staff must still confirm physical readiness on arrival.

Staff quarters/maintenance rooms are not guest booking choices. Midnight promo remains paused.

### Arrival

1. Find the correct scheduled booking.
2. Verify room availability/readiness.
3. Click **Check-In** and confirm.
4. Verify occupied stay and new SCTI.

Actual check-in is stamped when recorded, not automatically set to planned arrival. Ask RA/admin to correct an earlier declaration if needed.

### Cancel before check-in

Find the scheduled reservation, click **Cancel**, and confirm the result under Cancelled. Cancelling a reservation differs from cancelling an active stay.

Use Scheduled, Checked-In, Cancelled, and All filters and pagination to locate records. Filters remain selected across pages.

## 7 Extend hours or convert 12h to 24h

**Go to:** Front Desk, occupied room, **Extend**.

### Add paid hours

1. Choose **Add extra hours at ₱130 per hour**.
2. Select agreed hours.
3. Review the added charge/timing.
4. Click **Save Extension** once.
5. Verify the new due time and bill.

A paid extension moves the due time. Checkout grace then applies after that new time. Extension records a charge; collect money using normal deposit/checkout.

### Convert an active 12h stay

1. Choose **Convert 12-hour stay to 24-hour package** inside Extend.
2. Review the conversion and new checkout.
3. Enter the guest's request in **Reason**.
4. Click **Convert to 24 Hours**.
5. Verify 24h package, rate, and discount.

Conversion uses original/corrected check-in, not the moment you press Convert. An 8:00 AM 12h check-in converted to 24h becomes due at 8:00 AM next day.

It replaces the package charge and clears separate hourly extensions from the old package. Orders and deposits remain attached. Fixed discounts are recalculated for 24h.

This is a package conversion, not clock-format selection. The choice is for eligible active 12h stays. Settled stays cannot be extended/converted.

## 8 Transfer a guest to another room

**Go to:** occupied room, **Transfer**.

1. Confirm destination is ready and available.
2. Verify guest and source room.
3. Choose **Select Target Available Room**.
4. Review destination type/details.
5. Click **Complete Transfer**.
6. Verify the destination stay and released source room.
7. Review price, discount, due time, and orders.

Same folio/SCTI follows the guest. The package is re-priced for the destination type, and orders remain with the stay.

**Example:** Classic 3h transferred to Premium uses **₱495**, not ₱395. DC changes from ₱40 to ₱50.

Booked tier and extra hours are retained. Fixed discount is recalculated; an approved custom amount is preserved under its stored override. Do not invent a separate difference charge.

Occupied, maintenance, or staff-quarters destinations are blocked. Settled stays cannot transfer.

## 9 Apply the correct room discount

**Go to:** occupied room, **Discount**.

1. Confirm guest, room type, and tier.
2. Choose **Discount Classification**: DC, Senior, PWD, or **No Discount / Clear Existing**.
3. Enter **Card / ID Reference #**. Senior/PWD requires an ID.
4. Check the displayed deduction.
5. Click **Apply Fixed Discount**.
6. Verify the saved bill.

Cashiers apply/switch/clear normal fixed discounts immediately without admin/owner approval. **PWD uses the Senior schedule.**

Deduction depends on **room type AND package**. It is the configured peso deduction, not an arbitrary percentage. See Lesson 33. Four-hour deductions are ₱0; do not invent another amount.

### Protected custom changes

An approved custom discount or rate override is protected. Changes/replacement require **Approvals & Tasks** with reason/reference. A disabled classification field can indicate an approved custom override.

Owner's own room-rate/custom-discount request is auto-approved but still needs **Apply approved action**. Cashier exception requests need review. See Lesson 21.

Master schedule changes do not rewrite existing snapshots. After transfer/conversion, verify the new fixed deduction.

## 10 Add items and view Previous Orders

**Go to:** occupied room, **Add On**. **X Order** opens the same panel.

1. Verify room, guest, SCTI, and status at the top.
2. Expand **PREVIOUS ORDERS** to read existing items/quantities/prices/status.
3. In ADD-ONS, choose an item and quantity.
4. Click **+ Add another item** for each extra line.
5. Remove unwanted lines before submission.
6. Check combined total and any kitchen notes.
7. Click **Submit add-ons once**.
8. Confirm the new order/history and add-ons total.

History belongs to this stay, not every former guest of that room. Transfers preserve it. Submit multiple items together; check history before retrying a stalled submission.

Kitchen items enter preparation. Non-kitchen items are delivered immediately. Food can be ordered all day when available and sufficiently stocked, including ingredients.

Admin/owner may use **Correct order** to reduce an eligible mistaken quantity with reason. Increasing quantities requires a new order. Corrections preserve history and return eligible stock.

### Common amenities

Choose the matching Services item and quantity in the add-ons table. Original agreed charges are:

| Item | Price |
| --- | --- |
| Extra person | ₱150 |
| Extra single bed / mattress | ₱250 |
| Complete extra bed set | ₱500 |
| Bed sheet | ₱100 |
| Pillow | ₱200 |
| Pillow case | ₱100 |
| Blanket | ₱100 |
| Towel | ₱100 |

Confirm the current displayed catalog price before saving. Use Extend/automatic overtime for extra stay hours; avoid adding a second excess-hour service for the same period.

## 11 XOrder: cancel an eligible order

**XOrder cancels an order, not a stay.**

**Go to:** occupied room, **X Order**, expand **PREVIOUS ORDERS**.

1. Verify the specific order and guest.
2. Check it is unpaid and unprepared.
3. Check it is within 15 minutes of order creation.
4. Click **Cancel order**; confirm if prompted.
5. Verify cancelled status and removed charges.
6. Check the updated add-ons total.

No mandatory "Reason for cancelling order" field is shown for this cashier flow. Operator/time remain audited. It cancels the order and its lines.

| Block | Correct next step |
| --- | --- |
| More than 15 minutes old | Ask admin/owner to review a supported correction |
| Preparing, ready, or delivered | Coordinate with kitchen/admin; it is no longer untouched |
| Paid walk-in sale | Use reviewed refund/correction workflow |
| Settled guest stay | Refer for financial review |

For part of an order, admin/owner may use the eligible quantity-correction action. Alternatively, cancel a still-eligible untouched order and submit the correct one.

Cancellation removes charges and returns unused tracked stock once, including eligible recipe ingredients.

The order's **15-minute creation window** is separate from the guest's **15-minute checkout grace after due time**.

## 12 POS: room charges and paid walk-ins

**Go to:** POS.

### Room order

1. Add items to cart and set quantities.
2. Select **Charge to Guest Room Folio**.
3. Choose occupied room and verify guest.
4. Enter **Kitchen / Room Notes** if needed.
5. Review and click **Place Order & Dispatch Ticket**.
6. Verify order and guest history.

Room charging does not collect a separate payment. Selection identifies a specific stay: if another guest took the room while your screen was open, stale selection is rejected. Refresh before charging.

### Paid walk-in

1. Confirm usable shared drawer.
2. Select items/quantities and paid walk-in option.
3. Choose Cash or GCash.
4. Cash: enter actual tendered amount and verify change.
5. GCash: verify receipt and enter real reference.
6. Review total, submit once, and confirm sale/payment.

Walk-in POS accepts cash or GCash. Guest checkout separately supports split payment. Do not disguise a paid walk-in sale as a room charge.

Kitchen-containing orders enter preparation. Non-kitchen-only orders are delivered immediately. Paid sales cannot use cashier XOrder; use the reviewed POS refund workflow when required.

For an authorized staff meal, select the room explicitly marked **Permanent Employee Quarters (Staff Order)**. Confirm that staff destination instead of using a paying guest's room. Staff records are excluded from guest stay/count totals.

## 13 Deposits and unused-money refunds

**Go to:** guest's Checkout page.

### Receive actual deposit

1. Verify guest/SCTI.
2. Use **Record deposit received**.
3. Enter money actually received and Cash/GCash.
4. For GCash, verify money and enter reference.
5. Submit once.
6. Confirm deposit/payment history and print slip if needed.

An open usable drawer is required. A preview field does not receive money. Never enter a fictitious deposit to reduce a bill.

### Apply it

Review recorded deposit and apply-deposit control. Applied deposit reduces remaining due. If kept separate, it remains held until handled by the supported refund/application workflow.

**Example:** ₱655 bill less recorded/applied ₱200 deposit leaves **₱455** to collect.

### Return refundable balance

Use the supported deposit-refund action or **Deposit refund** request in Approvals & Tasks. Verify amount/method/reason; obtain review and apply when the return actually occurs.

Verify recorded refund/remaining balance; print refund summary if required. Refund is a separate negative movement. Approval alone does not hand money to the guest.

## 14 Complete checkout without overcharging

**Go to:** occupied room, **Checkout**.

### Review

1. Verify guest, room, SCTI, check-in, package, and checkout time.
2. Check base rate, paid extensions, and grace-adjusted excess.
3. Review add-ons, cancellations, and corrections.
4. Check discount and required reference.
5. Review deposit/application.
6. Confirm remaining balance with the guest.

The live preview updates with time. Refresh after RA corrections or another operator's orders. Final settlement validates saved values.

Cashiers cannot edit check-in time or freely override protected rates/custom discounts through a preview. Complete the authorized correction first.

### Collect and settle

1. Confirm drawer is ready.
2. Select cash, GCash, or supported split payment.
3. Enter actual cash tendered and check change.
4. Enter actual GCash component/reference when used.
5. Verify combined payment covers balance.
6. Click **Complete Official Check-Out** once.
7. Read success and verify settled record/payment/room release.

**Example:** Classic 3h lasting 5h 10m, no other charges, total ₱655. ₱1,000 cash tendered gives **₱345 change**.

GCash cannot exceed balance. Cash may exceed it because change is returned. Required GCash/statutory references must be entered.

A printed draft or saved preview is not payment confirmation. Coordinate key return and physical room cleaning separately. Force checkout is not the normal paid settlement method.

## 15 Statements, receipts, gate passes, and reprints

| Document | Purpose |
| --- | --- |
| Statement / Billing | Review guest charges and balance |
| Settlement receipt | Show completed settlement |
| Deposit slip | Document recorded deposit |
| Deposit refund summary | Review recorded returns/balance |
| Exit Gate Pass | Support departure after settlement |
| Order slip | Review room items |
| Loss slip | Document approved unpaid loss |
| Configured invoice | Separately numbered approved accounting document |

Heading is **SEDONA COURT TRAVELLERS INN**; standalone LODGING heading is removed. **Only Statement / Billing includes the supplied Sedona logo and Facebook QR.**

Active receipts are draft/payment-pending. Gate pass follows required settlement. Do not issue a draft as proof money was received.

### Print or copy

1. Open the document from checkout success links or Ledger.
2. Verify guest, SCTI, amount, and status.
3. Use print-request/copy action where shown.
4. Select correct printer/paper/scale in browser print dialog.
5. Check physical output is complete/readable.

Several slips use 80mm receipt paper. Print requests are logged; verify actual output. Reprints retain original SCTI. Printer failure does not justify collecting again.

## 16 Stock: restock, exact counts, and recounts

**Go to:** Stock. Changes apply **immediately, without approval**.

### One item

1. Find correct item/read recorded count.
2. Count usable stock, including delivery.
3. Click **+ Adjust / Restock**.
4. Enter **New Exact Physical Count** as final on-hand quantity.
5. Select **Adjustment Reason**; add notes/supplier reference.
6. Click **Save Stock Adjustment**.
7. Verify quantity and movement history.

**Example:** stock 50 plus delivery 10: enter **60**, not 10. This sets the final count; it does not add the entered amount.

Use restock/count correction/damage-spoilage/return reason matching reality. Do not falsify quantities to enable ordering.

### Many items

1. Click **Cashier Shift Recount**.
2. Enter final quantities for counted items.
3. Leave untouched items blank.
4. Enter 0 explicitly for zero stock.
5. Add notes; click **Submit Shift Recount**.
6. Confirm saved counts.

Stock can be updated on a closed financial day. **Export Movement CSV** downloads history; it is not a stock-import tool.

Food is available all day but needs an active/available catalog entry, sufficient own stock, and recipe ingredients. For breakfast dishes, check **Egg (Fried/Boiled)** as well as dish quantity.

A low-stock warning means at/below reorder threshold. Retired items cannot be sold/restocked.

## 17 Transactions and audit history

**Go to:** Transactions & Audit.

1. Search SCTI/order/GCash reference or date range.
2. Choose From, To, Kind, and Shift filters as needed.
3. Click **Filter**.
4. Review timestamp, amount, source, operator, and drawer.
5. Use **Audit history** for actions/corrections.

Cashiers normally see their own financial entries; admin/owner review property-wide activity. Shared-drawer payments still identify the acting cashier.

Positive entries usually indicate money received; refunds/cash leaving are separate negative movements. Collections are not the same as room revenue.

**Recorded; provider verification pending** means recorded but not yet matched to a provider statement. Do not collect again.

After a timeout, check history before retrying. Give the supervisor SCTI/order, time, amount, and error if the outcome is unclear.

## 18 Drawer expenses and property cash transfers

### Money already spent

**Go to:** Shifts & Drawer, **Log Shift Expense (Cash Out)**.

1. Confirm drawer and actual payout.
2. Choose expense category.
3. Enter **Disbursement Description**, amount, receipt reference, notes.
4. Submit once and confirm expense.
5. Check expected drawer cash; retain supplier evidence.

This records a paid expense and creates review. Do not record the same payout again under Expenses & Petty Cash.

**Example:** expected cash ₱2,000 minus recorded ₱150 supplies payout leaves ₱1,850.

### Transfers

In **Approvals & Tasks**, choose **Drop drawer cash to safe**, **Add drawer float**, or **Deposit drawer cash to bank**.

Enter drawer, actual amount, source/destination, reference, and reason. Obtain independent review; apply when the movement occurs. Verify history and expected cash.

These transfers are not sales or expenses. The application records them; it does not perform an online bank transfer.

If a form asks for a reason when covering another drawer, explain the actual arrangement. Normal continuous mode uses the same shared drawer for authorized cashiers.

## 19 Kitchen preparation and display stations

**Go to:** Kitchen KDS / kitchen view using kitchen account.

1. Verify room/guest or walk-in order.
2. Read items, quantities, special instructions.
3. When work begins, click **Start Cooking / Preparing**.
4. When ready, click **Mark Food Ready for Delivery**.
5. On handover/delivery, click **Mark Completed / Delivered**.
6. Confirm stage and coordinate with cashier/delivery staff.

Use the actual stage. Preparing stops the cashier's unprepared-order cancellation. Coordinate immediately if cancellation is requested.

View refreshes every 15 seconds. Use **Refresh Now** when needed. **Test Kitchen Chime** checks sound; browsers may require a click before audio.

Non-kitchen items are delivered immediately without cooking stages. Mixed orders containing food use the queue.

Use kitchen TV for a shared monitor and operating KDS for status updates. Use **Lobby Kiosk** for the designated customer display. Display-only accounts do not operate payments/rates.

Keep stations online. Refresh stale screens and verify against the operating page.

## 20 Admin: stay cancellation and unpaid departures

### Cancel a new unpaid stay

**Who:** admin role only. Cashier stay-cancellation controls are removed.

1. Open active stay's Add On / guest-orders panel.
2. Find **ADMIN: CANCEL STAY**.
3. Verify creation timestamp/deadline.
4. Confirm no recorded money or blocking orders/charges.
5. Enter **Reason for cancelling stay**.
6. Submit cancellation and verify cancelled record/room release.

Window is 15 minutes from stay **creation**, not RA-corrected check-in. Correction cannot extend it. If money/charges exist or deadline expired, use supported financial correction/refund rather than deleting history.

### Guest departs unpaid

Cashier opens **Force**, selects incident, and clicks **Submit for Approval**. This only creates a request.

Admin/owner:

1. Find write-off request in Approvals & Tasks.
2. Verify guest, room, balance, and incident.
3. Investigate and record review note.
4. Approve supported details, then apply.
5. Check **Loss Slips** and resulting record.
6. Confirm room status and preparation.

Write-off is unpaid loss, not collection. Changed charges can invalidate pending details; review current bill before application.

## 21 Review and apply approvals

**Go to:** Approvals & Tasks, **Property review queue**.

| Tab | Meaning |
| --- | --- |
| Needs review | Waiting for decision |
| Ready to apply | Approved but not applied |
| History | Applied, rejected, cancelled outcomes |
| All requests | Complete queue |

Pending/approved requests show oldest first. Read source, requester, proposed values, reason, and evidence; expand Technical record only if needed.

### Submit a new request

1. Expand the request form matching the task, such as **Room rate / custom guest discount**, **Return remaining guest deposit**, or **Refund a walk-in sale**.
2. Select the exact guest folio or original sale payment.
3. Enter only proposed changes. For a folio override, leave unchanged fields empty; fill **New room charge**, **Guest discount**, and **Discount ID reference** as appropriate.
4. Admin/owner may enter **Custom discount amount** when authorized. Refund requests need amount, method, and any GCash refund reference.
5. Enter **Reason** and supporting **Evidence reference**.
6. Click **Submit request** and check its outcome in the queue.

For an owner's folio override, check Ready to apply. For requests needing another reviewer, check Needs review. Normal fixed discounts use the room's Discount action directly.

### Decision and application

1. Compare source/evidence.
2. Enter **Review note**.
3. Click **Approve request** or **Reject request**.
4. For approved actions, open **Ready to apply**.
5. Click **Apply approved action**.
6. Verify History and actual changed record.

Approval records a decision; application makes the change once. **Approve expense review** instead completes review of money already spent; it does not pay again.

Fixed DC/Senior/PWD changes apply directly. Stock adjustments/recounts apply immediately. Older pending stock requests may show **Apply stock update now**.

Owner's own **Room rate / custom discount** requests auto-approve; owner can review their own older requests of that type, then apply.

Other financial exceptions still need a different admin/owner reviewer. Export preparers cannot review their own batch.

## 22 Owner: accounts and login history

**Go to:** Staff Accounts & RBAC.

Admin can inspect; **owner alone creates accounts, resets passwords, or deactivates staff**.

1. Enter staff name and unique login email.
2. Choose appropriate displayed role.
3. Set strong unique initial password.
4. Click **Create Staff Account**.
5. Give credentials privately.
6. Confirm login and required pages.

Use cashier access for cashier work and kitchen access for preparation. Avoid assigning owner merely to solve missing cashier navigation.

**Reset Staff Password** revokes old sessions/remembered logins. Staff sign in again. **Deactivate** stops access while preserving history; signed-in owner cannot deactivate their own account. Current page has no general role-edit/reactivate form.

Review outstanding work before stopping access. Shared drawer continues independently of that operator's login.

Admin/owner open **Login History**, apply account/date filters, and inspect successful/failed attempts, timestamps, and recorded source. Investigate unfamiliar activity. Never publish passwords in shared guides/screenshots.

## 23 Prices, discount schedules, catalog, and maintenance

### Room prices

In **Master Pricing Editor**:

1. Find exact room/type.
2. Edit intended package fields.
3. Save row and confirm success.
4. Check a future check-in preview and inform the desk.

Master edits affect future check-ins. Existing stays keep stored prices; active corrections use their authorized workflow. Update every intended room row when changing a category tariff.

### Discounts

Open **Discounts**, edit intended room/package deductions, and save. Compare against Lesson 33 when keeping the original schedule. New stays use the updated schedule; existing snapshots are retained. PWD uses Senior amounts.

### Menu

In Master Pricing Editor, change item price/availability and save. New items need name, category, price, stock tracking, initial quantity, reorder level, availability, and displayed kitchen settings.

Food remains available all day subject to active availability and stock/ingredients. Existing order lines retain recorded sale prices.

Retire items that should stop being sold; history remains. Later exact stock updates use Stock immediately.

### Room maintenance

Use vacant room's maintenance action. Toggle back when ready. Active occupied room blocks changes. Cleaning/inspection/key readiness remain physical staff tasks.

## 24 Daily SCTI register and guest counting

**Go to:** Daily SCTI Register.

Format is **SCTI-YYYYMMDD-NNN**, for example **SCTI-20261014-001**, 002, 003. Manila calendar date changes at midnight, starting the next day's sequence.

1. Choose **Receipt date**.
2. Click **Show day**.
3. Read registered stays, valid stays, guests, and cancellations.
4. Review sequential rows/pagination.
5. Investigate gaps using retained cancellations/audit.

| Count | Meaning |
| --- | --- |
| Registered stays | Guest stays allocated on that receipt date |
| Valid stays | Registered stays excluding cancelled ones |
| Guests | Sum of pax on valid stays |
| Cancelled stays | Retained cancelled records |

**Example:** 003 has 2 pax; 004 has 3 pax. This is **2 stays, 5 guests**. Last suffix is not the day's guest count.

One number identifies a stay. Orders, deposits, transfers, conversions, and reprints do not allocate a new stay number. Cancelled numbers are never reused.

Historical SCTI-998997-style numbers remain unchanged. RA time correction does not change receipt date. Totals cover the whole selected date regardless of pagination. Staff quarters are excluded.

The register is **midnight-to-midnight**. Daily Finance is **6AM-to-6AM**. A 2AM arrival goes on that calendar day's register; its payment may belong to the previous financial business date.

## 25 Reports, ledger, and losses

| Page | Question |
| --- | --- |
| Front Desk | Which guest rooms need attention now? |
| Daily SCTI Register | How many stays/pax were registered this receipt date? |
| Transactions & Audit | Who recorded this money/action? |
| Ledger | Where are guest folios, POS sales, and vouchers? |
| Reports & Audits | What is the recent operational/sales picture? |
| Executive P&L | What is the selected period's recorded result? |
| Shift Reconciliations | What historical drawer counts/variances exist? |
| Loss Slips | Which unpaid departures became approved losses? |
| Daily Finance | Is this financial day ready to close? |

Use Ledger Receipt/Deposit/Billing links after checkout success links are gone. In P&L, choose period/custom range, click Filter, and use Export CSV Report as required.

Opening float is existing cash. Deposits are held money until applied/refunded. GCash is not physical cash. Change is returned tender. Cash drops/bank deposits are transfers. Write-offs are unpaid losses.

Active stay is not completed paid sale. Cancelled/staff stays are excluded from guest counts. Pax differs from number of stays.

Check displayed dates: Daily Finance uses 6AM cutoff; calendar filters/other reports use their own period. Compare equal time windows.

## 26 Review, close, and reopen Daily Finance

**Go to:** Daily Finance. Admin/owner review; owner reopens.

Business date covers **6AM that date to just before 6AM next day**, Manila time. It does not end guest stays or reset SCTI.

### Close a reviewed day

1. Select Business date and click **Review day**.
2. Confirm time window.
3. Review movements, deposits, expenses, requests, statement matching.
4. Read every **Close checks** item.
5. Resolve actual listed exceptions.
6. Wait until the next-morning 6AM cutoff has passed.
7. Enter reason and click **Close business day**.
8. Confirm closed status/version.

The continuous shared drawer can remain open. Disabled handovers do not block normal closing. Cash variances, unresolved reviews, unmatched statements, and other listed exceptions still need attention.

Closed financial periods lock posting. Stock counts remain independently editable.

### Reopen

1. Owner selects closed day.
2. Enter reason; use **Reopen with retained history**.
3. Make authorized correction/matching/review.
4. Close again and confirm new version.

Previous snapshots remain. Old exports become superseded; coordinate replacements/corrections already delivered to accounting. Guests still staying do not need forced checkout to close finance.

## 27 Provider statements and expense review

### Import statements

In Daily Finance:

1. Click **Download CSV template**.
2. Fill reference, posted_at, gross_amount, fee_amount, net_amount from provider records.
3. Confirm net = gross minus fees.
4. Select GCash/Bank under Statement import.
5. Choose CSV and click **Import statement**.
6. Check **Recent statements & matching**.

Limit is 2,000 rows. Offset-free timestamps use Manila time. Exact duplicates are skipped; changed rows may be new imports, so inspect before repeating.

### Match

Find sources in Day movements/Transactions. Enter Payment IDs for GCash or Bank movement IDs for deposits, comma-separated when needed. Confirm provider, reference, direction, combined gross; click **Match exact total**.

Reviewer must differ from source operator. Already matched payments cannot be reused.

**Example:** gross ₱1,000, fee ₱10, net ₱990. Source payments must total ₱1,000; provider fee does not change guest payment.

Use **Exclude an incorrect statement import** request for an incorrect unmatched row, review, then apply. Original row remains.

### Expenses

In Expenses & Petty Cash, check date, category, receipt, amount, and payment source. Cash Drawer Register affects drawer; Executive Petty Cash Fund and Bank / Online Wire are separate.

Save Expense Voucher only for actual expenses not already recorded by cashier. A rejected paid expense remains spent money. When money actually returns, use **Request cash return** to create a reviewed expense reversal. Original voucher stays.

## 28 Accounting setup and configured invoices

**Go to:** Daily Finance, **Accounting setup**. Owner enters approved property/accountant information.

1. Enter legal name, address, identification, registration.
2. Enter **Accountant approval reference**.
3. Select approved tax treatment/rate.
4. Enter invoice series/next sequence.
5. Enter opening reconciliation reference/CSV destination.
6. Fill required account mappings.
7. Enable invoices/exports only when complete.
8. Click **Save approved configuration**.

100 basis points = 1%. Configured tax extraction identifies tax within the sale; it does not add an extra charge simply because a tax field exists.

### Issue

1. Complete paid guest checkout or eligible paid walk-in sale.
2. Open Approvals & Tasks, **Configured invoices**.
3. Use guest/walk-in pagination to locate source.
4. Click **Issue / open invoice**.
5. Verify number/source/totals/deposit application.
6. Use **Record print request**, browser print, and inspect paper.

Guest and walk-in lists paginate separately. Cashier access follows guest operating permissions and their own eligible walk-in sources; admin/owner have broader review.

Invoice sequence differs from daily SCTI. Issued numbers are retained and never reused; opening/printing again keeps the same invoice.

Issue before the original sale's day closes. Cancelled/written-off stays are not paid invoice sources.

Senior/PWD VAT-inclusive folios may need reviewed **Statutory invoice tax basis** with evidence. Use approved values, not guesses. Current screens have no general issued-invoice edit/void workflow.

## 29 Prepare and deliver accounting CSV

**Go to:** Daily Finance, closed business day.

1. Confirm closed/reviewed day and enabled account mappings.
2. Click **Prepare reviewed CSV batch**.
3. Find draft in **Accounting export outbox**.
4. Verify day/version/destination/lines.
5. Different admin/owner clicks **Approve export**.
6. Use **Download reviewed CSV**.
7. Deliver/import through agreed accounting process.
8. Enter actual delivery reference; click **Record manual delivery**.
9. Confirm delivered status.

New batches start as draft despite the prepare-button wording. Preparer cannot review own batch. Download and delivery acknowledgment are separate.

The system does not automatically upload/send it to an accountant. Reopened days use latest reclosed version; previously delivered versions may need reversal/replacement correction batches. Coordinate with accountant.

P&L summary CSV is different from mapped accounting export.

## 30 Troubleshooting without duplicate records

| Problem | Check / next step |
| --- | --- |
| Checkout blocked | Usable drawer, covered balance, required ID/reference, financial-day status |
| Restock wrong | Enter final count: 50 + 10 = 60; inspect saved movement before retry |
| Food unavailable despite stock | Availability, retirement, recipe ingredients, Egg stock |
| XOrder blocked | Creation deadline, payment, preparation/delivery, active stay |
| Late check-in time | RA/admin uses Check-in Time Corrections |
| Discount wrong | Room type, tier, saved snapshot, custom override, reference |
| No 12h conversion | Open Extend on eligible active 12h stay |
| Different reviewer needed | Other financial exceptions need independent admin/owner |
| POS room turned over | Refresh; select current guest's folio |
| Booking conflict | Scheduled reservations/current guest expected occupancy |
| Page failed after submit | Check history before repeating |
| Printer silent | Verify saved transaction, printer, paper, connection, copy |

### Missing table / update

"No such table: document_sequences" means required migrations are missing. Operator uses normal update in Lesson 34; do not erase the DB to repair it.

Refresh after server pulls code, clears caches, builds assets, and migrates. A GitHub push alone does not update another server.

### Report an unclear result

Capture full error, room/SCTI/order, time, action, and whether history shows it. Avoid repeated uncertain payments. Share only needed guest details.

A 403 normally means missing access. Use authorized admin for RA-only work rather than sharing another staff login.

In Approvals & Tasks, **Cancel this request** withdraws an unexecuted request, not an order/stay or completed payment. Recheck stale requests against current source details.

## 31 Daily operating checklists

### Cashier starts

- [ ] Sign in as yourself.
- [ ] Confirm shared drawer usable; start only if none exists.
- [ ] Review occupied rooms, alerts, deposits, pending orders.
- [ ] Confirm sound and important incident notes.

### Every departure

- [ ] Verify guest, room, SCTI, corrected/actual check-in.
- [ ] Verify package, extensions, grace-adjusted overtime, add-ons.
- [ ] Verify discount/reference and recorded deposit.
- [ ] Record actual cash/GCash; return correct change.
- [ ] Complete checkout once and verify saved result.
- [ ] Print correct documents; coordinate key/room preparation.

### Cashier finishes

- [ ] Resolve uncertain results using history.
- [ ] Tell next cashier pending work/incidents.
- [ ] Coordinate physical cash count under property procedure.
- [ ] Log out; shared drawer stays open.

### Admin/owner review

- [ ] Review active stays, corrections, and exceptions.
- [ ] Use daily register for stays/pax and retained cancellations.
- [ ] Review Needs review and Ready to apply.
- [ ] Verify expenses, refunds, cash transfers, losses.
- [ ] Review stock movements/ingredients.
- [ ] Match statements and resolve finance close checks.
- [ ] Issue needed invoices before day close.
- [ ] Close after 6AM cutoff; review/deliver accounting exports.

## 32 Staff practice walkthrough

Use a separate training installation. Do not create practice transactions or reset live hotel data.

### Cashier

1. Sign in as training cashier.
2. Start shared drawer with ₱1,000 or use existing training drawer.
3. Check in Classic, 2 pax, 3h; verify ₱395/new daily SCTI.
4. Add drink and meal in one submission; inspect Previous Orders.
5. Cancel a separate untouched order within 15 minutes using XOrder.
6. Apply DC; verify ₱40 deduction.
7. Record training deposit ₱200.
8. Review ₱395 - ₱40 + remaining add-ons - applied ₱200.
9. Complete one cash settlement, verify change/receipt/history.
10. Log out without closing drawer.

Training menu prices may be made up; do not treat them as production tariff.

### Admin/owner

1. Correct a late-entered training check-in and verify new due time.
2. Convert training 12h to 24h through Extend.
3. Transfer Classic to Premium; verify price/discount.
4. Owner requests custom discount and applies auto-approved change.
5. Set an exact stock count; confirm immediate change.
6. Compare daily register stays/pax/cancellations.
7. Review a sample financial request and apply separately.
8. Walk through day review without closing unfinished live finance.

### Knowledge check

XOrder cancels an eligible order. Checkout grace is 15m after due time. Admin corrects check-in time. Logout leaves drawer open. Reprint retains SCTI. Restock uses final on-hand count. Deposits are held money, not another room sale.

## 33 Original room tariffs and fixed discounts

These are the original agreed schedules for this edition. Installed master values and stored stay/order prices determine actual billing. Review later authorized changes separately.

### Standard packages

| Room | 3h | 4h | 6h | 12h | 24h |
| --- | --- | --- | --- | --- | --- |
| Classic | ₱395 | ₱525 | ₱785 | ₱1,195 | ₱2,100 |
| Premium | ₱495 | ₱625 | ₱885 | ₱1,295 | ₱2,300 |
| VIP | ₱695 | ₱825 | ₱1,085 | ₱1,395 | ₱2,500 |

Extra/extension hour **₱130**. Maximum checkout grace **15 minutes after due**. Midnight promo disabled for new stays/reservations.

### DC deductions

| Room | 3h | 4h | 6h | 12h | 24h |
| --- | --- | --- | --- | --- | --- |
| Classic | ₱40 | ₱0 | ₱0 | ₱55 | ₱95 |
| Premium | ₱50 | ₱0 | ₱0 | ₱60 | ₱105 |
| VIP | ₱65 | ₱0 | ₱0 | ₱70 | ₱115 |

### Senior / PWD deductions

| Room | 3h | 4h | 6h | 12h | 24h |
| --- | --- | --- | --- | --- | --- |
| Classic | ₱79 | ₱0 | ₱158 | ₱195 | ₱340 |
| Premium | ₱99 | ₱0 | ₱178 | ₱215 | ₱375 |
| VIP | ₱139 | ₱0 | ₱218 | ₱255 | ₱460 |

These are peso deductions, not selling prices. ID/reference rules remain.

### Examples

| Stay | Calculation | Room amount |
| --- | --- | --- |
| Classic 3h DC | ₱395 - ₱40 | ₱355 |
| Premium 12h DC | ₱1,295 - ₱60 | ₱1,235 |
| VIP 24h Senior/PWD | ₱2,500 - ₱460 | ₱2,040 |
| Classic 3h lasting 5h 10m, none | ₱395 + 2 x ₱130 | ₱655 |

Examples exclude add-ons/deposits/other charges. Choose amenities from current catalog and read their actual displayed price.

## 34 Server operator: updates, backup, and reset

For the server maintainer. Cashiers use operating screens and report errors.

### Normal Windows CMD update

Open CMD in the actual Sedona Court 3.0 server folder containing artisan. One-pass command:

Paste this whole block into CMD once. The caret at each line end continues the same command:

~~~bat
git pull --ff-only origin main ^
  && php artisan optimize:clear ^
  && npm run build ^
  && php artisan migrate --force
~~~

Each step runs only after prior success. Resolve failed commands/local Git changes without force-erasing files/DB.

Keep existing environment/key/database. Fresh migrations or seeding are not normal updates. Refresh screens afterward.

For an existing local/LAN installation using Artisan's development server:

~~~bat
php artisan serve --host=0.0.0.0 --port=8000
~~~

Keep terminal running and use property's configured address/port. Do not start a second instance on an occupied port. Hosted production uses its established service/startup procedure.

### Backup

Follow property backup procedure before major operations. Protect database, environment configuration, and required uploaded files. Test restores separately. Use consistent SQLite backup or stop writes; do not blindly copy a live DB during writes.

### Selective fresh start

**Destructive:** this removes transaction history. Use only when owner has decided to discard existing guest/sales/financial records. Never use as a routine repair.

Supported reset is SQLite-only and requires maintenance mode. In correct 3.0 folder, Windows CMD:

Paste the complete block once; keep the line-ending carets:

~~~bat
git pull --ff-only origin main ^
  && php artisan down ^
  && php artisan migrate --force ^
  && php artisan pms:reset-transactions --force ^
  && php artisan up
~~~

Creates full backup first, printing location under storage/app/private/database-backups. If a step fails, later steps stop. Resolve it before deliberately returning service if maintenance remains enabled.

| Kept | Cleared |
| --- | --- |
| Accounts, roles, permissions | Guests/stays/reservations/orders |
| Menu and current stock counts | Payments, expenses, shifts/drawers |
| Rooms/rates/discount setup | Approval, reconciliation, invoice/export history |
| Accounting settings and numbering counters | Old transaction audit/movements, sessions/jobs/cache |

Records new reset audit. Occupied guest rooms become available; maintenance/staff-quarter states are retained. Staff sign in and open drawer afterward.

Issued counters are preserved. Already used today's 001 is not reused; new stays continue today's count. New calendar date starts its own count.

Never use **php artisan migrate:fresh --seed** for this selective reset; it would not preserve requested records.

### Scope

This edition covers current operating screens/rules. Site permissions, printer setup, accounting enablement, and later authorized prices can differ. Verify saved records and actual money/print outcomes during daily work.
