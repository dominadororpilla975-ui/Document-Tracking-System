# 🚀 Quick Start Guide - Multi-Department Report Tracking System

## System Overview

This is a complete municipal government report tracking system with **multi-department sequential approval workflows**. Citizens submit documents that flow through multiple departments for approval, similar to e-commerce order fulfillment but for government paperwork.

## 📂 Project Structure

```
report-tracking-system/
├── backend/
│   ├── main.py              # Flask app entry point
│   ├── db.py                # Database connection
│   ├── routes/
│   │   ├── auth.py          # Login/Register
│   │   ├── admin.py         # Admin operations
│   │   ├── client.py        # Client endpoints
│   │   ├── department.py    # Department operations
│   │   ├── staff.py         # Staff operations
│   │   └── workflow.py      # ✨ NEW: Workflow engine
│   └── __pycache__/
├── frontend/
│   ├── src/
│   │   ├── components/
│   │   │   ├── Navbar.js
│   │   │   ├── Sidebar.js
│   │   │   └── WorkflowTracker.js  # ✨ NEW: Workflow visualization
│   │   ├── pages/
│   │   │   ├── AdminDashboard.js
│   │   │   ├── ClientDashboard.js  # ✨ Enhanced
│   │   │   └── DepartmentDashboard.js  # ✨ Enhanced
│   │   ├── styles/
│   │   │   └── WorkflowTracker.css  # ✨ NEW: Workflow styling
│   │   └── services/
│   │       └── api.js
│   ├── package.json
│   └── RUN_FRONTEND.bat
├── database/
│   └── report_tracking.sql  # ✨ Enhanced with workflow tables
├── WORKFLOW_IMPLEMENTATION.md  # ✨ NEW: Complete documentation
└── TESTING_GUIDE.md  # ✨ NEW: Testing procedures
```

## 🔧 Setup Instructions

### 1. Database Setup

1. Open MySQL client or MySQL Workbench
2. Create database:

   ```sql
   CREATE DATABASE report_tracking;
   USE report_tracking;
   ```

3. Import the schema and sample data:

   ```bash
   mysql -u root -p report_tracking < database/report_tracking.sql
   ```

4. Verify tables created:
   ```sql
   SHOW TABLES;  -- Should show: departments, users, reports, report_logs, report_workflow_routes
   ```

### 2. Backend Setup

1. Navigate to backend directory:

   ```bash
   cd backend
   ```

2. Install Python dependencies:

   ```bash
   pip install flask flask-cors mysql-connector-python bcrypt
   ```

3. Update DB connection in `db.py` if needed:

   ```python
   db = mysql.connector.connect(
       host="localhost",
       user="root",
       password="your_password",  # Update this
       database="report_tracking"
   )
   ```

4. Start backend server:

   ```bash
   python main.py
   ```

   Expected output:

   ```
    * Running on http://127.0.0.1:5000
    * Debug mode: on
   ```

### 3. Frontend Setup

1. Navigate to frontend directory:

   ```bash
   cd frontend
   ```

2. Install dependencies:

   ```bash
   npm install
   ```

3. Start development server:

   ```bash
   npm start
   ```

   Expected output:

   ```
   Compiled successfully!
   Local: http://localhost:3000
   ```

4. OR use the batch file (Windows):
   ```bash
   RUN_FRONTEND.bat
   ```

## 🎯 First-Time Testing

### Overview of Credentials

| Role            | Email                    | Password  |
| --------------- | ------------------------ | --------- |
| Admin           | admin@municipal.gov      | admin123  |
| Budget Director | budget@municipal.gov     | dept123   |
| Accountant      | accounting@municipal.gov | dept123   |
| Treasurer       | treasurer@municipal.gov  | dept123   |
| Client 1        | client1@gmail.com        | client123 |
| Client 2        | client2@gmail.com        | client123 |

(18 department staff accounts total - see database/report_tracking.sql)

### Test Flow: 3-Department Workflow

1. **Client Submits Report**
   - Open `http://localhost:3000`
   - Login: `client1@gmail.com` / `client123`
   - Go to Dashboard → Submit tab
   - Fill form and submit → Note Report ID

2. **Admin Assigns Workflow**
   - Logout and login as Admin: `admin@municipal.gov` / `admin123`
   - Go to Admin Dashboard → Reports
   - Find your report and view details
   - Use API to create workflow (see TESTING_GUIDE.md)

   ```bash
   curl -X POST http://127.0.0.1:5000/workflow/create \
     -H "Content-Type: application/json" \
     -d '{"report_id": 1, "departments": [7, 8, 5], "client_id": 100}'
   ```

