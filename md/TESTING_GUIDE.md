# Workflow System - Testing Guide

## 🧪 Testing the Multi-Department Approval Workflow

This guide walks you through testing the complete workflow system with sample data.

## Prerequisites

- Backend running on `http://127.0.0.1:5000`
- Frontend running on `http://localhost:3000`
- MySQL database with sample data loaded
- Database: `report_tracking.sql` (includes 50 departments, sample users, and workflows)

## 🔑 Login Credentials

### Admin Account

```
Email: admin@municipal.gov
Password: admin123
Role: admin
```

### Department Staff Accounts

All department staff use:

```
Password: dept123
```

Examples:

- `budget@municipal.gov` (Budget Director)
- `accounting@municipal.gov` (Accountant)
- `treasurer@municipal.gov` (Treasurer)
- `health@municipal.gov` (Health Department)
- etc.

### Client Accounts

```
Email: client1@gmail.com or client2@gmail.com
Password: client123
```

## 📋 Test Scenario 1: Complete Workflow with 3 Departments

### Step 1: Client Submits a Report

1. Login to `http://localhost:3000` as **client1@gmail.com** (password: `client123`)
2. Go to **Dashboard** → **Submit Report** tab
3. Fill in the form:
   - **Title**: "Budget Variance Analysis Request"
   - **Description**: "Please review Q4 budget variance for Finance Department"
   - **Department**: "Budget Office"
4. Click **Submit Report**
5. Note the Report ID (should be auto-generated)

### Step 2: Admin Assigns Multi-Department Workflow

1. Logout and login as **admin@municipal.gov** (password: `admin123`)
2. Go to **Admin Dashboard**
3. Click **Reports** tab
4. Find the report you just created (or use Report ID 1 from sample data)
5. Click "Assign Workflow" button (if available in UI) or use API:

**Option A: Via API (Postman/Terminal)**

```bash
curl -X POST http://127.0.0.1:5000/workflow/create \
  -H "Content-Type: application/json" \
  -d '{
    "report_id": 1,
    "departments": [7, 8, 5],
    "client_id": 100
  }'
```

- Department 7 = Budget Office
- Department 8 = Accounting Office
- Department 5 = Treasurer Office

Expected Response:

```json
{
  "message": "Workflow created successfully",
  "report_id": 1,
  "steps": 3
}
```

### Step 3: First Department Reviews (Budget Office)

1. Logout and login as **budget@municipal.gov** (password: `dept123`)
2. Go to **Department Dashboard**
3. Click **⏳ Pending** tab
4. You should see the report you just created
5. Click the report row to view workflow details
6. **WorkflowTracker** should show:
   - Step 1: Budget (In Progress)
   - Step 2: Accounting (Pending)
   - Step 3: Treasurer (Pending)
7. Click **✓ Approve** button
8. Optional: Add approval notes in the prompt (or leave default)

Expected Result:

- Step 1 status changes to "Approved"
- Step 2 automatically becomes "In Progress"
- Report moves to "Processing" status

### Step 4: Second Department Reviews (Accounting Office)

1. Logout and login as **accounting@municipal.gov** (password: `dept123`)
2. Go to **Department Dashboard**
3. Click **⏳ Pending** tab
4. You should now see the same report (was pending, now ready for us)
5. View workflow details
6. **WorkflowTracker** should show:
   - Step 1: Budget (✓ Approved)
   - Step 2: Accounting (In Progress)
   - Step 3: Treasurer (Pending)
7. Click **✓ Approve** button
8. Add notes: "Document verified against accounting standards"

Expected Result:

- Step 2 status changes to "Approved"
- Step 3 automatically becomes "In Progress"

### Step 5: Final Department Reviews (Treasurer)

1. Logout and login as **treasurer@municipal.gov** (password: `dept123`)
2. Go to **Department Dashboard**
3. Click **⏳ Pending** tab
4. View workflow details
5. **WorkflowTracker** should show:
   - Step 1: Budget (✓ Approved)
   - Step 2: Accounting (✓ Approved)
   - Step 3: Treasurer (In Progress)
6. Click **✓ Approve** button
7. Add notes: "Final approval granted. Document ready for processing."

Expected Result:

- Step 3 status changes to "Approved"
- Report status becomes "Completed"
- All workflow steps finished ✅

### Step 6: Client Views Final Status

1. Logout and login as **client1@gmail.com** (password: `client123`)
2. Go to **My Reports Dashboard** → **Track Reports** tab OR **Completed** tab
3. Report should show **100% complete**
4. Click **View** button
5. **WorkflowTracker** shows all steps with ✓ checkmarks

Timeline Visual:

```
✓ Budget (Approved) → ✓ Accounting (Approved) → ✓ Treasurer (Approved)
```

## 📋 Test Scenario 2: Rejection Test

### Step 1-2: Same as Scenario 1 (Submit & Assign Workflow)

### Step 3: First Department Rejects

1. Login as **budget@municipal.gov**
2. Go to Department Dashboard → Pending
3. Find the report
4. In **WorkflowTracker**, click **✗ Reject** button
5. Enter rejection reason: "Document formatting does not meet standards. Please resubmit with proper headers."

