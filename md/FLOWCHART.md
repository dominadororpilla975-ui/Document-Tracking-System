# Report Tracking System - Client Tracking Flowchart

## Overview

The Report Tracking System allows **Clients to track their reports step by step**, showing which office/department is handling their papers at every stage.

---

## Client Tracking Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    CLIENT CAN TRACK:                                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ✓ View their submitted reports                                              │
│  ✓ See which office/department is handling their report                       │
│  ✓ See the current status                                                   │
│  ✓ View workflow progress (Step 1-6)                                       │
│  ✓ View timeline/history of all actions                                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Status Tracking Examples

### When Client Submits Report:

```
┌─────────────────────────────────────────────┐
│ Status: Submitted - Waiting for Admin Review │
│ Department: (None yet)                       │
│ Step: 1 - Report Submitted                  │
└─────────────────────────────────────────────┘
```

### When Admin Assigns to Department:

```
┌─────────────────────────────────────────────┐
│ Status: Assigned to Social Welfare Office   │
│ Department: Social Welfare Office           │
│ Step: 3 - Assigned to Department            │
└─────────────────────────────────────────────┘
```

### While Department Processes:

```
┌─────────────────────────────────────────────┐
│ Status: Processing at Engineering Office   │
│ Department: Engineering Office               │
│ Step: 4 - Being Processed                   │
└─────────────────────────────────────────────┘
```

### When Completed (Awaiting Approval):

```
┌─────────────────────────────────────────────┐
│ Status: Completed - Pending Approval        │
│ Department: Mayor's Office                   │
│ Step: 5 - Processing Complete               │
└─────────────────────────────────────────────┘
```

### When Fully Approved:

```
┌─────────────────────────────────────────────┐
│ Status: Approved by Mayor's Office ✅        │
│ Department: Mayor's Office                   │
│ Step: 6 - Fully Approved                  │
└─────────────────────────────────────────────┘
```

---

## Complete Workflow Flowchart

```mermaid
flowchart TB
    START([🟢 START])

    C1[Client Logs In]
    C2[Client Creates Report]
    C3[Client Submits]

    S1[System: Status = "Submitted - Waiting for Admin Review"]
    S1a[System: Step 1 - Report Submitted]

    A1[Admin Reviews Report]
    A2{Is Valid?}
    A3[Admin Rejects]
    A4[Admin Assigns to Department]

    S2[System Notifies Client of Rejection]
    S3[System: "Assigned to [Office Name]"]
    S3a[System: Step 3 - Department Assigned]

    D1[Department Processes]
    D2[Department Updates to Processing]
    D3[Department Updates to Completed]

    S4[System: "Processing at [Office Name]"]
    S5[System: "Completed - Pending Approval"]

    A5[Admin Reviews & Approves]
    S6[System: "Approved by [Office Name] ✅"]

    C4[Client Views Report Status]
    C5[Client Sees Department & Progress]
    C6[Client Views Timeline/History]

    END([🟢 END])

    %% Connections
    START --> C1
    C1 --> C2
    C2 --> C3
    C3 --> S1
    S1 --> S1a
    S1a --> A1

    A1 --> A2
    A2 -->|NO| A3
    A3 --> S2
    S2 --> C4

    A2 -->|YES| A4
    A4 --> S3
    S3 --> S3a
    S3a --> D1

    D1 --> D2
    D2 --> S4
    S4 --> D3
    D3 --> S5
    S5 --> A5

    A5 --> S6
    S6 --> C4

    C4 --> C5
    C5 --> C6
    C6 --> END

    %% Styling
    classDef client fill:#e3f2fd,stroke:#1976d2,stroke-width:2px;
    classDef system fill:#e8f5e9,stroke:#388e3c,stroke-width:2px;
    classDef admin fill:#fff3e0,stroke:#f57c00,stroke-width:2px;
    classDef department fill:#f3e5f5,stroke:#7b1fa2,stroke-width:2px;
    classDef startend fill:#ffebee,stroke:#c62828,stroke-width:3px;
    classDef decision fill:#fff9c4,stroke:#f9a825,stroke-width:2px;

    class C1,C2,C3,C4,C5,C6 client;
    class S1,S1a,S2,S3,S3a,S4,S5,S6 system;
    class A1,A2,A3,A4,A5 admin;
    class D1,D2,D3 department;
    class START,END startend;
    class A2 decision;
```