3. **Department Reviews Workflow**
   - Logout and login as: `budget@municipal.gov` / `dept123`
   - Go to Department Dashboard → Pending tab
   - Click report to view WorkflowTracker
   - Click **✓ Approve** to advance

4. **Next Department Reviews**
   - Logout and login as: `accounting@municipal.gov` / `dept123`
   - Same process - approve to advance

5. **Final Department Completes**
   - Logout and login as: `treasurer@municipal.gov` / `dept123`
   - Approve final step
   - Report marked as "Completed"

6. **Client Sees Progress**
   - Logout and login as client again
   - Dashboard shows: ✓ Budget → ✓ Accounting → ✓ Treasurer
   - Report status: Completed (100%)

## 📊 System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    React Frontend (Port 3000)                │
│  ClientDashboard | DepartmentDashboard | AdminDashboard     │
│  + WorkflowTracker Component                                 │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP/JSON
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                  Flask Backend (Port 5000)                   │
│  routes: auth | admin | client | department | workflow      │
│  Workflow Engine: Create/Approve/Reject/Reassign            │
└──────────────────────┬──────────────────────────────────────┘
                       │ SQL
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                   MySQL Database                             │
│  Tables: users | departments | reports | workflow_routes    │
│          report_logs | (50 dept, 100+ sample data)          │
└─────────────────────────────────────────────────────────────┘
```

## 🔑 Key Features Implemented

### ✅ Backend Workflow Engine (`routes/workflow.py`)

- Create multi-department workflows
- Automatic step advancement
- Approval/rejection with audit trail
- Department-specific report queries
- Workflow reassignment

### ✅ Frontend Components

- **WorkflowTracker**: Timeline visualization of workflow steps
- **DepartmentDashboard**: Multi-tab interface (Pending/Processing/Completed)
- **ClientDashboard**: Real-time progress monitoring with workflow modal

### ✅ Database Support

- 50 municipal departments
- 18 department staff accounts
- Complete audit trail via report_logs
- Multi-step workflow routing

## 🐛 Troubleshooting

### Backend won't start

- Check Python version: `python --version` (3.7+)
- Install dependencies: `pip install flask flask-cors mysql-connector-python bcrypt`
- Verify MySQL is running and accessible

### Frontend won't compile

- Delete `node_modules` and `package-lock.json`
- Run `npm install` again
- Check Node version: `node --version` (12+)

### Can't connect to database

- Verify MySQL credentials in `backend/db.py`
- Check database exists: `mysql -u root -p -e "SHOW DATABASES;"`
- Verify tables exist: `use report_tracking; SHOW TABLES;`

### Workflow not advancing

- Check backend console for errors
- Verify `report_id` exists in `reports` table
- Verify `department_id` exists in `departments` table
- Check `report_workflow_routes` has entries for all steps

### Port already in use

- Backend (5000): `lsof -i :5000` then `kill -9 <PID>`
- Frontend (3000): `lsof -i :3000` then `kill -9 <PID>`

## 📖 Documentation

- **[WORKFLOW_IMPLEMENTATION.md](WORKFLOW_IMPLEMENTATION.md)** - Complete system architecture & API reference
- **[TESTING_GUIDE.md](TESTING_GUIDE.md)** - Step-by-step testing procedures
- **Sample Data** - `database/report_tracking.sql` includes 4 pre-configured workflows

## 🎨 UI Tour

### Admin Dashboard

- **Reports Tab**: View all reports, assign workflows
- **Departments Tab**: Manage 50 municipal departments
- **Users Tab**: Create and manage user accounts

### Department Dashboard

- **⏳ Pending**: Reports waiting for this department
- **🔄 Processing**: Reports currently being reviewed
- **✓ Completed**: Approved/rejected reports with notes
- Click any report to view detailed workflow timeline

### Client Dashboard

- **➕ Submit**: Submit new documents
- **📍 Track**: Monitor progress through departments
- **✓ Completed**: View approved documents
- Real-time progress bars show: Step X/Y

## 🚀 Next Steps

1. **Read** WORKFLOW_IMPLEMENTATION.md for complete API documentation
2. **Test** using TESTING_GUIDE.md procedures
3. **Customize** dashboard colors/branding as needed
4. **Deploy** to production server when ready

## 💡 Example Use Cases

1. **Budget Approval**: Budget → Accounting → Treasurer
2. **License Application**: Planning → Public Works → Finance → Mayor
3. **Construction Permit**: Engineering → Fire Safety → Environmental → Zoning
4. **Social Benefits**: Social Services → Finance → Program Director → Mayor
5. **Infrastructure Request**: Engineering → Maintenance → Budget → Mayor

---

**System Ready to Use!** 🎉

For detailed testing procedures, see [TESTING_GUIDE.md](TESTING_GUIDE.md)

Questions? Check the documentation files or review the code comments.
