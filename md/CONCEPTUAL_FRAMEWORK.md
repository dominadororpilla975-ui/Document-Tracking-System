# Conceptual Framework

## Purpose

The conceptual framework describes the high-level design principles, core domain concepts, and relationships that guide the Report Tracking System. It is intended to help stakeholders understand how the system organizes reports, users, departments, workflows, and audit history.

## Core Objectives

- Enable transparent tracking of citizen reports from submission to resolution
- Support role-based responsibilities across Admin, Department, Staff, and Client users
- Maintain an audit trail for every report change
- Provide a modular workflow structure that supports assignment, approval, rejection, and reassignments
- Make it easy to extend the system with new departments, workflows, and reporting rules

## Key Concepts

### Report

A report is the central entity in the system. It contains:

- `title` and `description`
- `client_id` (report originator)
- `department_id` (current assigned department)
- `status` (workflow state)
- `budget` (optional financial estimate)
- `requires_physical_pickup` and `physical_pickup_info`
- `created_at` timestamp

A report moves through the system from initial submission to final resolution.

### User

Users have roles that define permissions and behavior:

- **Admin**: full control over reports, users, departments, assignments, approvals, and reassignments
- **Department**: responsible for processing reports assigned to their department
- **Staff**: supports department workflows and internal processing
- **Client**: submits reports and tracks status

Users are associated with departments when their role is `staff` or `department`.

### Department

A department represents an organizational unit responsible for handling reports. Departments are linked to reports and users.

### Workflow Step

Each report is processed according to workflow stages. Common workflow steps include:

1. `Pending` - report submitted and awaiting review
2. `Assigned` - report has been assigned to a department
3. `Processing` - department is working on the report
4. `Approved` - report processing completed and approved
5. `Rejected` - report has been rejected with a reason
6. `Completed` - final state after approval or successful processing

The workflow may also include intermediate review or reassign steps depending on business rules.

### Audit Trail (`report_logs`)

Every significant action taken on a report is stored in an audit log. The audit trail captures:

- `report_id`
- `old_department` and `new_department`
- `updated_by`
- `action` code
- `remarks`
- `date_updated`

This ensures accountability, traceability, and historical context for decision-making.

## Relationships

- A **Client** creates one or many **Reports**.
- A **Report** may be assigned to one **Department** at a time.
- A **Department** has many **Users** with operational roles.
- A **Report** has many **Report Logs** documenting its lifecycle.

## System Components

### Frontend

The frontend provides role-specific dashboards and user experiences:

- Client interface for report submission and tracking
- Department dashboard for assigned report processing
- Admin dashboard for system oversight, assignments, approvals, and user management
- Staff pages for workflow support and department statistics

### Backend

The backend exposes RESTful endpoints to:

- create and read reports
- assign and reassign work
- approve, reject, and update report status
- manage users and departments
- generate dashboard statistics and historical views

### Database

The conceptual data model is organized around:

- `users`
- `departments`
- `reports`
- `report_logs`
- optional workflow route records for multi-step report paths

## Design Principles

- **Separation of concerns**: distinct responsibilities for users, departments, reports, and history
- **Auditability**: every state transition is logged for traceability
- **Extensibility**: workflows and roles can be extended without changing core data structures
- **User-centric transparency**: clients can track the progress of their own reports
- **Role-based control**: permissions align with each user type

## Example Workflow

1. Client submits a new report.
2. Admin reviews the report and assigns it to a department.
3. Department or staff processes the report and updates status.
4. Admin or department may approve, reject, or reassign the report.
5. The audit log records each transition.
6. The report reaches a final state such as `Approved` or `Completed`.

## Extensibility Notes

- Additional workflow stages can be introduced by adding new status values and handling them in the backend.
- Physical pickup and budget approval are modeled as optional report attributes.
- Multi-department routing can be supported with a workflow route table that records sequential routing steps.
- Reporting and dashboard metrics can be expanded by querying the same core entities.

## Summary

The conceptual framework for the Report Tracking System is built around a centralized report entity, user roles, department accountability, and a traceable workflow lifecycle. It is designed for transparency, auditability, and flexible growth.
