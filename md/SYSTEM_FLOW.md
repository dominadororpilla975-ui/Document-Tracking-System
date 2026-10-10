# Report Tracking System - System Flow (Alon ng Sistema)

## 📌 Buod ng Sistema (System Overview)
Ang **Report Tracking System** ay isang web-based application na ginagamit upang i-track at pamahalaan ang mga ulat/dokumento mula sa pagpasa (submission) ng Kliyente (Client), pag-review ng Admin, pagproseso sa iba't ibang Departamento (Department/Office), hanggang sa huling pag-apruba at pag-archive.

---

## 👥 Mga Role sa Sistema (System Roles)

1. **Client / Public User**:
   - Nagrerehistro/nag-lo-login sa system.
   - Nagpasa ng bagong report / dokumento (`submit_report.php`).
   - Nagta-track ng status at kinaroroonang departamento ng kanilang report (`track.php`, `documents.php`).
   - Nakakatanggap ng email notification at system notifications.

2. **System Administrator (Admin)**:
   - Nag-aaproba ng mga bagong nakarehistrong account (`account_pending_approval.php`).
   - Nagre-review ng mga natanggap na bagong ulat (`admin_approvals.php`).
   - Nag-a-assign ng ulat sa tamang Departamento (`admin_routing.php`).
   - Nagpapatakbo ng pamamahala ng mga User, Roles, at Departamento (`manage_users.php`, `role_management.php`, `department_management.php`).
   - Nag-a-archive at nagpapanatili ng audit logs (`admin_archive.php`, `admin_audit_logs.php`).

3. **Department Staff / Office Evaluator**:
   - Tumatanggap ng mga ulat na in-assign sa kanilang departamento.
   - Nagbabago ng status (hal. *Under Review / Processing*, *Completed*, *Needs Revision*).
   - Nag-a-attach ng karagdagang ulat, remarks, o revision files (`upload_revision.php`).
   - Nagpapadala ng receiving copy o itinuturo sa susunod na opisina (`receiving_copy.php`).

---

## 🔄 Status Lifecycle ng Dokumento (Document Status Flow)

```
[ 1. Draft / Submitted ] ──► [ 2. Admin Review ] ──► [ 3. Assigned to Department ]
                                   │                              │
                                   ▼                              ▼
                             (Rejected)                 [ 4. Processing / Under Review ]
                                                                  │
                                                                  ▼
                             [ 6. Approved / Archived ] ◄── [ 5. Completed / Pending Final Approval ]
```

