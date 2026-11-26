// Shared shapes for the delivery screens (step 5.2). They mirror what DeliveryService::row() and detail() send.

export type DeliveryRow = {
    id: number;
    iar_number: string;
    iar_series: { value: string; label: string };
    invoice_no: string;
    fund_cluster: string;
    amount: string;
    date_received: string;
    iar_deadline: string | null;
    iar_overdue: boolean;
    status: { value: string; label: string };
    can_edit: boolean;
    inspectors: string[];
    end_user: string | null;
    purchase_order: { id: number; po_number: string; supplier: string | null };
};

export type SignatoryRow = {
    role: 'inspector' | 'end_user';
    role_label: string;
    // Step 5.2b: null for people typed by hand; old rows copied from the personnel directory still carry both.
    person_id: string | null;
    employee_no: string | null;
    name: string;
    position: string | null;
};

export type DeliveryDetail = DeliveryRow & {
    void_reason: string | null;
    voided_by: string | null;
    voided_at: string | null;
    created_by: string | null;
    created_at: string | null;
    signatories: SignatoryRow[];
};

export type DeliveryFormOptions = {
    series: { value: string; label: string }[];
    today: string;
    min_inspectors: number;
    max_inspectors: number;
    iar_days: number;
};

// Step 6.1e: shown (outline button + note, amber banner on the form) when a delivery is about to be recorded for a PO that is
// already fully delivered. The server only says WHETHER (fully_delivered); the sentence lives here so the three screens match.
export const FULLY_DELIVERED_NOTE =
    'This PO is fully delivered. Record another delivery only if the supplier delivered more than ordered (over-delivery).';

export type PoSummary = { id: number; po_number: string; supplier: string | null; po_date: string | null };

// Step 6.1: the delivered items. The progress and every total are worked out by the server each time (never saved).
export type ItemProgress = { value: 'not_delivered' | 'partial' | 'full'; label: string };

export type DeliveryItemEntry = {
    id: number;
    delivery_id: number;
    iar_number: string;
    delivery_voided: boolean;
    delivered_on: string;
    quantity: string;
    status: { value: string; label: string }; // 'active' | 'void'
    over_delivery: boolean;
    over_delivery_reason: string | null;
    recorded_by: string | null;
    recorded_at: string | null;
    void_reason: string | null;
    voided_by: string | null;
    voided_at: string | null;
    can_void: boolean;
};

// One PO line: ordered, delivered (all non-voided deliveries, active rows), undelivered, over_by (null when not over) and its history rows.
// Step 6.1b: entered_here = the quantity this line already has on THIS delivery (null = none yet; a line holds one active quantity per
// delivery), active_here = how many active rows it has here (more than 1 only for rows saved before the rule; they still count).
// Both are empty (null / 0) on the PO tab, which is not about one delivery.
export type DeliveryLine = {
    po_item_id: number;
    description: string;
    unit: string | null;
    ordered: string;
    delivered: string;
    undelivered: string;
    over_by: string | null;
    entered_here: string | null;
    active_here: number;
    rows: DeliveryItemEntry[];
};

// Step 6.1d: the typed Amount against the value of the quantities entered (quantity x unit price). A check only, never a block.
// difference is positive text and direction says whether the TYPED Amount is higher or lower than the value (both null unless state is 'differs').
export type AmountCheck = {
    amount: string;
    value: string | null; // null = no quantities entered yet
    difference: string | null;
    direction: 'higher' | 'lower' | null;
    state: 'none' | 'matches' | 'differs';
};

// The delivered-items panel of the delivery page.
export type DeliveryItemsPanelData = {
    can_update: boolean;
    locked_reason: string | null;
    can_record_another: boolean; // delivery.encode AND the PO still accepts a delivery (step 6.1b)
    fully_delivered: boolean; // step 6.1e: the PO is fully delivered (derived); softens the Record another delivery button
    record_blocked_reason: string | null; // only for people who hold delivery.encode
    amount_check: AmountCheck; // step 6.1d
    progress: ItemProgress;
    lines: DeliveryLine[];
};

// What the update form receives.
// Step 6.1b: no date per line; every row takes the delivery's date received.
export type ItemSheet = {
    date_received: string;
    progress: ItemProgress;
    lines: DeliveryLine[];
};

// Step 6.1b: one delivery (one IAR) of the PO with its quantities. running_total = the line's total after this row (null for a voided row
// or a voided delivery: it does not count).
export type DeliveryGroupRow = DeliveryItemEntry & { description: string | null; unit: string | null; running_total: string | null };

export type DeliveryGroup = {
    delivery_id: number;
    iar_number: string;
    status: { value: string; label: string };
    date_received: string;
    rows: DeliveryGroupRow[];
};

// Step 6.1d: the typed Amounts of the deliveries that are not voided, next to the PO total. over_by is set only when they add up to MORE than the PO total.
export type PoAmounts = { deliveries_total: string; po_total: string; over_by: string | null; counted: number };

// What the PO detail page receives for its Deliveries tab.
export type PoDeliveriesData = {
    can_record: boolean;
    blocked_reason: string | null;
    fully_delivered: boolean; // step 6.1e: the PO is fully delivered (derived); false for people who may not read deliveries
    rows: DeliveryRow[];
    progress: ItemProgress | null; // null for people who may not read deliveries
    lines: DeliveryLine[]; // totals only (rows are empty here)
    groups: DeliveryGroup[]; // the history, one group per delivery
    amounts: PoAmounts | null; // step 6.1d; null for people who may not read deliveries
};

// Step 5.3: the documents panel of the delivery page. The server decides every can_* flag (DeliveryPolicy + DeliveryDocumentRule).
export type DeliveryChecklistItem = { key: string; label: string; required: boolean; count: number };

export type DeliveryDocumentRow = {
    id: number;
    type: string;
    type_key: string;
    original_name: string;
    size_bytes: number;
    status: string; // 'active' | 'void'
    page_from: number | null; // the page in the PO's combined PDF (null = not in it, or not built yet)
    page_to: number | null;
    void_reason: string | null;
    uploaded_by: string | null;
    uploaded_at: string | null;
};

export type DeliveryDocumentsData = {
    can_upload: boolean;
    can_void: boolean;
    can_replace: boolean;
    can_complete: boolean;
    can_reopen: boolean;
    can_view: boolean;
    can_view_combined: boolean;
    locked_reason: string | null;
    accept: string;
    max_mb: string;
    max_files: number;
    max_total_mb: number;
    types: { key: string; label: string }[];
    checklist: { required_total: number; required_done: number; complete: boolean; missing: string[]; items: DeliveryChecklistItem[] };
    pdf_mode: 'on_upload' | 'when_complete';
    rows: DeliveryDocumentRow[];
};