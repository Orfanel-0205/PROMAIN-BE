<?php
// app/Services/Ai/AnswerBook.php
//
// Written answers for "how does this screen work".
//
// The AI model is good at conversation and bad at remembering which button
// this particular system has. Asked how to release a prescription it would
// invent a plausible-sounding sequence — the "use the search bar, usually
// found at the top" problem staff complained about.
//
// So the questions staff actually ask about Ka-Agapay are answered from here:
// written once, reviewed like any other code, and identical every time. The
// model still handles everything else, and it is told to use this text when
// the question matches a page.
//
// WHEN A SCREEN CHANGES, CHANGE THE ENTRY. An answer book that drifts from the
// software is worse than no answer book, because staff trust it.

namespace App\Services\Ai;

class AnswerBook
{
    /**
     * One entry per screen: the words staff use for it, and what to tell them.
     *
     * `steps` are the exact buttons in the exact order. `notes` carry the
     * things that bite people: the rule that refuses a save, the thing that
     * cannot be undone, the place a record goes afterwards.
     */
    private const ENTRIES = [
        'queue' => [
            'title' => 'Queue',
            'keywords' => ['queue', 'pila', 'ticket', 'call next', 'now serving'],
            'summary' => 'Today\'s walk-in patients, in the order they will be seen.',
            'steps' => [
                'Click the Queue button in the sidebar.',
                'Issue a ticket with New Ticket, choosing the service and any priority (senior, pregnant, PWD, emergency).',
                'Click Call Next to call the highest-priority waiting ticket.',
                'Mark the patient Served when the consultation starts, or No Show if they do not come.',
            ],
            'notes' => [
                'Priority is computed, not chosen: seniors, pregnant patients, PWDs, children under 5 and emergencies rise automatically.',
                'The queue is per RHU. You only ever see and call your own facility\'s tickets.',
                'Tickets reset each day; yesterday\'s queue stays in the records, not on the board.',
            ],
        ],

        'appointments' => [
            'title' => 'Appointments',
            'keywords' => ['appointment', 'tipanan', 'booking', 'approve appointment', 'reschedule'],
            'summary' => 'Requests residents made from the mobile app, waiting for a decision.',
            'steps' => [
                'Click the Appointments button.',
                'Work the Pending list first: check the patient, reason, date and type.',
                'Click Approve to confirm, or Reject and give a clear reason the resident will read.',
                'Approved appointments flow into the queue on the day itself.',
            ],
            'notes' => [
                'Rejecting sends the reason to the resident\'s phone, so write it for them, not for staff.',
                'Each RHU has its own daily capacity; approving beyond it is what causes crowding at the door.',
            ],
        ],

        'consultations' => [
            'title' => 'Consultations',
            'keywords' => ['consultation', 'konsulta', 'check-up', 'diagnosis', 'soap'],
            'summary' => 'The clinical record of a visit: findings, diagnosis and what was prescribed.',
            'steps' => [
                'Click the Consultations button, or start one from a called queue ticket.',
                'Record the findings and diagnosis.',
                'Add a prescription or lab request from inside the consultation, so it is linked to the visit.',
                'Save. The record appears in the patient\'s history and in reports.',
            ],
            'notes' => [
                'Only a doctor or the MHO may issue a prescription; nurses, midwives and BHWs can release or dispense one that exists.',
            ],
        ],

        'prescriptions' => [
            'title' => 'E-Prescription / Lab Requests',
            'keywords' => ['prescription', 'reseta', 'e-prescription', 'lab request', 'release', 'dispense', 'pdf'],
            'summary' => 'Prescriptions and lab requests: issuing them, printing them, and handing out medicine.',
            'steps' => [
                'Click the E-Prescription / Lab Requests button.',
                'Release gives the patient the PDF. Choose whether the medicine is also being handed over from the RHU drug room now.',
                'Dispense records medicine handed over: who received it, and how much of each item.',
                'Open Prescription PDF prints or saves the document.',
            ],
            'notes' => [
                'Every dispense asks who received the medicine — the patient, or the person collecting for them. It cannot be skipped.',
                'Handing over part of a prescription deducts only that part. The prescription stays "partially dispensed" until the rest is given.',
                'Only doctors and the MHO can create a prescription. Dispensing staff at the issuing RHU can release and dispense.',
                'A prescription is valid for 7 days from the date it was issued.',
            ],
        ],

        'patients' => [
            'title' => 'Patient Registry',
            'keywords' => ['patient', 'pasyente', 'registry', 'resident record', 'patient profile'],
            'summary' => 'Every resident registered with your RHU, and their health record.',
            'steps' => [
                'Click the Patient Registry button.',
                'Search by name to open a patient.',
                'The profile holds their details, consultations, prescriptions and follow-ups.',
            ],
            'notes' => [
                'You see residents of your own RHU. The MHO and super admin see both.',
                'A resident appears here once their registration is approved, not when they first sign up.',
            ],
        ],

        'registrations' => [
            'title' => 'Registration Approvals',
            'keywords' => ['registration', 'approve user', 'verify', 'sign up', 'id verification', 'pending account'],
            'summary' => 'People who signed up and are waiting to be let in.',
            'steps' => [
                'Click the Registration Approvals button.',
                'Open a registrant and compare the submitted ID against the details they typed.',
                'For staff, assign the final role before approving.',
                'Approve, or Reject with a reason.',
            ],
            'notes' => [
                'Approval is blocked until an ID has been submitted, on purpose.',
                'The MHO may approve clinical roles; other staff roles need a super admin.',
                'Until approved, an account can do almost nothing, so a waiting resident cannot book or be seen.',
            ],
        ],

        'inventory' => [
            'title' => 'Inventory',
            'keywords' => ['inventory', 'stock', 'medicine', 'gamot', 'supply', 'expiry', 'low stock'],
            'summary' => 'Medicines and supplies held by your RHU.',
            'steps' => [
                'Click the Inventory button.',
                'Stock In records a delivery; Stock Out records anything taken that is not a dispense.',
                'Low stock and expiring items are flagged at the top.',
            ],
            'notes' => [
                'Dispensing a prescription deducts stock automatically. Do not also record it by hand, or the count doubles.',
                'Stock is per RHU. Each facility counts its own.',
            ],
        ],

        'followups' => [
            'title' => 'Health Follow-up',
            'keywords' => ['follow-up', 'follow up', 'followup', 'bantay', 'reminder', 'sms reminder'],
            'summary' => 'Patients due to come back, and the reminders sent to them.',
            'steps' => [
                'Click the Health Follow-up button.',
                'Work Overdue first, then Today.',
                'Send SMS resends a reminder; Consultation opens the visit it came from.',
            ],
            'notes' => [
                'Reminders go out on a schedule, not when you open this screen.',
                'An SMS costs money per message, so resend only when it will change something.',
            ],
        ],

        'telemedicine' => [
            'title' => 'Telemedicine',
            'keywords' => ['telemedicine', 'video call', 'online consultation', 'remote'],
            'summary' => 'Video consultations with residents who cannot come in.',
            'steps' => [
                'Click the Telemedicine button.',
                'Approve a request, then Join when the patient is ready.',
                'Write the consultation notes as you would for a walk-in.',
            ],
            'notes' => [
                'The patient joins from their phone. If their connection is poor, audio alone usually holds.',
            ],
        ],

        'events' => [
            'title' => 'Events',
            'keywords' => ['event', 'program', 'announcement', 'cms', 'post', 'publish', 'visibility'],
            'summary' => 'Posts residents see in the mobile app: events, programs and advisories.',
            'steps' => [
                'Click the Events button, then New Post.',
                'Fill the title, description, schedule, venue and audience.',
                'Choose the visibility: Public, or one RHU only.',
                'Publish. Save as draft if it is not final.',
            ],
            'notes' => [
                'A post dated in the past cannot be published.',
                'Visibility set to one RHU means only that RHU\'s residents see it. Public means everyone.',
            ],
        ],

        'reports' => [
            'title' => 'Reports',
            'keywords' => ['report', 'export', 'doh', 'monthly report', 'excel', 'csv'],
            'summary' => 'Exports for DOH reporting and for the municipality.',
            'steps' => [
                'Click the Reports button.',
                'Choose the report, the date range and the RHU.',
                'Generate, then download.',
            ],
            'notes' => [
                'Figures come from records as they stand now, so a late consultation changes last month\'s report.',
            ],
        ],

        'analytics' => [
            'title' => 'Analytics and Heatmap',
            'keywords' => ['analytics', 'heatmap', 'trend', 'dashboard', 'statistics', 'chart'],
            'summary' => 'Patterns across barangays and services: where cases cluster, how the queue moves.',
            'steps' => [
                'Click the Analytics button for totals and trends.',
                'Click the Heatmap button for barangay distribution.',
                'Set the RHU focus and date range before reading anything.',
            ],
            'notes' => [
                'These are planning tools, not a diagnosis. Check the underlying records before acting on a cluster.',
            ],
        ],

        'users' => [
            'title' => 'Users',
            'keywords' => ['user', 'staff account', 'role', 'deactivate', 'password reset'],
            'summary' => 'Staff accounts, their roles and which RHU they belong to.',
            'steps' => [
                'Click the Users button.',
                'Open a staff member to change their role or RHU.',
                'Deactivate removes their access without deleting their record.',
            ],
            'notes' => [
                'A staff member\'s RHU decides which patients and queues they can see.',
                'Deactivate rather than delete: their name still has to appear on the records they made.',
            ],
        ],

        'rhus' => [
            'title' => 'RHU Facilities',
            'keywords' => ['rhu facility', 'add rhu', 'new rhu', 'facility', 'barangay assignment'],
            'summary' => 'The Rural Health Units this system serves. Super admin only.',
            'steps' => [
                'Click the RHU Facilities button under Administration.',
                'Add an RHU with its short name, code and address.',
                'Choose the barangays it serves — until you do, it serves nobody.',
                'Assign staff to it in Users.',
            ],
            'notes' => [
                'A facility is switched off rather than deleted, because its queue tickets and prescriptions carry its name.',
                'Moving a barangay moves its residents: their queue, appointments and follow-ups follow the new facility.',
            ],
        ],

        'teamchat' => [
            'title' => 'Team Chat',
            'keywords' => ['team chat', 'chat', 'message staff', 'group chat', 'sticker'],
            'summary' => 'Messaging between staff. Never visible to residents.',
            'steps' => [
                'Click the Team Chat button.',
                'Start a conversation with New Chat, or open an existing one.',
                'Hover a message to react with a duck, or use the emoji button to add one to your own message.',
            ],
            'notes' => [
                'You can only message staff in your own RHU. The MHO and super admin can message both.',
                'Patient details in chat are still patient details: say the record number rather than the whole history.',
            ],
        ],
    ];

