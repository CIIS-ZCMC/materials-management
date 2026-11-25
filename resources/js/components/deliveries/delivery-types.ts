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

// What the PO detail page receives for its Deliveries tab.
export type PoDeliveriesData = {
    can_record: boolean;
    blocked_reason: string | null;
    rows: DeliveryRow[];
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