Expected Result:

- Report status changes to "Rejected"
- Report moves to completed tab
- Client sees report as "Rejected" in their dashboard
- Entire workflow stops (no advancement to next step)

### Step 4: Client Sees Rejection

1. Login as client
2. Go to Dashboard → Check **Track Reports** tab
3. Report shows "Rejected" status
4. Click **View** to see rejection reason

## 📋 Test Scenario 3: Backend API Testing

### Get Pending Reports for a Department

```bash
curl -X GET http://127.0.0.1:5000/workflow/department/7/pending
```

Returns all reports waiting for Budget Department (ID: 7)

### Get Processing Reports for a Department

```bash
curl -X GET http://127.0.0.1:5000/workflow/department/7/processing
```

Returns all reports currently being reviewed by Budget Department

### Get Completed Reports for a Department

```bash
curl -X GET http://127.0.0.1:5000/workflow/department/7/completed
```

Returns all reports approved/rejected by Budget Department

### View Complete Workflow for a Report

```bash
curl -X GET http://127.0.0.1:5000/workflow/report/1
```

Returns full workflow timeline with all steps and statuses

### Approve a Workflow Step

```bash
curl -X POST http://127.0.0.1:5000/workflow/approve/1 \
  -H "Content-Type: application/json" \
  -d '{
    "approver_id": 11,
    "notes": "Approved with comments"
  }'
```

### Reject a Workflow Step

```bash
curl -X POST http://127.0.0.1:5000/workflow/reject/1 \
  -H "Content-Type: application/json" \
  -d '{
    "approver_id": 11,
    "reason": "Does not meet compliance requirements"
  }'
```

## 🔍 Key UI Elements to Verify

### WorkflowTracker Component

- [ ] Circular step indicators show correct status
- [ ] Status colors: Gray (Pending), Blue (In Progress), Green (Approved), Red (Rejected)
- [ ] Pulsing animation on "In Progress" step
- [ ] Department name visible for each step
- [ ] Approval notes/reasons displayed
- [ ] Progress bar at bottom shows percentage

### DepartmentDashboard

- [ ] **Pending** tab shows only pending reports for department
- [ ] **Processing** tab shows only in-progress reports
- [ ] **Completed** tab shows approved/rejected reports
- [ ] Report counts update in badge
- [ ] Clicking report shows workflow details
- [ ] Approve/Reject buttons only visible on current step

### ClientDashboard

- [ ] **Track Reports** shows workflow progress bar (Step X/Y)
- [ ] Status badges show correct color
- [ ] Progress percentage calculated correctly
- [ ] **View** button opens workflow modal
- [ ] Workflow modal shows all steps with current status
- [ ] **Completed** tab shows finished reports

## 📊 Sample Data Workflow Example

From `report_tracking.sql`, there's a pre-configured workflow:

**Report 1**: "Budget Variance Analysis"

```
Workflow Routes:
- Step 1: Department 7 (Budget) → Status: In Progress
- Step 2: Department 8 (Accounting) → Status: Pending
- Step 3: Department 5 (Treasurer) → Status: Pending
```

You can test with this existing report without creating a new one.

## ⚠️ Common Issues & Troubleshooting

### Issue: Workflow not advancing to next step

**Solution**: Check `report_workflow_routes` table - ensure step N+1 exists

### Issue: Department doesn't see pending reports

**Solution**: Verify department_id matches in both `departments` and `report_workflow_routes`

### Issue: WorkflowTracker not appearing

**Solution**: Ensure WorkflowTracker.js is imported in Dashboard components

### Issue: Approve button not working

**Solution**: Check browser console for errors; verify `user_id` is in localStorage

### Issue: Backend returns 404

**Solution**: Verify Flask app registered the workflow blueprint (`app.register_blueprint(workflow)`)

## 🎯 Test Checklist

- [ ] Client can submit report
- [ ] Admin can create multi-department workflow
- [ ] First department sees pending report
- [ ] Department can approve and advance to next
- [ ] Second department receives in-progress report
- [ ] Department can reject and stop workflow
- [ ] Client sees real-time progress updates
- [ ] Progress bar shows correct percentage
- [ ] All workflow steps visible in timeline
- [ ] Approval notes saved and displayed
- [ ] Completed tab shows finished reports
- [ ] Report logs created for audit trail

## 📝 Performance Testing

Test with multiple workflows simultaneously:

1. Create 5 reports
2. Assign each to different 3-department workflows
3. Have 3 department staff approve simultaneously
4. Monitor backend response time
5. Check database for proper record creation

Expected: All operations complete within 2 seconds

## 🚀 Next Testing Phase

After verifying basic workflow:

1. Test with 5+ step workflows
2. Test bulk reassignment
3. Test concurrent approvals
4. Test with large reports (file attachments)
5. Load test: 100+ concurrent users

---

**Happy Testing!** 🎉

Document your findings in [issue tracker] and report any bugs to the development team.
