<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();

function lex_manage_clients_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute(['table' => $table]);
    return $cache[$table] = (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_risk') {
            $clientId = lex_sanitize_int($_POST['client_id'] ?? 0);
            $riskLevel = $_POST['risk_level'] ?? 'low';
            $pdo->prepare('UPDATE clients SET risk_level = :risk_level WHERE id = :id')->execute(['risk_level' => $riskLevel, 'id' => $clientId]);
            lex_audit('update_client_risk', 'clients', (string) $clientId);
            lex_flash_set('success', 'Client risk level updated.');
            header('Location: ' . lex_app_url('admin/manage_clients.php'));
            exit;
        } elseif ($action === 'delete_client') {
            $clientId = lex_sanitize_int($_POST['client_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT c.id, c.user_id, u.full_name FROM clients c JOIN users u ON u.id = c.user_id WHERE c.id = :id LIMIT 1');
            $stmt->execute(['id' => $clientId]);
            $client = $stmt->fetch();
            if ($client) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $pdo->beginTransaction();
                try {
                    if (lex_manage_clients_table_exists($pdo, 'case_files')) {
                        $caseFiles = lex_recent('SELECT folder_name FROM case_files WHERE client_user_id = :client_user_id', ['client_user_id' => (int) $client['user_id']]);
                        foreach ($caseFiles as $caseFile) {
                            $folderName = (string) ($caseFile['folder_name'] ?? '');
                            if ($folderName !== '') {
                                lex_case_files_recursive_delete(lex_case_files_folder_path($folderName));
                            }
                        }
                    }
                    if (lex_manage_clients_table_exists($pdo, 'risk_assessments')) {
                        $pdo->prepare('DELETE FROM risk_assessments WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'appointments')) {
                        $pdo->prepare('DELETE FROM appointments WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'lawyer_reviews')) {
                        $pdo->prepare('DELETE FROM lawyer_reviews WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'case_files')) {
                        $pdo->prepare('DELETE FROM case_files WHERE client_user_id = :client_user_id_1 OR created_by_user_id = :client_user_id_2 OR updated_by_user_id = :client_user_id_3')->execute([
                            'client_user_id_1' => (int) $client['user_id'],
                            'client_user_id_2' => (int) $client['user_id'],
                            'client_user_id_3' => (int) $client['user_id'],
                        ]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'documents')) {
                        try {
                            $pdo->prepare('DELETE FROM documents WHERE case_id IN (SELECT id FROM cases WHERE client_id = :client_id)')->execute(['client_id' => $clientId]);
                        } catch (Throwable $docError) {
                            // Some local databases have a broken or partially dropped documents table; continue with the client delete.
                        }
                    }
                    $pdo->prepare('DELETE FROM cases WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    $pdo->prepare('DELETE FROM clients WHERE id = :id')->execute(['id' => $clientId]);
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) $client['user_id']]);
                    $pdo->commit();
                    lex_audit('delete_client', 'clients', (string) $clientId);
                    lex_flash_set('success', 'Client removed.');
                    header('Location: ' . lex_app_url('admin/manage_clients.php'));
                    exit;
                } catch (Throwable $inner) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $inner;
                } finally {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                }
            } else {
                lex_flash_set('error', 'Client not found.');
                header('Location: ' . lex_app_url('admin/manage_clients.php'));
                exit;
            }
        }
    } catch (Throwable $e) {
        lex_flash_set('error', 'Could not update client record.');
        header('Location: ' . lex_app_url('admin/manage_clients.php'));
        exit;
    }
}

$clients = lex_recent('SELECT c.*, u.full_name, u.email FROM clients c JOIN users u ON u.id = c.user_id ORDER BY c.id DESC');
$assessments = lex_recent('SELECT ra.*, u.full_name FROM risk_assessments ra JOIN clients c ON c.id = ra.client_id JOIN users u ON u.id = c.user_id ORDER BY ra.assessed_at DESC');

