<?php
/**
 * Customer.php
 * -------------------------------------------------------
 * Customer registration, search, assignment and the
 * completeness rules that keep the loan book clean.
 * -------------------------------------------------------
 */

class Customer extends Model
{
    protected function getTable(): string
    {
        return 'customers';
    }

    /** Next sequential reference (CUST-0001, CUST-0002, ...). */
    public function nextReference(): string
    {
        $max = (int) $this->db
            ->query("SELECT COALESCE(MAX(CAST(SUBSTRING(reference, 6) AS UNSIGNED)), 0) FROM customers")
            ->fetchColumn();

        return 'CUST-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Register a new customer.
     *
     * @throws ValidationException
     */
    public function create(array $data, ?string $photoDataUrl = null, ?array $photoFile = null): int
    {
        $errors = $this->validate($data, true);

        if ($errors) {
            throw new ValidationException($errors);
        }

        $photoPath = PhotoStorage::store($photoDataUrl, $photoFile);

        if ($photoPath === null) {
            throw new ValidationException([t('A passport photo is required. Use the camera to capture it.')]);
        }

        $first  = $this->titleCase($data['first_name']);
        $middle = $this->titleCase($data['middle_name'] ?? '');
        $last   = $this->titleCase($data['last_name']);
        $full   = trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last) ?? '');

