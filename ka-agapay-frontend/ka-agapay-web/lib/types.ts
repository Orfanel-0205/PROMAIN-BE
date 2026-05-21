// lib/types.ts

export interface User {
    user_id: number;
    first_name: string;
    last_name: string;
    email: string;
    mobile_number: string;
    account_status: string;
    role: string;
    barangay: string | null;
}

export interface QueueTicket {
    id: number;
    ticket_number: string;
    status: 'waiting' | 'called' | 'in_service' | 'completed' | 'cancelled' | 'no_show' | 'skipped';
    service_type: string;
    queue_position: number;
    priority: {
        score: number;
        category: string;
        flags: {
            is_emergency: boolean;
            is_pregnant: boolean;
            is_senior: boolean;
            is_pwd: boolean;
        };
    };
    resident: {
        id: number;
        name: string;
        barangay: string;
    };
    timestamps: {
        issued_at: string;
        called_at: string | null;
        service_started_at: string | null;
        service_ended_at: string | null;
    };
    performance: {
        wait_time_minutes: number | null;
        service_time_minutes: number | null;
    };
}

export interface TelemedicineRequest {
    id: number;
    status: string;
    urgency_level: 'routine' | 'urgent' | 'emergency';
    chief_complaint: string;
    resident: {
        id: number;
        name: string;
        barangay: string;
    };
    session: TelemedicineSession | null;
    created_at: string;
}

export interface TelemedicineSession {
    id: number;
    status: string;
    session_mode: string;
    session_token: string;
    schedule: {
        date: string;
        time: string;
    };
    assigned_doctor: {
        id: number;
        name: string;
    } | null;
    started_at: string | null;
    ended_at: string | null;
}

export interface Prescription {
    id: number;
    prescription_number: string;
    status: string;
    diagnosis: string;
    medications: Medication[];
    resident: { id: number; name: string };
    prescribed_by: { id: number; name: string };
    dates: {
        prescription_date: string;
        valid_until: string;
        is_expired: boolean;
    };
}

export interface Medication {
    name: string;
    dosage: string;
    dosage_form: string;
    frequency: string;
    duration: string;
    quantity: number;
}

export interface InventoryItem {
    id: number;
    item_code: string;
    name: string;
    category: string;
    current_stock: number;
    minimum_stock_level: number;
    unit_of_measure: string;
    expiration_date: string | null;
    is_active: boolean;
}

export interface DashboardData {
    generated_at: string;
    today: {
        queue: {
            total: number;
            waiting: number;
            completed: number;
            avg_wait_minutes: number;
        };
        telemedicine: {
            total: number;
            pending: number;
            completed: number;
            emergency: number;
        };
        prescriptions: {
            total_issued: number;
            dispensed: number;
        };
        referrals: {
            pending: number;
            urgent: number;
        };
    };
}