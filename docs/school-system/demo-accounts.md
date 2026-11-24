# School Management System — Demo Accounts

All accounts below are **active** in the `school_management` database.

- **Shared initial password:** `kali4290`
- Every account is flagged `must_change_password`, so the UI prompts each user to set a private password after signing in.
- Change these passwords before using the system with real school data.

## Part A — School roles

| Username | Name | Role |
|---|---|---|
| `dejene` | Dejene | School Owner |
| `shock` | Shock | School Manager (Bursar) |
| `headmaster` | Head Master | Head Teacher |
| `secondmaster` | Second Master | Second Master / Deputy Headmaster |
| `academic` | Academic Master | Academic Administrator |
| `tutu` | Tutu | Accountant |
| `classteacher` | Class Teacher | Class Teacher |
| `mmari` | Mmari | Subject Teacher |
| `discipline` | Discipline Master | Discipline Master |
| `sports` | Sports Master | Sports Master |
| `patron` | Patron | Patron |
| `matron` | Matron | Matron |
| `secretary` | Secretary | Secretary |
| `admissions` | Admissions Officer | Admissions Officer / Registrar |
| `schooladmin` | School Administrator | School Administrator |
| `nurse` | School Nurse | School Nurse |
| `librarian` | Librarian | Librarian |
| `storekeeper` | Storekeeper | Storekeeper |
| `transport` | Transport Officer | Transport Officer |
| `doreen` | Doreen | Parent / Guardian |
| `student1` | Student Account | Student |

## Part B — Finance and accounting roles

| Username | Name | Role |
|---|---|---|
| `sysadmin` | System Administrator | System Administrator |
| `companyowner` | Company Owner | Company Owner |
| `financedirector` | Finance Director | Finance Director |
| `chiefaccountant` | Chief Accountant | Chief Accountant |
| `senioraccountant` | Senior Accountant | Senior Accountant |
| `generalaccountant` | General Accountant | General Accountant |
| `arofficer` | Accounts Receivable Officer | Accounts Receivable Officer |
| `apofficer` | Accounts Payable Officer | Accounts Payable Officer |
| `payroll` | Payroll Officer | Payroll Officer |
| `taxaccountant` | Tax Accountant | Tax Accountant |
| `cashier` | Cashier | Cashier |
| `procurement` | Procurement Officer | Procurement Officer |
| `internalauditor` | Internal Auditor | Internal Auditor |
| `externalauditor` | External Auditor | External Auditor |
| `departmentmanager` | Department Manager | Department Manager |
| `readonlymanager` | Read-only Management User | Read-only Management User |

## Maintenance commands

```bash
# List every account and role
php tools/school.php list-accounts

# Re-provision missing accounts (existing accounts are refreshed, not duplicated)
php tools/school.php provision-accounts
```
