// Shared shapes for the delivery screens (step 5.2). They mirror what DeliveryService::row() and detail() send.

export type Person = {
    id: number | string;
    employee_no: string;
    name: string;
    position: string | null;
    office: string | null;
};

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
    person_id: string;
    employee_no: string;
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
export type DeliveryLine = {
    po_item_id: number;
    description: string;
    unit: string | null;
    ordered: string;
    delivered: string;
    undelivered: string;
    over_by: string | null;
    rows: DeliveryItemEntry[];
};

// The delivered-items panel of the delivery page.
export type DeliveryItemsPanelData = {
    can_update: boolean;
    locked_reason: string | null;
    progress: ItemProgress;
    lines: DeliveryLine[];
};

// What the update form receives.
export type ItemSheet = {
    today: string;
    min_date: string | null;
    progress: ItemProgress;
    lines: DeliveryLine[];
};

// What the PO detail page receives for its Deliveries tab.
export type PoDeliveriesData = {
    can_record: boolean;
    blocked_reason: string | null;
    rows: DeliveryRow[];
    progress: ItemProgress | null; // null for people who may not read deliveries
    lines: DeliveryLine[];
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