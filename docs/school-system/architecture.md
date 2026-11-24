# School Management System — Phase 1 Architecture Plan

## Status

This is the design package requested by the product brief. It defines an implementation direction; it does not claim that school workflows or integrations already exist. Application module work starts after review of this plan.

## Existing application assessment and UI reuse

The supplied app is a native PHP/MySQL system with direct PHP page handlers, shared layout includes, PDO, class-based data access, and Composer autoloading. It is not yet MVC: `views/` contains route-like pages with form handling, and `classes/` mixes models, services, and infrastructure. Introduce an `app/` boundary incrementally while keeping single-school deployment straightforward.

Reuse the loan system's visual language and components:

- Shared header/footer concepts: dark sidebar, topbar, responsive content shell, page headings, operational tables, filters, and role-specific navigation.
- Existing bundled Inter font, Bootstrap CSS/JS, Bootstrap Icons, and SweetAlert2. Preserve icon family and confirmation/modal behavior.
- Existing tokens and components from `assets/css/style.css` and `assets/css/lms.css`; add a school palette override (navy/gold) while retaining geometry, spacing, borders, shadows, typography, icon boxes, and popup-card treatment. Do not alter the loan app as part of this design phase.
- The PWA shell is a candidate for the future parent portal, subject to offline/privacy review.
- PhpSpreadsheet is available for spreadsheet imports/exports; it does not provide PDF generation.

PDF generation should sit behind a `ReportRenderer` interface. Select Dompdf only after checking compatibility with the deployment PHP baseline; otherwise choose a compatible maintained renderer. Report card snapshots preserve source data and branding independently of rendered files. QR codes use an opaque verification token and reveal no marks or personal details. The bundled `qrcode.js` is browser-side; server-side report QR generation needs a maintained PHP package or controlled image generation.

## Proposed architecture

- `public/`: document root, front controller, static assets, upload/download entrypoints.
- `routes/web.php`, `routes/api.php`: route definitions and middleware binding.
- `app/Core/`: request, response, router, container, config, transaction boundary, exception handling.
- `app/Controllers/`: HTTP input/output only.
- `app/Models/`: domain entities/value objects where useful; avoid Active Record coupling for critical workflows.
- `app/Repositories/`, `app/Interfaces/`: PDO persistence contracts and implementations.
- `app/Services/`: academic, enrollment, attendance, finance, payment, reporting, notification, and analytics rules.
- `app/DTOs/`, `Validators/`, `Policies/`, `Middleware/`, `Events/`, `Exceptions/`.
- `resources/views/`, `resources/components/`, `resources/layouts/`: PHP templates using the loan app's visual patterns.
- `database/migrations/`, `database/seeders/`: versioned schema and local demo data.
- `storage/private/`, `storage/reports/`, `storage/logs/`, `storage/temporary/`: outside public web root.
- `tests/`: unit, integration, feature, and security coverage.

Request flow: route → authentication and CSRF middleware → role and record policy → controller validation/DTO → service transaction → repository/PDO → response/view. Analytics uses the same authorization path and aggregate SQL repositories; views do not calculate business metrics.

## Core design decisions

1. Single-school deployment initially; no SaaS tenancy or tenant switching.
2. IDs are never sufficient authorization. Parent access requires an active verified guardian link; teacher access requires active assignment; finance and publication require explicit permissions and workflow state.
3. Store TZS money as `DECIMAL(14,2)`. Payments and allocations are append-oriented; adjustments, refunds, reversals, and corrections retain actor, reason, timestamp, and prior state.
4. Academic periods, grading scales, weights, and result versions are preserved. Published results are immutable; corrections create a new version and audit entry.
5. Use `Africa/Dar_es_Salaam` at the application boundary and UTC timestamps in persistence where practical; convert for display and period boundaries.
6. Payment providers are adapters behind `PaymentGateway`. No live integration is claimed until provider documentation, credentials, callback validation, settlement terms, and end-to-end tests are verified. A fake gateway is labeled and enabled only in local/testing.
7. Notifications record delivery attempts and evidence; attempted is not delivered without provider confirmation.
8. Private files live outside document root, are MIME/type/size validated, and are served only through authorized controllers.
9. Demo users and records are created only when `APP_ENV` is `local` or `testing`; passwords are hashed and demo identities visibly labeled.
10. Audit events cover sensitive workflows without logging passwords, tokens, medical details, or payment secrets.

## Module boundaries

Identity/access; school configuration; students/guardians; staff/assignments; academic assessment and results; attendance and learning; finance; parent/student portals; aggregate intelligence; documents/reports; integrations. Each module owns service rules and repository contracts. Analytics does not expose personally identifying chart data by default.

## Phase 1 exit criteria

After review, implementation should provide the foundation, migrations, authentication, server-side RBAC and record policies, local/testing-only five-account demo seeder, and role-specific dashboard shells. A navigation link or screen alone does not make a module complete.
