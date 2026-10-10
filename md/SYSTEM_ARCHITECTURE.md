# System Architecture & Organization

## Overview

This document describes the organization and structure of the Report Tracking System as a professional real-world application.

## System Architecture

### Three-Tier Architecture

```
┌─────────────────────────────────────────┐
│     Frontend (React)                    │
│ - Admin Dashboard                       │
│ - Department Dashboard                  │
│ - Client Dashboard                      │
│ - Staff Dashboard                       │
└──────────────┬──────────────────────────┘
               │ HTTP/REST
┌──────────────▼──────────────────────────┐
│     Backend (Flask API)                 │
│ - Admin Routes (report & user mgmt)     │
│ - Department Routes (workflow)          │
│ - Client Routes (submissions)           │
│ - Staff Routes (processing)             │
│ - Auth Routes (login/register)          │
└──────────────┬──────────────────────────┘
               │ SQL
┌──────────────▼──────────────────────────┐
│     Database (MySQL)                    │
│ - Departments                           │
│ - Users                                 │
│ - Reports                               │
│ - Report Logs (Audit Trail)             │
└─────────────────────────────────────────┘
```

## Backend Organization

### Module Structure

#### 1. **auth.py** - Authentication Module

- User registration
- Login with password verification
- Role discovery
- Session initialization

#### 2. **admin.py** - Administrative Module

**Report Management:**

- Fetch all reports with filtering by status/department
- Get detailed report with full history
- Assign reports to departments
- Approve/reject reports with notes
- Create audit logs for each action

**Department Management:**

- List all departments with details
- Get department statistics (staff count, pending reports)

**User Management:**

- Create new staff/department users
- Fetch users by role
- Update user assignments

#### 3. **department.py** - Department Operations

- View assigned reports
- Retrieve single report details
- Update report status (Processing, Approved, Rejected, Completed)
- Add remarks/comments
- Reassign reports to other departments
- Log all changes to report_logs

#### 4. **client.py** - Client Operations

- Submit new reports
- View personal reports
- Get report details with history
- Add feedback/comments
- Track report status

#### 5. **staff.py** - Staff Operations

- View department reports
- Get single report details
- Update report status
- Add work logs
- Get department statistics/metrics
- Filter reports by status

### Database Design

#### Schema Overview

```sql
users
├── id (PK)
├── name
├── email (UNIQUE)
├── password (hashed with bcrypt)
├── role (admin, staff, client, department)
└── department_id (FK to departments)

departments
├── id (PK)
└── name

reports
├── id (PK)
├── title
├── description
├── client_id (FK to users)
├── department_id (FK to departments, nullable)
├── status (Enum: Pending, Assigned, Under Review, Processing, Approved, Rejected, Completed)
└── created_at (timestamp)

report_logs (Audit Trail)
├── id (PK)
├── report_id (FK to reports)
├── old_department (nullable)
├── new_department (nullable)
├── updated_by (FK to users)
├── remarks (free text)
└── date_updated (timestamp)
```

## Frontend Organization

### Component Structure

```
src/
├── pages/
│   ├── AdminDashboard.js        - Full system administration
│   ├── DepartmentDashboard.js   - Department operations
│   ├── ClientDashboard.js       - Client submissions & tracking
│   ├── StaffDashboard.js        - Staff report processing
│   ├── Login.js                 - Authentication
│   ├── Register.js              - Client registration
│   └── Welcome.js               - Landing page
│
├── components/
│   ├── Navbar.js                - Navigation header
│   ├── Sidebar.js               - Role-based navigation
│   ├── ReportForm.js            - Report submission form
│   ├── ReportList.js            - Report listings
│   ├── ReportTracker.js         - Status tracking
│   └── PasswordInput.js          - Password field component
│
└── services/
    └── api.js                   - Axios API client (baseURL: http://127.0.0.1:5000)
```

### Admin Dashboard Features

**Reports Tab:**

- View all reports with status filtering
- Filter by department
- Inline department assignment dropdown
- View report details in modal
- Review and approve/reject reports

**Departments Tab:**

- List all departments
- Display department information
- Card-based layout

**Users Tab:**

- Create new users (staff/department heads)
- View all system users
- Display role badges
- Department assignment

### Interactive Workflows

#### Report Assignment Workflow

