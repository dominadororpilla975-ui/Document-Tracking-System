# Record Management System - Implementation Roadmap

## Scope: 5 Priority Departments

1. Mayor's Office
2. Accounting Office
3. Budget Office (Municipal Budget Office)
4. Treasury Office
5. General Services Office (GSO)

## Priority Features (8 features)

1. ✅ Submit records/documents + file upload
2. ✅ Update document status (workflow)
3. ✅ Search and filter records
4. ✅ Archive/restore records
5. ✅ Audit trail logging
6. ✅ File preview + download
7. ✅ Record timeline/history view
8. ✅ Admin dashboard monitoring

## Record Statuses

- **Pending**: Submitted, awaiting review
- **Under Review**: Assigned to staff/department
- **Approved**: Approved by authorized person
- **Rejected**: Rejected with reasons
- **Released**: Ready for pickup/distribution
- **Completed**: Task finished
- **Archived**: Moved to archive (searchable, restorable)

## Record Fields (Enhanced)

```
- Record ID (auto-increment)
- Tracking Number (auto-generated, unique)
- Department (Mayor, Accounting, Budget, Treasury, GSO)
- Category (department-specific types)
- Title
- Description
- Submitted By (user)
- Date Submitted
- Status (with status history)
- Remarks/Comments (threaded)
- Attachments (multiple files, previews)
- Assigned Office/Person
- Approved By (user)
- Date Approved
- Updated At
- Archived At (null if active)
```

## Implementation Phases

### PHASE 1: Database & Core Backend (Week 1)

- [x] Existing tables: users, departments, reports
- [ ] Add `record_categories` table (per department)
- [ ] Add `record_status_history` table (track status changes)
- [ ] Add `record_archives` table (archived records)
- [ ] Add `record_comments` table (threaded remarks)
- [ ] Enhance `reports` table with archive_date, tracking_number

### PHASE 2: Backend API Endpoints (Week 1-2)

- [ ] `/api/records/submit` (POST) - Create new record
- [ ] `/api/records/{id}` (GET) - Fetch single record
- [ ] `/api/records/search` (GET) - Search/filter records
- [ ] `/api/records/{id}/status` (PUT) - Update status
- [ ] `/api/records/{id}/archive` (PUT) - Archive record
- [ ] `/api/records/{id}/restore` (PUT) - Restore from archive
- [ ] `/api/records/{id}/comments` (GET/POST) - Comments
- [ ] `/api/records/admin/dashboard` (GET) - Admin monitoring
- [ ] `/api/records/generate-tracking-number` (POST)
- [ ] `/api/records/categories/{deptId}` (GET)

### PHASE 3: Frontend - Department Submit Module (Week 2)

- [ ] Enhanced submission form (already added `DepartmentSubmitTab`)
- [ ] Category selector (by department)
- [ ] File upload with preview
- [ ] Tracking number display
- [ ] Submission confirmation

### PHASE 4: Frontend - Record Tracking Module (Week 2-3)

- [ ] My Records page (filtered by department)
- [ ] Search/filter component
- [ ] Status history timeline
- [ ] Comments section
- [ ] Download/print options
- [ ] Archive button

### PHASE 5: Frontend - Admin Monitoring Dashboard (Week 3)

- [ ] Global records overview
- [ ] Records by department (table/cards)
- [ ] Status distribution chart
- [ ] Real-time monitoring
- [ ] Archive management
- [ ] Bulk status updates

### PHASE 6: Polish & Testing (Week 3-4)

- [ ] File preview (PDF, images, documents)
- [ ] Performance optimization
- [ ] Mobile responsiveness
- [ ] Security audit
- [ ] User testing

---

## Department Categories (Quick Reference)

### Mayor's Office

- Executive Order
- Official Communication
- Memorandum
- Administrative Report
- Approval Document
- Executive Decision

### Accounting Office

- Financial Report
- Payroll Management
- Liquidation Report
- Budget Report
- Expense Tracking
- Account Reconciliation

### Budget Office

- Budget Proposal
- Budget Allocation
- Spending Report
- Budget Revision
- Financial Analysis
- Budget Monitoring

### Treasury Office

- Collection Report
- Official Receipt
- Payment Record
- Revenue Tracking
- Cash Transaction
- Bank Statement

### General Services Office

- Inventory Report
- Supply Request
- Property Record
- Asset Tracking
- Supply Distribution
- Maintenance Report

---

## Database Migrations Needed

```sql
-- 1. Add tracking_number and archive_date to reports
ALTER TABLE reports ADD COLUMN tracking_number VARCHAR(50) UNIQUE;
ALTER TABLE reports ADD COLUMN archive_date TIMESTAMP NULL;
ALTER TABLE reports ADD COLUMN category VARCHAR(100);

-- 2. Create categories table
CREATE TABLE record_categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  department_id INT,
  name VARCHAR(100),
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- 3. Create status history table
CREATE TABLE record_status_history (
  id INT PRIMARY KEY AUTO_INCREMENT,
  report_id INT,
  old_status VARCHAR(50),
  new_status VARCHAR(50),
  changed_by INT,
  reason TEXT,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (report_id) REFERENCES reports(id),
  FOREIGN KEY (changed_by) REFERENCES users(id)
);

-- 4. Create comments table
CREATE TABLE record_comments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  report_id INT,
  user_id INT,
  comment TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (report_id) REFERENCES reports(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 5. Create archive table
CREATE TABLE archived_records (
  id INT PRIMARY KEY AUTO_INCREMENT,
  report_id INT,
  archived_by INT,
  archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  restore_date TIMESTAMP NULL,
  reason TEXT,
  FOREIGN KEY (report_id) REFERENCES reports(id),
  FOREIGN KEY (archived_by) REFERENCES users(id)
);
```

---

## API Response Structure

### Record Object

```json
{
  "id": 1,
  "tracking_number": "TR-2026-0001",
  "department_id": 2,
  "department_name": "Accounting Office",
  "category": "Financial Report",
  "title": "Q1 2026 Financial Report",
  "description": "...",
  "submitted_by": "John Doe",
  "submitted_by_id": 5,
  "date_submitted": "2026-05-24T10:00:00Z",
  "status": "Under Review",
  "current_status_since": "2026-05-24T10:05:00Z",
  "approved_by": "Jane Smith",
  "approved_by_id": 3,
  "date_approved": "2026-05-24T15:00:00Z",
  "attachments": [
    {
      "id": 1,
      "filename": "report.pdf",
      "size": 2048576,
      "type": "application/pdf",
      "url": "/api/files/1",
      "preview_url": "/api/files/1/preview"
    }
  ],
  "status_history": [
    {
      "old_status": "Pending",
      "new_status": "Under Review",
      "changed_by": "Admin User",
      "changed_at": "2026-05-24T10:05:00Z"
    }
  ],
  "comments": [
    {
      "id": 1,
      "user": "Jane Smith",
      "text": "Needs revision",
      "created_at": "2026-05-24T11:00:00Z"
    }
  ],
  "is_archived": false,
  "archived_at": null
}
```

---

## Development Strategy

1. **Backend First**: Create DB migrations + API endpoints
2. **Frontend Integration**: Wire up new tabs/pages
3. **Admin Dashboard**: Central monitoring
4. **Testing**: Iterate based on feedback
5. **Polish**: UI refinements, performance

---

## Next Steps

1. Create database migrations
2. Build record submission API
3. Integrate with existing department dashboard
4. Add admin monitoring dashboard