| Hakbang (Step) | Status | Paglalarawan (Description) |
|---|---|---|
| **Step 1** | `Submitted` | Naipasa na ng Kliyente ang ulat. Naghihintay ng Paunang Pagsusuri ng Admin. |
| **Step 2** | `Under Admin Review` | Sinusuri ng Admin ang katumpakan at pagkumpleto ng mga kinakailangang dokumento. |
| **Step 3** | `Assigned / Routed` | Inilipat na ng Admin ang dokumento sa nakatalagang Departamento (hal. Engineering, Social Welfare, Mayor's Office). |
| **Step 4** | `Processing` | Aktibong pinoproseso ng staff ng Departamento ang ulat. |
| **Step 5** | `Completed - Pending Final Approval` | Tapos na ang pagproseso sa Departamento at naghihintay na lang ng huling pag-apruba. |
| **Step 6** | `Approved / Archived` | Ganap nang naaprubahan at inimbak sa Archival System (`admin_archive.php`). |
| **Alternative**| `Rejected / Revision Needed` | Ibinalik sa Kliyente para sa karagdagang kailangan o tinanggihan dahil sa kakulangan. |

---

## 📊 System Flowchart (Mermaid Diagram)

```mermaid
flowchart TD
    START([🟢 Simula]) --> REG[User Registration / Login]
    REG --> CHECK_ROLE{Anong Uri ng User?}

    %% CLIENT FLOW
    CHECK_ROLE -->|Client| C1[Magpasa ng Bagong Report / Document]
    C1 --> C2[Ilagay ang Detalye at Mag-upload ng Attachments]
    C2 --> C3[System: I-save bilang 'Submitted' & Mag-generate ng Tracking Code]
    C3 --> C4[Makatanaw sa Client Dashboard & Track Page]

    %% ADMIN FLOW
    C3 --> A1[Admin: Notifications & Admin Approvals Page]
    CHECK_ROLE -->|Admin| A1
    A1 --> A2{Suriin ang Report: Tama ba?}
    A2 -->|Hindi Valid| A_REJ[Admin Rejects / Requests Revision]
    A_REJ --> NOTIF_REJ[System: Magpadala ng Email & Notification sa Client]
    
    A2 -->|Valid| A3[Admin: Route / Assign sa Target Department]
    A3 --> S_ASSIGN[System: Status = 'Assigned to [Department]']

    %% DEPARTMENT FLOW
    S_ASSIGN --> D1[Department Staff: Natanggap ang Document]
    CHECK_ROLE -->|Department Staff| D1
    D1 --> D2[I-update ang Status sa 'Processing']
    D2 --> D3{May Kailangang Rebisyon?}
    D3 -->|May Rebisyon| D_REV[Mag-upload ng Revision / Mag-iwan ng Remarks]
    D_REV --> C4
    D3 -->|Kumpletado| D4[I-update sa 'Completed - Pending Final Approval']

    %% FINAL APPROVAL & ARCHIVE
    D4 --> A4[Admin / Final Approver: Final Review]
    A4 --> A5{Final Approval?}
    A5 -->|Aprubado| FIN[Status = 'Approved' & Auto Archive]
    A5 -->|Hindi Aprubado| A_REJ

    FIN --> AUDIT[System: Mag-record sa Audit Trail Logs]
    AUDIT --> END_NODE([🔴 Katapusan])
```

---

## 📋 Detalyadong Proseso ng Bawat Modyul (Detailed Module Breakdown)

### 1. Pagpaparehistro at Authentikasyon (Authentication Flow)
- Magpaparehistro ang user gamit ang `register.php`.
- Ipapadala ang verification email (`verify_email.php`).
- Para sa mga may kailangang permiso, aaprubahan ng Admin ang account (`account_pending_approval.php`).

### 2. Pagpasa ng Ulat (Submission Flow)
- Sa `submit_report.php`, pumipili ang client ng kategorya, naglalagay ng pamagat, paglalarawan, at nag-u-upload ng kaugnay na attachment.
- Awtomatikong nagkakaroon ng **Tracking Number** para sa mabilis na pagsubaybay (`track.php`).

### 3. Pagruruta at Pag-aasayn (Routing & Assignment Flow)
- Sa `admin_routing.php` at `admin_approvals.php`, makikita ng Admin ang listahan ng mga bagong ulat.
- Iniaatas ng Admin ang dokumento sa partikular na departamento batay sa uri ng serbisyo (`service_management.php`).

### 4. Pagproseso sa Opisina at Kinaroroonan (Department Processing & Tracking)
- Nakikita ng bawat opisina ang kanilang nakatakdang trabaho sa `documents.php` o `receiving_copy.php`.
- Bawat kilos (receive, process, forward, complete) ay naitatala sa `admin_audit_logs.php` para sa transparency at seguridad.

### 5. Pag-archive at Pag-uulat (Archiving & Reports)
- Ang mga natapos na ulat ay inililipat sa `admin_archive.php`.
- Ang Admin ay nakakagawa ng pangkalahatang uulat sa `admin_reports.php` para sa mga estatistika.

---

## 📄 Mga Kaugnay na File sa System Architecture
- 📄 [`md/FLOWCHART.md`](file:///c:/xampp/htdocs/report-tracking-system/md/FLOWCHART.md) - Karagdagang tsart ng pagsubaybay ng kliyente.
- 📄 [`md/SYSTEM_ARCHITECTURE.md`](file:///c:/xampp/htdocs/report-tracking-system/md/SYSTEM_ARCHITECTURE.md) - Teknikal na arkitektura ng backend at database.
- 📄 [`md/WORKFLOW_IMPLEMENTATION.md`](file:///c:/xampp/htdocs/report-tracking-system/md/WORKFLOW_IMPLEMENTATION.md) - Teknikal na dokumentasyon ng mga API at estado ng workflow.

---

## 🛡️ Mga Mekanismo ng Seguridad (System Security Features)

1. **Session & Cookie Hardening**:
   - Ginamitan ng `HttpOnly`, `SameSite=Lax`, at `session.use_strict_mode = 1` sa [`includes/auth.php`](file:///c:/xampp/htdocs/report-tracking-system/includes/auth.php) upang maiwasan ang **Session Hijacking** at **Session Fixation**.

2. **CSRF Protection (Cross-Site Request Forgery)**:
   - Gumagamit ng cryptographically secure tokens (`random_bytes(32)`) at timing-attack safe comparison (`hash_equals()`) para sa lahat ng POST requests.

3. **SQL Injection Prevention**:
   - 100% PDO Prepared Statements sa mga database query para sa ligtas na pagproseso ng input.

4. **Password Hashing**:
   - BCRYPT Hashing (`password_hash()` at `password_verify()`) para sa pag-imbak ng mga password ng mga user.

5. **XSS Protection (Cross-Site Scripting)**:
   - Sanitization ng lahat ng dynamic inputs/outputs gamit ang `htmlspecialchars()`.

6. **Role-Based Access Control (RBAC)**:
   - Paghihiwalay ng permiso para sa Admin, Department Staff, at Client gamit ang `requireRole()` at `hasRole()`.

7. **Audit Logging**:
   - Pagtatala ng mahahalagang aktibidad sa system (`config/audit_log.php` at `admin_audit_logs.php`) kasama ang User ID, Action, at IP address.

8. **Secure File Access & Downloads**:
   - Ang pag-download ng attachments (`download_attachment.php`) ay dumadaan sa authentication at permission check bago ibigay ang file.

