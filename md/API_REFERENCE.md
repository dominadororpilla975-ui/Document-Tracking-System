# 📚 Workflow API Reference

Complete REST API documentation for the multi-department workflow system.

## Base URL

```
http://127.0.0.1:5000
```

## Authentication

All endpoints accept `user_id` in the request body or as localStorage parameter. Some endpoints require specific roles (admin, department).

## Workflow Endpoints

### 1. Get Report Workflow

Retrieve complete workflow details including all steps and their statuses.

**Endpoint:** `GET /workflow/report/<report_id>`

**Parameters:**

- `report_id` (path) - Integer, report ID

**Response:**

```json
{
  "report": {
    "id": 1,
    "title": "Budget Variance Report",
    "total_workflow_steps": 3,
    "current_workflow_step": 2,
    "status": "Processing"
  },
  "workflow_steps": [
    {
      "id": 1,
      "report_id": 1,
      "step_number": 1,
      "department_id": 7,
      "department_name": "Budget Office",
      "assigned_user_id": 11,
      "user_name": "John Budget",
      "status": "Approved",
      "approver_notes": "Budget reviewed and approved",
      "assigned_date": "2024-01-15T10:30:00",
      "completed_date": "2024-01-15T11:45:00"
    },
    {
      "id": 2,
      "report_id": 1,
      "step_number": 2,
      "department_id": 8,
      "department_name": "Accounting Office",
      "assigned_user_id": 13,
      "user_name": "Jane Accounting",
      "status": "In Progress",
      "approver_notes": null,
      "assigned_date": "2024-01-15T11:46:00",
      "completed_date": null
    },
    {
      "id": 3,
      "report_id": 1,
      "step_number": 3,
      "department_id": 5,
      "department_name": "Treasurer Office",
      "assigned_user_id": 14,
      "user_name": "Bob Treasurer",
      "status": "Pending",
      "approver_notes": null,
      "assigned_date": "2024-01-15T10:30:00",
      "completed_date": null
    }
  ]
}
```

**Status Codes:**

- `200` - Success
- `404` - Report not found
- `500` - Server error

---

### 2. Create Workflow Routes

Assign a report to a multi-department workflow. Creates sequential approval routing.

**Endpoint:** `POST /workflow/create`

**Request Body:**

```json
{
  "report_id": 1,
  "departments": [7, 8, 5],
  "client_id": 100
}
```

**Parameters:**

- `report_id` (integer) - Report to attach workflow to
- `departments` (array) - Department IDs in sequence order
- `client_id` (integer) - User ID who initiated (for logging)

**Response:**

```json
{
  "message": "Workflow created successfully",
  "report_id": 1,
  "steps": 3
}
```

**What Happens:**

1. Report status → "Assigned"
2. Creates `report_workflow_routes` entries for each department
3. Step 1 → "In Progress" (ready for first department)
4. Steps 2+ → "Pending" (waiting for previous steps)
5. `report_logs` entries created for audit trail

**Status Codes:**

- `201` - Created
- `400` - Missing required fields or empty departments array
- `500` - Server error

**Example:**

```bash
curl -X POST http://127.0.0.1:5000/workflow/create \
  -H "Content-Type: application/json" \
  -d '{
    "report_id": 5,
    "departments": [7, 8, 5],
    "client_id": 100
  }'
```

---

### 3. Approve Workflow Step

Approve current workflow step and advance to next. Auto-completes report if last step.

**Endpoint:** `POST /workflow/approve/<report_id>`

**Parameters:**

- `report_id` (path) - Report ID

**Request Body:**

```json
{
  "approver_id": 11,
  "notes": "Budget review approved. No issues found."
}
```

**Parameters:**

- `approver_id` (integer) - User ID who is approving
- `notes` (string, optional) - Approval comments

**Response:**

```json
{
  "message": "Step approved successfully",
  "next_step": 2
}
```

**What Happens:**

1. Current step → "Approved"
2. Current step → `completed_date` = NOW()
3. If more steps exist:
   - Next step → "In Progress"
   - Report → "Processing"
4. If last step:
   - Report → "Completed"
5. Log entry created with action "approved"

**Status Codes:**

- `200` - Success
- `404` - No pending steps found
- `500` - Server error

**Example:**