        try {
            $this->db->beginTransaction();

            $reference = $this->nextReference();

            $stmt = $this->db->prepare(
                "INSERT INTO customers
                    (reference, first_name, middle_name, last_name, full_name, gender, nida, phone, phone_alt,
                     photo_path, emergency_contact, emergency_phone, notes, data_status, status,
                     registered_by, assigned_officer_id, registered_at)
                 VALUES
                    (:reference, :first_name, :middle_name, :last_name, :full_name, :gender, :nida, :phone, :phone_alt,
                     :photo_path, :emergency_contact, :emergency_phone, :notes, 'complete', 'active',
                     :registered_by, :officer_id, NOW())"
            );

            $stmt->execute([
                'reference'         => $reference,
                'first_name'        => $first,
                'middle_name'       => $middle,
                'last_name'         => $last,
                'full_name'         => $full,
                'gender'            => $this->normaliseGender($data['gender'] ?? null),
                'nida'              => preg_replace('/\s+/', '', (string) $data['nida']),
                'phone'             => normalisePhone((string) $data['phone']),
                'phone_alt'         => !empty($data['phone_alt']) ? normalisePhone((string) $data['phone_alt']) : null,
                'photo_path'        => $photoPath,
                'emergency_contact' => $data['emergency_contact'] ?: null,
                'emergency_phone'   => !empty($data['emergency_phone']) ? normalisePhone((string) $data['emergency_phone']) : null,
                'notes'             => $data['notes'] ?: null,
                'registered_by'     => currentUserId() ?: null,
                'officer_id'        => !empty($data['assigned_officer_id']) ? (int) $data['assigned_officer_id'] : (currentUserId() ?: null),
            ]);

            $id = (int) $this->lastInsertId();

            $this->assign($id, (int) ($data['assigned_officer_id'] ?: currentUserId()));

            AuditLog::record('customer.create', 'customers', $id,
                'Registered ' . $reference . ' — ' . $full);

            $this->db->commit();

            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            PhotoStorage::delete($photoPath);
            throw $e;
        }
    }

    /**
     * Update an existing customer. Existing records that were imported
     * incomplete can be saved without forcing every field.
     *
     * @throws ValidationException
     */
    public function update(int $id, array $data, ?string $photoDataUrl = null, ?array $photoFile = null): void
    {
        $customer = $this->find($id);

        if (!$customer) {
            throw new RuntimeException('Customer not found.');
        }

        // Keep the row id in the payload so the NIDA uniqueness check
        // excludes this customer instead of flagging its own number.
        $data['id'] = $id;

        $errors = $this->validate($data, false);

        if ($errors) {
            throw new ValidationException($errors);
        }

        $photoPath = PhotoStorage::store($photoDataUrl, $photoFile, 'customers');
        $oldPhoto  = $customer['photo_path'];
        $removePhoto = !empty($data['remove_photo']);
        $finalPhoto  = $photoPath ?? ($removePhoto ? null : $oldPhoto);

        $first  = $this->titleCase($data['first_name'] ?? $customer['first_name']);
        $middle = $this->titleCase($data['middle_name'] ?? $customer['middle_name']);
        $last   = $this->titleCase($data['last_name'] ?? $customer['last_name']);
        $full   = trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last) ?? '');

        $complete = $this->isComplete([
            'first_name' => $first,
            'middle_name'=> $middle,
            'last_name'  => $last,
            'nida'       => $data['nida'] ?? $customer['nida'],
            'phone'      => $data['phone'] ?? $customer['phone'],
            'photo_path' => $finalPhoto,
        ]);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "UPDATE customers SET
                    first_name = :first_name,
                    middle_name = :middle_name,
                    last_name = :last_name,
                    full_name = :full_name,
                    gender = :gender,
                    nida = :nida,
                    phone = :phone,
                    phone_alt = :phone_alt,
                    photo_path = :photo_path,
                    emergency_contact = :emergency_contact,
                    emergency_phone = :emergency_phone,
                    notes = :notes,
                    data_status = :data_status,
                    status = :status,
                    assigned_officer_id = :officer_id
                 WHERE id = :id"
            );

            $stmt->execute([
                'first_name'        => $first,
                'middle_name'       => $middle,
                'last_name'         => $last,
                'full_name'         => $full,
                'gender'            => $this->normaliseGender($data['gender'] ?? ($customer['gender'] ?? null)),
                'nida'              => !empty($data['nida']) ? preg_replace('/\s+/', '', (string) $data['nida']) : null,
                'phone'             => !empty($data['phone']) ? normalisePhone((string) $data['phone']) : null,
                'phone_alt'         => !empty($data['phone_alt']) ? normalisePhone((string) $data['phone_alt']) : null,
                'photo_path'        => $finalPhoto,
                'emergency_contact' => $data['emergency_contact'] ?: null,
                'emergency_phone'   => !empty($data['emergency_phone']) ? normalisePhone((string) $data['emergency_phone']) : null,
                'notes'             => $data['notes'] ?: null,
                'data_status'       => $complete ? 'complete' : 'incomplete',
                'status'            => in_array($data['status'] ?? 'active', ['active', 'inactive', 'blacklisted'], true)
                                            ? $data['status'] : 'active',
                'officer_id'        => !empty($data['assigned_officer_id']) ? (int) $data['assigned_officer_id'] : null,
                'id'                => $id,
            ]);

            if (!empty($data['assigned_officer_id'])) {
                $this->assign($id, (int) $data['assigned_officer_id']);
            }

            AuditLog::record('customer.update', 'customers', $id, 'Updated ' . $customer['reference'] . ' — ' . $full);

            $this->db->commit();

            if ($oldPhoto !== null && $oldPhoto !== $finalPhoto) {
                PhotoStorage::delete($oldPhoto);
            }
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            PhotoStorage::delete($photoPath);
            throw $e;
        }
    }

    /** @return string[] */
    private function validate(array $data, bool $strict): array
    {
        $errors = [];

        $first = trim((string) ($data['first_name'] ?? ''));
        $middle = trim((string) ($data['middle_name'] ?? ''));
        $last  = trim((string) ($data['last_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $nida  = preg_replace('/\s+/', '', (string) ($data['nida'] ?? '')) ?? '';
        $gender = $this->normaliseGender($data['gender'] ?? null);

        if ($strict) {
            if ($first === '')  $errors[] = t('First name is required.');
            if ($middle === '') $errors[] = t('Middle name is required.');
            if ($last === '')   $errors[] = t('Last name is required.');
            if ($phone === '')  $errors[] = t('Phone number is required.');
            if ($nida === '')   $errors[] = t('NIDA number is required.');
        }

        if ($gender === null && trim((string) ($data['gender'] ?? '')) !== '') {
            $errors[] = t('Select a valid gender.');
        } elseif ($strict && $gender === null) {
            $errors[] = t('Gender is required.');
        }

        foreach (['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last] as $label => $value) {
            if (mb_strlen($value) > 80) {
                $errors[] = t('Each name must be 80 characters or fewer.');
                break;
            }
            if ($value !== '' && !preg_match('/^[\p{L}\p{M}\'\-\. ]+$/u', $value)) {
                $errors[] = t('Names may only contain letters, spaces, hyphens and apostrophes.');
                break;
            }
        }

        if ($phone !== '' && !isValidTzPhone($phone)) {
            $errors[] = t('Enter a valid Tanzanian phone number, e.g. 0744 909 605.');
        }

        if ($nida !== '') {
            if (!preg_match('/^[0-9]{20}$/', $nida)) {
                $errors[] = t('A NIDA number must be exactly 20 digits.');
            } elseif ($this->nidaExists($nida, isset($data['id']) ? (int) $data['id'] : null)) {
                $errors[] = t('That NIDA number is already registered to another customer.');
            }
        }

        if (!empty($data['phone_alt']) && !isValidTzPhone((string) $data['phone_alt'])) {
            $errors[] = t('The alternate phone number is not valid.');
        }

        if (!empty($data['emergency_phone']) && !isValidTzPhone((string) $data['emergency_phone'])) {
            $errors[] = t('The emergency contact number is not valid.');
        }

        return $errors;
    }

    public function nidaExists(string $nida, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) FROM customers WHERE nida = :nida AND deleted_at IS NULL";
        $params = ['nida' => $nida];

        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function isComplete(array $data): bool
    {
        return trim((string) $data['first_name']) !== ''
            && trim((string) $data['middle_name']) !== ''
            && trim((string) $data['last_name']) !== ''
            && preg_match('/^[0-9]{20}$/', (string) $data['nida']) === 1
            && isValidTzPhone((string) $data['phone'])
            && !empty($data['photo_path']);
    }

    /** Accept male/female/other, ignore blanks, reject anything else. */
    private function normaliseGender($value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        return in_array($value, ['male', 'female', 'other'], true) ? $value : null;
    }

    private function titleCase(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (function_exists('mb_convert_case')) {
            return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords(strtolower($value));
    }

    /** Assign a customer to an officer (closes the previous assignment). */
    public function assign(int $customerId, int $officerId): void
    {
        if ($officerId <= 0) {
            return;
        }

        $current = $this->db->prepare(
            "SELECT officer_id FROM customer_assignments
              WHERE customer_id = :c AND released_at IS NULL
              ORDER BY id DESC LIMIT 1"
        );
        $current->execute(['c' => $customerId]);
        $existing = $current->fetchColumn();

        if ((int) $existing === $officerId) {
            return;
        }

        $this->db->prepare(
            "UPDATE customer_assignments SET released_at = NOW()
              WHERE customer_id = :c AND released_at IS NULL"
        )->execute(['c' => $customerId]);

        $this->db->prepare(
            "INSERT INTO customer_assignments (customer_id, officer_id, assigned_by)
             VALUES (:c, :o, :b)"
        )->execute(['c' => $customerId, 'o' => $officerId, 'b' => currentUserId() ?: null]);

        $this->db->prepare(
            "UPDATE customers SET assigned_officer_id = :o WHERE id = :c"
        )->execute(['o' => $officerId, 'c' => $customerId]);
    }

    /** Customer with officer name and borrowing totals. */
    public function findDetailed(int $id): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT c.*,
                    u.full_name AS officer_name,
                    (SELECT COUNT(*) FROM loans l
                      WHERE l.customer_id = c.id AND l.deleted_at IS NULL) AS loan_count,
                    (SELECT COALESCE(SUM(v.remaining_balance), 0)
                       FROM v_loans_with_status v
                      WHERE v.customer_id = c.id
                        AND v.loan_status NOT IN ('PAID','WRITTEN OFF')) AS outstanding,
                    (SELECT COALESCE(SUM(v.amount_paid), 0)
                       FROM v_loans_with_status v
                      WHERE v.customer_id = c.id) AS total_repaid,
                    (SELECT MIN(l.loan_date) FROM loans l
                      WHERE l.customer_id = c.id AND l.deleted_at IS NULL) AS first_loan_date
               FROM customers c
          LEFT JOIN users u ON u.id = c.assigned_officer_id
              WHERE c.id = :id AND c.deleted_at IS NULL
              LIMIT 1"
        );

        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    /** Fast typeahead search. */
    public function search(string $q, int $limit = 10): array
    {
        $like = '%' . $q . '%';

        $stmt = $this->db->prepare(
            "SELECT id, reference, full_name, phone, photo_path, data_status
               FROM customers
              WHERE deleted_at IS NULL
                AND (full_name LIKE :q1 OR phone LIKE :q2 OR reference LIKE :q3 OR nida LIKE :q4)
              ORDER BY full_name
              LIMIT " . max(1, min(50, $limit))
        );

        $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);

        return $stmt->fetchAll();
    }

    /**
     * Paginated customer list.
     *
     * @param array $filters q, status, officer, data_status, has_loan
     * @return array{rows: array, total: int, pages: int, page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ["c.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = "(c.full_name LIKE :q1 OR c.phone LIKE :q2 OR c.reference LIKE :q3 OR c.nida LIKE :q4)";
            $like = '%' . $filters['q'] . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
        }

        if (!empty($filters['status'])) {
            $where[] = "c.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['data_status'])) {
            $where[] = "c.data_status = :data_status";
            $params['data_status'] = $filters['data_status'];
        }

        if (!empty($filters['officer'])) {
            $where[] = "c.assigned_officer_id = :officer";
            $params['officer'] = (int) $filters['officer'];
        }

        if (!empty($filters['has_loan'])) {
            $where[] = "EXISTS (SELECT 1 FROM loans l WHERE l.customer_id = c.id AND l.deleted_at IS NULL)";
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page   = max(1, $page);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT c.*,
                       u.full_name AS officer_name,
                       (SELECT COUNT(*) FROM loans l
                         WHERE l.customer_id = c.id AND l.deleted_at IS NULL) AS loan_count,
                       (SELECT COALESCE(SUM(v.remaining_balance), 0)
                          FROM v_loans_with_status v
                         WHERE v.customer_id = c.id
                           AND v.loan_status NOT IN ('PAID','WRITTEN OFF')) AS outstanding,
                       (SELECT COUNT(*) FROM v_overdue_loans o WHERE o.customer_id = c.id) AS overdue_count
                  FROM customers c
             LEFT JOIN users u ON u.id = c.assigned_officer_id
                 WHERE {$whereSql}
              ORDER BY c.id DESC
                 LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        ];
    }

    /** All active customers (for dropdowns). */
    public function selectable(): array
    {
        return $this->db->query(
            "SELECT id, reference, full_name, phone
               FROM customers
              WHERE deleted_at IS NULL AND status = 'active'
              ORDER BY full_name"
        )->fetchAll();
    }

    public function softDelete(int $id): void
    {
        $customer = $this->find($id);

        if (!$customer) {
            return;
        }

        $openLoans = (int) $this->db->query(
            "SELECT COUNT(*) FROM v_loans_with_status
              WHERE customer_id = {$id} AND loan_status NOT IN ('PAID','WRITTEN OFF')"
        )->fetchColumn();

        if ($openLoans > 0) {
            throw new RuntimeException(t('This customer still has an open loan and cannot be deleted.'));
        }

        $this->db->prepare("UPDATE customers SET deleted_at = NOW(), status = 'inactive' WHERE id = :id")
                 ->execute(['id' => $id]);

        AuditLog::record('customer.delete', 'customers', $id,
            'Archived ' . $customer['reference'] . ' — ' . $customer['full_name']);
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'inactive', 'blacklisted'], true)) {
            throw new RuntimeException('Invalid status.');
        }

        $this->db->prepare("UPDATE customers SET status = :s WHERE id = :id")
                 ->execute(['s' => $status, 'id' => $id]);

        AuditLog::record('customer.status', 'customers', $id, 'Status set to ' . $status);
    }

    public function countIncomplete(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM customers WHERE data_status = 'incomplete' AND deleted_at IS NULL"
        )->fetchColumn();
    }
}
