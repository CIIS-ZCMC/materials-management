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