    /**
     * The entry that answers this question, or null when nothing matches and
     * the model should answer in its own words.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $message): ?array
    {
        $lower = mb_strtolower($message);
        $best = null;
        $bestScore = 0;

        foreach (self::ENTRIES as $key => $entry) {
            $score = 0;

            foreach ($entry['keywords'] as $keyword) {
                if (str_contains($lower, $keyword)) {
                    // A longer keyword matching is a stronger signal than a
                    // short one: "lab request" beats "report" inside it.
                    $score = max($score, mb_strlen($keyword));
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['key' => $key] + $entry;
            }
        }

        return $best;
    }

    /**
     * The entry as text for the model to answer from: it may translate and
     * shorten this, but the buttons and rules come from here.
     */
    public function brief(array $entry): string
    {
        $lines = [
            "KA-AGAPAY REFERENCE for the {$entry['title']} screen. Answer from THIS, not from memory of other systems.",
            "What it is: {$entry['summary']}",
            'Steps, in this order:',
        ];

        foreach ($entry['steps'] as $index => $step) {
            $lines[] = '  ' . ($index + 1) . '. ' . $step;
        }

        if (!empty($entry['notes'])) {
            $lines[] = 'Rules staff get caught by:';

            foreach ($entry['notes'] as $note) {
                $lines[] = '  - ' . $note;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A plain answer straight from the book, used when the model is
     * unavailable — no API key, a rate limit, or the service being down.
     * Staff still get the right steps.
     */
    public function plainAnswer(array $entry): string
    {
        $text = "{$entry['title']} — {$entry['summary']}\n";

        foreach ($entry['steps'] as $index => $step) {
            $text .= "\n" . ($index + 1) . '. ' . $step;
        }

        if (!empty($entry['notes'])) {
            $text .= "\n\nWorth knowing:";

            foreach ($entry['notes'] as $note) {
                $text .= "\n- " . $note;
            }
        }

        return $text;
    }
}
