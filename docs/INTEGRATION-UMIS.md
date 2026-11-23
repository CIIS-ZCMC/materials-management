# UMIS integration note (for ZCMC IT)

Status: DRAFT, 2026-11-23. Written by the MMS system developer.
Nothing in the system calls UMIS today. People come from a dummy `personnel` table (30 fake names).
The UMIS side of every row is **to be confirmed by IT**: we have not seen UMIS and have not guessed any field names.

## What we need from UMIS (read only)

| What we need | Used for | Dummy table column | UMIS side |
|---|---|---|---|
| A stable id per person | Remember who signed or inspected | `personnel.id` (returned as `id`) | To be confirmed by IT |
| Employee number | Search, show next to the name | `employee_no` | To be confirmed by IT |
| Full name | Pickers, printed forms | `name` | To be confirmed by IT |
| Position / designation | Shown in the picker | `position` | To be confirmed by IT |
| Office or department | Shown in the picker | `office` | To be confirmed by IT |
| Active or separated flag | Hide people who left | `is_active` | To be confirmed by IT |
| Who may inspect, who is an end-user, who is a property custodian | Role filter in the pickers | `is_inspector`, `is_end_user`, `is_property_custodian` | To be confirmed by IT (may need to come from a position list or a MMS-kept list) |

## Questions for IT
1. Is there an API, a database view, or only file exports? Is there a test environment?
2. Can the MMS server reach UMIS over the hospital network? How does a system log in?
3. Does UMIS know the three roles above, or must MMS keep its own list of inspectors and custodians?
4. How quickly must a new hire or a resignation show up? Read live on every search, or copy nightly?
5. Search: can UMIS search by name quickly, or must we keep a local copy?

## Where this lives in the code
- Interface: `app/Contracts/PersonnelDirectory.php` (the role names and limits are constants there).
- Today's implementation: `app/Integrations/Personnel/LocalPersonnelDirectory.php`.
- The shape the app expects: `app/Data/PersonData.php`.
- The choice between implementations: `app/Providers/AppServiceProvider.php` (Phase 13 changes one binding).
- The API for the screens: `GET /personnel/search?search=&role=inspector|end-user|property-custodian&limit=`.