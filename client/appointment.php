<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);
$message = '';
$error = '';
$selectedLawyerId = lex_sanitize_int($_GET['lawyer_id'] ?? 0);

$availableLawyers = lex_recent(
     'SELECT l.id, u.full_name, l.specialization
      FROM lawyers l
      JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.status = "active"
      ORDER BY u.full_name ASC'
  );

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $lawyerId = lex_sanitize_int($_POST['lawyer_id'] ?? 0);
    $selectedLawyerId = $lawyerId;
    $scheduledAt = str_replace('T', ' ', lex_sanitize_text($_POST['scheduled_at'] ?? ''));
    $notes = lex_sanitize_text($_POST['notes'] ?? '');

    $stmt = $pdo->prepare('
        SELECT l.id
        FROM lawyers l
        JOIN users u ON u.id = l.user_id
        WHERE l.id = :lawyer_id
          AND u.is_active = 1
          AND l.status = "active"
        LIMIT 1
    ');
    $stmt->execute(['lawyer_id' => $lawyerId]);
    $lawyerExists = (int) ($stmt->fetchColumn() ?: 0);

    if ($lawyerExists && $scheduledAt !== '') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                SELECT id
                FROM cases
                WHERE client_id = :client_id
                  AND lawyer_id = :lawyer_id
                ORDER BY CASE WHEN status IN ("open", "ongoing") THEN 0 ELSE 1 END, id DESC
                LIMIT 1
            ');
            $stmt->execute([
                'client_id' => $clientId,
                'lawyer_id' => $lawyerId,
            ]);
            $caseId = (int) ($stmt->fetchColumn() ?: 0);

            if (!$caseId) {
                do {
                    $caseNumber = 'INTAKE-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
                    $stmt = $pdo->prepare('SELECT 1 FROM cases WHERE case_number = :case_number LIMIT 1');
                    $stmt->execute(['case_number' => $caseNumber]);
                } while ($stmt->fetchColumn());

                $pdo->prepare(
                    'INSERT INTO cases (case_number, title, description, lawyer_id, client_id, status, priority, filed_date, closed_date)
                     VALUES (:case_number, :title, :description, :lawyer_id, :client_id, "open", "normal", CURDATE(), NULL)'
                )->execute([
                    'case_number' => $caseNumber,
                    'title' => 'Client Intake Consultation',
                    'description' => $notes !== '' ? $notes : 'Client intake consultation created automatically from the appointment request.',
                    'lawyer_id' => $lawyerId,
                    'client_id' => $clientId,
                ]);
                $caseId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'INSERT INTO appointments (case_id, client_id, lawyer_id, scheduled_at, status, notes)
                 VALUES (:case_id, :client_id, :lawyer_id, :scheduled_at, "pending", :notes)'
            )->execute([
                'case_id' => $caseId,
                'client_id' => $clientId,
                'lawyer_id' => $lawyerId,
                'scheduled_at' => $scheduledAt,
                'notes' => $notes,
            ]);
            $appointmentId = (string) $pdo->lastInsertId();
            lex_audit('book_appointment', 'appointments', $appointmentId);
            $pdo->commit();
            $message = 'Appointment request submitted.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Client appointment booking failed: ' . $e->getMessage());
            $error = 'Unable to book the appointment. Please try again.';
        }
    } else {
        $error = $lawyerExists ? 'Select a lawyer and time.' : 'Select an available lawyer.';
    }
}

$appointments = lex_recent(
    'SELECT a.*, c.case_number, COALESCE(NULLIF(c.title, ""), "Appointment Request") AS case_title, u.full_name AS lawyer_name
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     JOIN lawyers l ON l.id = a.lawyer_id
     JOIN users u ON u.id = l.user_id
     WHERE a.client_id = :id
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at DESC',
    ['id' => $clientId]
);

lex_page_header('Appointments', 'appointments', $user);
?>
<section class="card client-appointment-card">
  <div class="card-head"><h2>Book an Appointment</h2></div>
  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
  <?php if (!empty($availableLawyers)): ?>
    <form method="post" class="form-grid client-appointment-form">
      <?= lex_csrf_field() ?>
      <label>Choose Lawyer
        <select name="lawyer_id" required>
          <option value="">Choose a lawyer</option>
          <?php foreach ($availableLawyers as $lawyer): ?>
            <?php $lawyerSpecialization = trim((string) ($lawyer['specialization'] ?? '')); ?>
            <option value="<?= (int) $lawyer['id'] ?>"<?= (int) $lawyer['id'] === $selectedLawyerId ? ' selected' : '' ?>><?= lex_e($lawyer['full_name'] . ($lawyerSpecialization !== '' ? ' - ' . $lawyerSpecialization : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($selectedLawyerId > 0): ?>
        <p class="muted full client-appointment-note">The lawyer card you chose is already selected here.</p>
      <?php endif; ?>
      <p class="muted full client-appointment-note">
        Choose any active lawyer. If you do not already have a case with them, an intake case will be created automatically.
      </p>
      <label>Scheduled at
        <input type="datetime-local" name="scheduled_at" required>
      </label>
      <label class="full">Notes
        <textarea name="notes" rows="3" placeholder="Optional notes"></textarea>
      </label>
      <button class="button button-primary" type="submit">Request Appointment</button>
    </form>
  <?php else: ?>
    <p class="muted">No active lawyers are available right now.</p>
  <?php endif; ?>
</section>

<section class="card client-appointment-requests-card">
  <div class="card-head"><h2>Appointment Requests</h2></div>
  <p class="muted client-appointment-requests-note">This page shows your appointment requests and their current status.</p>
  <div class="table-wrap">
    <table class="data-table client-appointment-requests-table">
      <thead><tr><th>Case</th><th>Lawyer</th><th>Scheduled</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($appointments as $appointment): ?>
        <tr>
          <td><?= lex_e($appointment['case_title']) ?></td>
          <td><?= lex_e($appointment['lawyer_name']) ?></td>
          <td><?= lex_e($appointment['scheduled_at']) ?></td>
          <td><span class="pill"><?= lex_e($appointment['status']) ?></span></td>
          <td><?= lex_e($appointment['notes']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php lex_page_footer(); ?>