lex_page_header('Manage Clients', 'clients');
?>
<section class="card admin-client-card">
  <div class="card-head"><h2>Client Portfolio</h2></div>
  <div class="table-wrap admin-client-table-wrap">
    <table class="data-table admin-client-table">
      <thead><tr><th>Name</th><th>Risk</th><th>Phone</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($clients as $client): ?>
        <tr>
          <td><?= lex_e($client['full_name']) ?></td>
          <td><span class="pill"><?= lex_e($client['risk_level']) ?></span></td>
          <td><?= lex_e($client['contact_number']) ?></td>
          <td>
            <form method="post" class="inline-form">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="update_risk">
              <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
              <select name="risk_level" aria-label="Risk level">
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </select>
              <button class="button button-secondary" type="submit">Save</button>
              <button
                class="button button-danger"
                type="button"
                data-client-delete-open
                data-client-id="<?= (int) $client['id'] ?>"
                data-client-name="<?= lex_e((string) $client['full_name']) ?>"
              >Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card admin-client-card">
  <div class="card-head"><h2>Risk Assessments</h2></div>
  <div class="stack-list">
    <?php foreach ($assessments as $item): ?>
      <div class="stack-row">
        <div>
          <strong><?= lex_e($item['full_name']) ?></strong>
          <span><?= lex_e($item['notes']) ?></span>
        </div>
        <div class="stack-row-right">
          <span class="pill"><?= lex_e($item['level']) ?></span>
          <strong><?= (int) $item['score'] ?></strong>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="modal-overlay" id="clientDeleteModal" data-client-delete-modal aria-hidden="true">
  <div class="modal-card wide admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="clientDeleteTitle">
    <div class="modal-header">
      <div>
        <h2 id="clientDeleteTitle">Delete Client</h2>
        <p class="modal-note">Type the client name and the word DELETE to confirm permanent deletion.</p>
      </div>
      <button class="close-button" type="button" data-client-delete-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-error">
        This will delete the client account and dependent case records.
      </div>
      <form method="post" class="form-grid admin-client-delete-form" data-client-delete-form novalidate>
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="delete_client">
        <input type="hidden" name="client_id" value="">
        <label>Client name
          <input type="text" data-client-delete-name readonly>
        </label>
        <label>Type client name + DELETE
          <input type="text" data-client-delete-confirm-text autocomplete="off" spellcheck="false" placeholder="Enter client name and DELETE">
        </label>
        <p class="field-error" data-client-delete-error aria-live="polite"></p>
        <div class="action-group">
          <button class="button button-danger" type="submit" data-client-delete-submit>Delete Client</button>
          <button class="button button-secondary" type="button" data-client-delete-close>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('clientDeleteModal');
  if (!modal) return;

  const form = modal.querySelector('[data-client-delete-form]');
  const nameField = modal.querySelector('[data-client-delete-name]');
  const confirmInput = modal.querySelector('[data-client-delete-confirm-text]');
  const submitButton = modal.querySelector('[data-client-delete-submit]');
  const errorBox = modal.querySelector('[data-client-delete-error]');
  const clientIdInput = form ? form.querySelector('input[name="client_id"]') : null;
  let expectedClientName = '';

  const normalize = (value) => value.trim().replace(/\s+/g, ' ');

  const syncState = () => {
    const entered = normalize(confirmInput?.value || '');
    const required = `${expectedClientName} DELETE`.trim();
    const matches = expectedClientName !== '' && entered === required;
    if (submitButton) submitButton.disabled = !matches;
    if (errorBox) {
      errorBox.textContent = entered !== '' && !matches ? 'Type the exact client name followed by DELETE.' : '';
    }
    return matches;
  };

  const openModal = (button) => {
    expectedClientName = button.dataset.clientName || '';
    if (clientIdInput) clientIdInput.value = button.dataset.clientId || '';
    if (nameField) nameField.value = expectedClientName;
    if (confirmInput) confirmInput.value = '';
    if (errorBox) errorBox.textContent = '';
    if (submitButton) submitButton.disabled = true;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    if (confirmInput) {
      setTimeout(() => confirmInput.focus(), 50);
    }
  };

  document.querySelectorAll('[data-client-delete-open]').forEach((button) => {
    button.addEventListener('click', () => openModal(button));
  });

  modal.querySelectorAll('[data-client-delete-close]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    });
  });

  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }
  });

  if (confirmInput) {
    confirmInput.addEventListener('input', syncState);
    confirmInput.addEventListener('keyup', syncState);
    confirmInput.addEventListener('change', syncState);
  }

  if (form) {
    form.addEventListener('submit', (event) => {
      if (!syncState()) {
        event.preventDefault();
        if (errorBox && normalize(confirmInput?.value || '') === '') {
          errorBox.textContent = 'Type the client name followed by DELETE to confirm deletion.';
        }
      }
    });
  }

  if (submitButton && form) {
    submitButton.addEventListener('click', (event) => {
      if (!syncState()) {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      form.submit();
    });
  }
})();
</script>

<?php lex_page_footer(); ?>
