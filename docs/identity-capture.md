# Identity Capture & Customer Digital File

This module adds device‑aware photo/document capture, QR phone pairing, private
storage, document versioning, passport MRZ reading and an on‑demand customer
dossier to the weekly loan system.

Everything is native PHP 8 + MySQL. No framework is required.

---

## 1. Where files live

```
storage/private/
├── customers/<REFERENCE>/portrait/…        portraits added from the file tab
├── customers/<REFERENCE>/identity/…        passport, NIDA, other IDs
├── customers/<REFERENCE>/loan_documents/…  applications, guarantors, agreements
├── customers/<REFERENCE>/supporting_documents/…
├── customers/<YYYY>/<MM>/…                 portraits saved by the registration form
├── temporary/camera_sessions/<UUID>/…      phone uploads (short lived)
├── thumbnails/…                            generated previews
└── thumbnails/photos/…                     cached list/menu avatars
```

* Files are **never** inside the public web root.
* Every image is decoded with GD and re‑encoded as a fresh JPEG, which removes
  EXIF (including GPS), embedded scripts and the original filename.
* Storage names are random (`bin2hex(random_bytes(16))`), only the metadata row
  knows what a file belongs to.
* Files are served by `media.php` after authentication (or a signed, expiring
  URL for phone uploads).

Nothing in a view or API response exposes a filesystem path.

---

## 2. Permissions

```
storage/                         0755  (web user)
storage/private/                 0755  (web user, not readable by others)
```

Do **not** add `storage/` to any public alias. On Apache, the bundled `.htaccess`
rules are not required because the directory sits outside `htdocs`; keep it that
way if the project moves.

---

## 3. HTTPS (required for phone cameras)

Browsers only expose `getUserMedia()` on secure contexts:
`https://…` or `http://localhost`.

The capture page and QR link work over plain HTTP, but the phone camera will be
blocked. For office use with a LAN IP:

1. Put the app behind HTTPS (reverse proxy or a certificate on Apache).
2. Forward `X-Forwarded-Proto: https` so `CameraSession::captureUrl()` emits an
   `https://` QR link.
3. Keep `Permissions-Policy: camera=(self)` (already sent by `Security`).

The upload fallback and the desktop QR flow still work without a local camera.

---

## 4. Configuration

| Setting | Where | Default |
|---|---|---|
| `DB_SOCKET` / `DB_HOST` … | environment | XAMPP socket |
| `SESSION_FINGERPRINT_SECRET` | `config/config.php` | **change in production** |
| `TESSERACT_BIN` | environment | `tesseract` (PATH) |
| Session TTL | `CameraSession::TTL_SECONDS` | 900 s |
| Signed asset TTL | `CameraSession::ASSET_TTL` | 600 s |
| Upload ceiling | `DocumentStorage::MAX_BYTES` | 10 MB |
| Portrait edge / quality | `CustomerDocument::store()` | 1200 px / 88 |
| Document edge / quality | `CustomerDocument::store()` | 2200 px / 86 |
| Thumbnail edge | `DocumentStorage::THUMB_EDGE` | 360 px |

The secret protects signed phone‑asset URLs. Rotating it invalidates any URL
already handed to a phone; sessions themselves keep working.

---

## 5. Passport OCR (optional)

OCR is **not** required. Without Tesseract the MRZ page shows *OCR unavailable*
and the officer can paste the two MRZ lines — the parser validates ICAO 9303
check digits locally.

To enable automatic reading:

```bash
sudo apt install tesseract-ocr
# optional: tesseract-ocr-eng is installed with the base package
/opt/lampp/bin/php -r 'echo (new PassportOcrService())->status()["version"], PHP_EOL;'
```

MRZ results are advisory. A valid check digit proves only that the characters
were read consistently — never that the document is genuine. The UI requires
officer review and never overwrites typed customer details.

---

## 6. Maintenance (cron)

Run at least hourly during office hours:

```cron
*/30 * * * * /opt/lampp/bin/php /opt/lampp/htdocs/loan/tools/cleanup_camera_sessions.php --minutes=60 >> /opt/lampp/htdocs/loan/logs/cleanup.log 2>&1
```

Useful one‑off commands:

```bash
php tools/cleanup_camera_sessions.php --dry-run       # preview
php tools/cleanup_camera_sessions.php --stats         # storage usage
php tools/cleanup_camera_sessions.php --integrity     # missing/mismatched files
```

The cleanup only deletes **temporary** capture files and closed sessions.
Documents attached to saved customers are never removed by it.

---

## 7. Endpoints

| Method | URL | Purpose |
|---|---|---|
| POST | `camera_session.php` (`action=create`) | new QR capture session |
| GET | `camera_session.php?action=status&id=` | PC polling |
| POST | `camera_session.php` (`complete`/`cancel`/`renew`) | session lifecycle |
| GET | `capture.php?t=<token>` | phone capture page |
| POST | `capture.php?t=<token>` (JSON `{image}`) | phone upload |
| POST | `document_capture.php` | save a captured document (JSON) |
| GET | `media.php?doc=&size=thumb\|full` | authorized document preview |
| GET | `media.php?doc=&size=full&download=1` | authorized download |
| GET | `media.php?version=` | document version history |
| GET | `media.php?asset=&exp=&sig=` | signed temporary phone asset |

All POSTs from the PC require `X-CSRF-Token` (or `csrf_token`). The phone page is
exempt because the token authenticates the session itself, not an officer login.

`capture.php` cannot read customers, list documents or reach any other page: the
token authorises a single upload slot.

---

## 8. Data model

New tables (see `database/migrations/2026_11_16_identity_capture.sql`):

* `camera_sessions` — hashed tokens, officer, draft id, purpose, expiry, state.
* `camera_session_assets` — one latest image per session, processing state.
* `document_versions` — previous files after a replacement.
* `document_ocr_results` — OCR/MRZ attempts and validation output.

Extended columns: `customers.gender`, `customer_documents.storage_key`,
`thumbnail_key`, `mime_type`, `file_size_bytes`, `width`, `height`, `checksum`,
`version_number`, `is_primary`, `issue_date`, `expiry_date`, `ocr_status`,
`deleted_at` (soft delete).

### Upgrading an existing installation

`tools/install.php` is idempotent and now upgrades an older database in place:

* adds any missing identity-capture columns and indexes,
* creates the four new tables,
* classifies legacy `customer_documents` rows (`doc_type` → `document_category`),
* never touches existing customer, loan or payment rows.

Legacy documents that only have the `path` column are still delivered by
`media.php` as long as the file exists under `storage/private/`, `storage/uploads/`
or `uploads/`. Files outside those folders are refused.

---

## 9. Dossier

`views/customers/dossier.php` builds the printable dossier on demand:

* choose which documents to include,
* identity summary, portrait, loans and document labels/dates,
* browser print → *Save as PDF*.

Nothing is merged into a permanent PDF, so replacing a document does not leave a
stale compiled file behind. A true server‑side PDF can be added later by
implementing the same selection logic with an FPDF/TCPDF adapter.

---

## 10. Architecture notes / Laravel migration

Business rules live in services/classes, not in views:

* `CameraSession` — session lifecycle, hashing, signed URLs.
* `DocumentStorage` — validation, re‑encoding, thumbnails, paths.
* `CustomerDocument` — metadata, categories, versions, soft delete.
* `PassportOcrService` + `MrzParser` — OCR bridge and ICAO parsing.
* `DocumentCleanupService` — retention, orphans, integrity.

Controllers (`capture.php`, `camera_session.php`, `document_capture.php`,
`media.php`) only handle HTTP. Persistence uses PDO prepared statements.

To migrate to Laravel later, keep these services and replace only routing,
authentication, storage adapters and the CLI runner.
