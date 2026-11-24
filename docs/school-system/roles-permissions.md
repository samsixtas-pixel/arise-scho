# School Management System — Role and Permission Matrix (Phase 1 proposal)

Legend: **Full** = manage within authorized scope; **Scoped** = assigned records only; **Read** = view; **Approve** = workflow approval; **—** = no default access. Every cell remains subject to record-level policies and workflow state.

| Capability | System Admin | Owner | Manager | Head Teacher | Academic Admin | Accountant | Class Teacher | Subject Teacher | Parent | Student | Librarian | Transport Officer |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Platform config/users/roles | Full | — | — | — | — | — | — | — | — | — | — | — |
| School profile/academic setup | Authorized support | Full | Scoped | Scoped | Full | Read | — | — | — | — | — | — |
| Student records/enrollment | — | Read | Full | Scoped | Full | Limited identity | Scoped | Scoped | Linked children | Own | Minimal | Minimal |
| Guardian verification/linking | — | Full | Full | Scoped | Full | — | — | — | Own requests/status | — | — | — |
| Staff records/assignments | — | Full | Full | Scoped | Scoped | — | Own profile | Own profile | — | — | Own profile | Own profile |
| Attendance | — | Read | Full | Full | Scoped | — | Assigned class | Assigned session | Linked children read | Own read | — | Assigned duty |
| Homework/materials | — | Read | Read | Full | Full | — | Assigned class | Assigned subject | Linked children read | Own records | — | — |
| Marks entry | — | Read | — | Approve | Full | — | Assigned assessment | Assigned subject | Published only | Published own | — | — |
| Result review/approval/publication | — | Read | — | Approve/publish | Submit/review | — | Submit assigned | Submit assigned | Published only | Published own | — | — |
| Result correction | — | Audit/read | — | Approve | Request/manage per policy | — | Request | Request | — | — | — | — |
| Fees/invoices/payments/receipts | — | Read/oversight | Scoped oversight | — | — | Full | — | — | Linked child read/pay | Own balance read | — | — |
| Refunds/adjustments/reconciliation | — | Oversight | Approve per policy | — | — | Create/reconcile; approval separation | — | Dispute own child | — | — | — |
| School-wide analytics | — | Full | Operational | Academic | Academic | Finance | Assigned classes | Assigned subjects | Linked children | Own progress | — | Transport scope |
| Announcements/communication | — | Full | Full | Full | Academic scope | Finance scope | Class scope | Class/subject scope | Reply/acknowledge | Limited | — | Transport scope |
| Audit log | Authorized support | Read | Scoped | Academic scope | Academic scope | Financial scope | Own actions | Own actions | Own requests | Own actions | Own actions | Own actions |

## Enforcement rules

- Use action permissions such as `results.marks.enter`, `results.publish`, `finance.payment.record`, `finance.refund.approve`, and `students.guardian.verify`. Role names alone never authorize a query.
- Parent access resolves active verified guardian links server-side for every child read, download, API call, and mutation. A supplied student ID or admission number never establishes the relationship.
- Teacher access requires current-year class/subject assignment and active employment. Class teacher status does not grant marks access outside assignments.
- Accountants manage finance but cannot publish results or approve academic workflows.
- Separate creation and approval for refunds/adjustments where possible; otherwise require manager/owner approval and an audit reason.
- System Admin access supports platform operation; student/finance details are not broadly visible by default. Support access is audited.
- Owner dashboards provide school-wide summaries; identifiable student detail has separate permissions.
- Demo users follow the same policies as normal users; demo status changes only labeling and local/testing lifecycle.