---

## Detailed Process

### Step 1: Client Submits Report

```
1. Client logs in
2. Client creates and submits report
3. System saves with:
   - Status: "Submitted - Waiting for Admin Review"
   - Department: None
   - Step: 1 - Report Submitted
4. Report appears in Admin dashboard
```

### Step 2: Admin Review

```
1. Admin reviews the report
2. Decision: Valid?

   NO → Admin rejects → Client notified

   YES → Admin assigns to appropriate department
```

### Step 3: Assigned to Department

```
1. Admin selects department (e.g., Social Welfare Office)
2. System updates:
   - Status: "Assigned to Social Welfare Office"
   - Department: Social Welfare Office
   - Step: 3 - Assigned to Department
3. Department sees report in their dashboard
```

### Step 4: Processing

```
1. Department processes the report
2. Department updates status to "Processing"
3. System updates:
   - Status: "Processing at Social Welfare Office"
   - Step: 4 - Being Processed
4. Client can see it's being processed
```

### Step 5: Completed

```
1. Department completes work
2. Department updates to "Completed"
3. System updates:
   - Status: "Completed - Pending Approval"
   - Step: 5 - Processing Complete
4. Goes back to Admin for final approval
```

### Step 6: Approved

```
1. Admin reviews completed report
2. Admin approves
3. System updates:
   - Status: "Approved by [Office Name]"
   - Step: 6 - Fully Approved ✅
4. Client notified of final approval
```

---

## Client Dashboard Display

### Report List Shows:

| Column     | Example Value                    |
| ---------- | -------------------------------- |
| Title      | Road Repair Request              |
| Status     | Processing at Engineering Office |
| Department | Engineering Office               |
| Step       | Step 4: Being Processed          |
| Submitted  | 2026-03-15                       |

### Report Details Shows:

```
Report Title: Road Repair Request
─────────────────────────────────────────
Current Status: Processing at Engineering Office
Current Step: Step 4: Being Processed
Assigned Office: Engineering Office
─────────────────────────────────────────

Timeline/History:
─────────────────────────────────────────
• Step 1: Report Submitted - 2026-03-15 10:00 AM
• Step 2: Admin Review Completed - 2026-03-15 02:00 PM
• Step 3: Assigned to Engineering Office - 2026-03-15 02:30 PM
• Step 4: Processing started - 2026-03-16 09:00 AM
```

---

## Client Tracking API Response

The client tracking API now returns enhanced information:

```json
{
  "report": {
    "id": 1,
    "title": "Road Repair Request",
    "status": "Processing",
    "department_name": "Engineering Office",
    "current_workflow_step": 4,
    "status_display": "Processing at Engineering Office",
    "workflow_step_display": "Step 4: Being Processed"
  },
  "updates": [
    {
      "action": "step1_submitted",
      "action_display": "Report submitted - Waiting for Admin Review",
      "date_updated": "2026-03-15 10:00 AM"
    },
    {
      "action": "step3_assigned",
      "action_display": "Assigned to Engineering Office",
      "department_name": "Engineering Office",
      "date_updated": "2026-03-15 02:30 PM"
    }
  ],
  "tracking_info": {
    "current_status": "Processing at Engineering Office",
    "current_step": "Step 4: Being Processed",
    "handling_department": "Engineering Office",
    "timeline_count": 4
  }
}
```

---

## Summary

| Status Display                       | Department    | Step | Description        |
| ------------------------------------ | ------------- | ---- | ------------------ |
| Submitted - Waiting for Admin Review | None          | 1    | Just submitted     |
| Under Admin Review                   | None          | 2    | Admin is reviewing |
| Assigned to [Office]                 | [Office Name] | 3    | Sent to department |
| Processing at [Office]               | [Office Name] | 4    | Department working |
| Completed - Pending Approval         | [Office Name] | 5    | Awaiting final OK  |
| Approved by [Office] ✅              | [Office Name] | 6    | Fully complete     |

**Client can always see:**

- ✅ Current status with office name
- ✅ Which department is handling
- ✅ Workflow step progress
- ✅ Full timeline history

---

_Last Updated: Enhanced Client Tracking with Office/Department Names_