```bash
curl -X POST http://127.0.0.1:5000/workflow/approve/1 \
  -H "Content-Type: application/json" \
  -d '{
    "approver_id": 11,
    "notes": "First approval done"
  }'
```

---

### 4. Reject Workflow Step

Reject current workflow step and mark entire report as rejected.

**Endpoint:** `POST /workflow/reject/<report_id>`

**Parameters:**

- `report_id` (path) - Report ID

**Request Body:**

```json
{
  "approver_id": 11,
  "reason": "Document does not meet compliance requirements. Please resubmit with corrections."
}
```

**Parameters:**

- `approver_id` (integer) - User ID who is rejecting
- `reason` (string, required) - Reason for rejection

**Response:**

```json
{
  "message": "Step rejected successfully",
  "reason": "Document does not meet compliance requirements. Please resubmit with corrections."
}
```

**What Happens:**

1. Current step → "Rejected"
2. Current step → `completed_date` = NOW()
3. Report → "Rejected"
4. Workflow stops (no advancement)
5. Log entry created with action "rejected"

**Status Codes:**

- `200` - Success
- `400` - Missing reason
- `404` - No pending steps
- `500` - Server error

**Example:**

```bash
curl -X POST http://127.0.0.1:5000/workflow/reject/1 \
  -H "Content-Type: application/json" \
  -d '{
    "approver_id": 11,
    "reason": "Formatting incomplete"
  }'
```

---

### 5. Get Pending Reports for Department

Get all reports waiting for a specific department's action.

**Endpoint:** `GET /workflow/department/<department_id>/pending`

**Parameters:**

- `department_id` (path) - Department ID

**Response:**

```json
[
  {
    "id": 1,
    "title": "Budget Request",
    "description": "Q4 budget variance analysis",
    "status": "Assigned",
    "step_number": 1,
    "route_id": 10,
    "client_name": "Maria Santos",
    "department_name": "Budget Office",
    "created_at": "2024-01-15T09:00:00"
  },
  {
    "id": 2,
    "title": "Financial Report",
    "description": "Monthly financial statement",
    "status": "Processing",
    "step_number": 1,
    "route_id": 11,
    "client_name": "Juan Dela Cruz",
    "department_name": "Budget Office",
    "created_at": "2024-01-16T14:30:00"
  }
]
```

**Status Codes:**

- `200` - Success
- `500` - Server error

**Example:**

```bash
curl -X GET http://127.0.0.1:5000/workflow/department/7/pending
```

---

### 6. Get Processing Reports for Department

Get all reports currently being reviewed by a department.

**Endpoint:** `GET /workflow/department/<department_id>/processing`

**Parameters:**

- `department_id` (path) - Department ID

**Response:**

```json
[
  {
    "id": 3,
    "title": "Expense Report",
    "description": "Travel expenses reimbursement",
    "status": "Processing",
    "step_number": 2,
    "route_status": "In Progress",
    "client_name": "Ana Lopez",
    "department_name": "Accounting Office",
    "created_at": "2024-01-14T11:20:00"
  }
]
```

**Status Codes:**

- `200` - Success
- `500` - Server error

---

### 7. Get Completed Reports for Department

Get all reports approved or rejected by a department.

**Endpoint:** `GET /workflow/department/<department_id>/completed`

**Parameters:**

- `department_id` (path) - Department ID

**Response:**

```json
[
  {
    "id": 1,
    "title": "Budget Variance Analysis",
    "status": "Completed",
    "step_number": 1,
    "approver_notes": "Approved with minor adjustments needed",
    "client_name": "Maria Santos",
    "department_name": "Budget Office",
    "created_at": "2024-01-15T09:00:00",
    "completed_date": "2024-01-15T11:45:00"
  },
  {
    "id": 4,
    "title": "License Application",
    "status": "Rejected",
    "step_number": 1,
    "approver_notes": "Missing required documentation",
    "client_name": "Pedro Villanueva",
    "department_name": "Budget Office",
    "created_at": "2024-01-16T08:00:00",
    "completed_date": "2024-01-16T09:15:00"
  }
]
```

**Status Codes:**

- `200` - Success
- `500` - Server error

---

### 8. Reassign Workflow Step

Reassign a workflow step to a different staff member.

**Endpoint:** `PUT /workflow/reassign/<route_id>`

**Parameters:**

- `route_id` (path) - Workflow route ID (from report_workflow_routes table)