```
Admin Dashboard
    ↓
Select Report
    ↓
Choose Department from Dropdown
    ↓
Database Update: SET status='Assigned'
    ↓
Log Entry: Created in report_logs
    ↓
Frontend Refresh: Fetch latest data
```

#### Report Review Workflow

```
Admin Views Pending Report
    ↓
Clicks "Review" Button
    ↓
Modal Opens: Select Action
    ↓
Option 1: Approve → Update status to 'Approved'
Option 2: Reject → Require rejection reason
    ↓
Submit → Database Update
    ↓
Notification: Success/Error Alert
```

#### Department Processing Workflow

```
Department Staff Views Assigned Report
    ↓
Updates Status: Processing
    ↓
Adds Remarks: Work progress
    ↓
Further Update: Completed
    ↓
Report marked as Done
    ↓
Client Notified of Completion
```

## API Request/Response Patterns

### Standard Response Format

```json
Success:
{
  "message": "Operation successful",
  "data": {...}
}

Error:
{
  "message": "Error description"
}
```

### Report Object Structure

```json
{
  "id": 1,
  "title": "Road Repair Request",
  "description": "Potholes along main street",
  "client_id": 3,
  "client_name": "Juan Dela Cruz",
  "department_id": 5,
  "department_name": "Public Works Office",
  "status": "Assigned",
  "created_at": "2026-03-01T10:30:00"
}
```

### History Log Structure

```json
{
  "id": 1,
  "report_id": 1,
  "old_department": 1,
  "new_department": 5,
  "updated_by": 2,
  "updated_by_name": "Admin User",
  "remarks": "Report assigned to Public Works Office",
  "date_updated": "2026-03-01T10:30:00"
}
```

## Real-World Features Implemented

### 1. **Audit Trail**

- Complete history of every action
- Who made the change, when, and why
- Department reassignment tracking
- Status change history

### 2. **Role-Based Access Control**

- Admin: Full access to all operations
- Department: Access only to assigned reports
- Staff: View and process department reports
- Client: Only their own submitted reports

### 3. **Data Validation**

- Email uniqueness
- Password strength (minimum 6 chars)
- Required field validation
- Status value validation
- Department requirement for reports

### 4. **Error Handling**

- Graceful error messages
- Validation feedback
- Database transaction rollback on failure
- Meaningful HTTP status codes

### 5. **Scalability Considerations**

- Parameterized SQL queries (prevents injection)
- Indexed foreign keys for performance
- Pagination-ready (can be added)
- Status-based filtering for large datasets

## Security Features

### Authentication

- Bcrypt password hashing (salt rounds: 12)
- Email-based login
- Role verification on each request

### Data Protection

- SQL parameterization (prepared statements)
- CORS enabled (configurable for production)
- Unique constraints on email
- Foreign key constraints

### Recommended Enhancements for Production

- JWT token authentication
- HTTPS (SSL/TLS)
- API rate limiting
- Request validation middleware
- Comprehensive logging
- Session expiration
- Two-factor authentication

## Performance Considerations

### Database Queries

- Indexed foreign keys
- JOIN operations for combined data retrieval
- Status filtering for quick report lookup

### Frontend Optimization

- Report filtering to reduce displayed data
- Modal-based details (lazy loading)
- Bootstrap components (lightweight)

## Testing Credentials

```
Admin Account:
Email: admin@example.com
Password: admin123
Role: admin

Client Account:
Email: juan@example.com
Password: client123
Role: client

Department Account:
Email: mayor@example.com
Password: admin123
Role: department
```

## Deployment Checklist

- [ ] Update database credentials in `backend/db.py`
- [ ] Update API baseURL in `frontend/src/services/api.js`
- [ ] Enable HTTPS/SSL
- [ ] Configure CORS for production domain
- [ ] Set up environment variables
- [ ] Run database migrations
- [ ] Test all user flows
- [ ] Deploy backend (Flask/Gunicorn)
- [ ] Deploy frontend (React build)
- [ ] Set up monitoring/logging
- [ ] Configure backup strategy

## Future Enhancements

### Phase 2 Features

- Email notifications
- SMS alerts for urgent reports
- File attachment support
- Report categorization
- Advanced search

### Phase 3 Features

- Performance dashboard
- SLA tracking
- Mobile app
- API rate limiting
- Advanced analytics

---

**Architecture Designed for**: Production-Ready Real-World Application
**Last Updated**: March 1, 2026
**Current Version**: 1.0