**Request Body:**

```json
{
  "assigned_user_id": 12,
  "reassigned_by": 1
}
```

**Parameters:**

- `assigned_user_id` (integer) - New user to assign to
- `reassigned_by` (integer) - Admin user making the change

**Response:**

```json
{
  "message": "Workflow step reassigned"
}
```

**Status Codes:**

- `200` - Success
- `400` - Missing assigned_user_id
- `500` - Server error

---

## Data Models

### Report Object

```json
{
  "id": 1,
  "title": "Document Title",
  "description": "Document description",
  "status": "Assigned|Processing|Approved|Rejected|Completed",
  "client_id": 100,
  "current_workflow_step": 1,
  "total_workflow_steps": 3,
  "created_at": "2024-01-15T09:00:00",
  "updated_at": "2024-01-15T11:45:00"
}
```

### Workflow Route Object

```json
{
  "id": 1,
  "report_id": 1,
  "step_number": 1,
  "department_id": 7,
  "assigned_user_id": 11,
  "status": "Pending|In Progress|Approved|Rejected",
  "approver_notes": "Optional notes",
  "assigned_date": "2024-01-15T10:30:00",
  "completed_date": "2024-01-15T11:45:00"
}
```

### Department Object

```json
{
  "id": 7,
  "name": "Budget Office",
  "description": "Department responsible for budget management"
}
```

---

## Status Transitions

### Normal Flow (Approval)

```
Pending → In Progress → Approved → (Next Step) or Completed
```

### Rejection Flow

```
Pending → In Progress → Rejected → (Workflow Stops)
```

### Workflow States

- **Pending** - Waiting for action from assigned department
- **In Progress** - Department is currently reviewing
- **Approved** - Step completed successfully, proceeds to next step
- **Rejected** - Step failed, entire report rejected
- **Completed** - All workflow steps finished

---

## Error Responses

### 400 Bad Request

```json
{
  "message": "report_id and departments array required"
}
```

### 404 Not Found

```json
{
  "message": "Report not found"
}
```

### 500 Server Error

```json
{
  "message": "Error: [detailed error message]"
}
```

---

## Department IDs Reference

| ID  | Department                  | Email                      |
| --- | --------------------------- | -------------------------- |
| 1   | Mayor's Office              | mayor@municipal.gov        |
| 2   | Vice Mayor's Office         | vice_mayor@municipal.gov   |
| 3   | City Administrator's Office | admin_office@municipal.gov |
| 5   | Treasurer's Office          | treasurer@municipal.gov    |
| 7   | Budget Office               | budget@municipal.gov       |
| 8   | Accounting Office           | accounting@municipal.gov   |
| 12  | Health Department           | health@municipal.gov       |
| ... | ...                         | ...                        |

(See database/report_tracking.sql for complete list of 50 departments)

---

## Integration Examples

### JavaScript/React Example

```javascript
// Create workflow
const response = await fetch("http://127.0.0.1:5000/workflow/create", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({
    report_id: reportId,
    departments: [7, 8, 5], // Budget → Accounting → Treasurer
    client_id: userId,
  }),
});

const data = await response.json();
console.log(data.message); // "Workflow created successfully"

// Approve step
const approveResponse = await fetch(
  `http://127.0.0.1:5000/workflow/approve/${reportId}`,
  {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      approver_id: userId,
      notes: "Approved",
    }),
  },
);
```

### Python Example

```python
import requests
import json

BASE_URL = 'http://127.0.0.1:5000'

# Create workflow
response = requests.post(f'{BASE_URL}/workflow/create', json={
    'report_id': 1,
    'departments': [7, 8, 5],
    'client_id': 100
})
print(response.json())

# Get workflow
response = requests.get(f'{BASE_URL}/workflow/report/1')
workflow = response.json()
print(f"Current Step: {workflow['report']['current_workflow_step']}")

# Approve step
response = requests.post(f'{BASE_URL}/workflow/approve/1', json={
    'approver_id': 11,
    'notes': 'Approved'
})
```

---

## Rate Limiting

No rate limiting currently implemented. Future versions may implement:

- 100 requests per minute per user
- 1000 requests per minute per IP

---

## Versions

**Current Version:** 1.0.0
**Release Date:** January 2024
**Last Updated:** January 2024

---

For questions or issues, refer to the main documentation or code comments.
