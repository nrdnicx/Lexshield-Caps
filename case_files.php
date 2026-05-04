<?php
require_once __DIR__ . '/config/bootstrap.php';

lex_case_files_table_ensure();
lex_case_file_vault_table_ensure();

function lex_case_files_seed_from_cases(PDO $pdo): void
{
    $seedCount = lex_stats('SELECT COUNT(*) FROM case_files');
    if ($seedCount > 0) {
        return;
    }

    $seedCases = lex_recent(
        'SELECT c.case_number, c.title, c.description, c.status, c.filed_date,
                cu.user_id AS client_user_id, cuu.full_name AS full_name,
                lu.user_id AS lawyer_user_id
         FROM cases c
         JOIN clients cu ON cu.id = c.client_id
         JOIN users cuu ON cuu.id = cu.user_id
         JOIN lawyers lu ON lu.id = c.lawyer_id
         ORDER BY c.id ASC'
    );

    foreach ($seedCases as $seed) {
        $fullName = (string) $seed['full_name'];
        $caseTitle = (string) $seed['title'];
        $caseNumber = (string) $seed['case_number'];
        $folderName = lex_case_files_folder_name($fullName, $caseTitle, $caseNumber);
        $pdo->prepare(
            'INSERT INTO case_files
                (full_name, case_identifier, case_file_title, description, date_created, client_user_id, assigned_lawyer_user_id, status, folder_name, attachments_json, created_by_user_id, updated_by_user_id)
             VALUES
                (:full_name, :case_identifier, :case_file_title, :description, :date_created, :client_user_id, :assigned_lawyer_user_id, :status, :folder_name, :attachments_json, :created_by_user_id, :updated_by_user_id)'
        )->execute([
            'full_name' => $fullName,
            'case_identifier' => $caseNumber,
            'case_file_title' => $caseTitle,
            'description' => (string) $seed['description'],
            'date_created' => (string) $seed['filed_date'],
            'client_user_id' => (int) $seed['client_user_id'],
            'assigned_lawyer_user_id' => (int) $seed['lawyer_user_id'],
            'status' => in_array((string) $seed['status'], ['open', 'ongoing', 'closed'], true) ? $seed['status'] : 'open',
            'folder_name' => $folderName,
            'attachments_json' => '[]',
            'created_by_user_id' => (int) $seed['lawyer_user_id'],
            'updated_by_user_id' => (int) $seed['lawyer_user_id'],
        ]);

        $seedId = (int) $pdo->lastInsertId();
        $record = lex_recent(
            'SELECT cf.*, lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name, cu.email AS client_email, cb.full_name AS created_by_name, ub.full_name AS updated_by_name
             FROM case_files cf
             JOIN users cu ON cu.id = cf.client_user_id
             LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
             LEFT JOIN users cb ON cb.id = cf.created_by_user_id
             LEFT JOIN users ub ON ub.id = cf.updated_by_user_id
             WHERE cf.id = :id LIMIT 1',
            ['id' => $seedId]
        )[0] ?? null;

        if ($record) {
            lex_case_files_sync_record($record);
        }
    }
}

function lex_case_files_status_label(string $status): string
{
    return match ($status) {
        'open' => 'Open',
        'ongoing' => 'Ongoing',
        'closed' => 'Closed',
        default => ucfirst($status),
    };
}

function lex_case_files_status_class(string $status): string
{
    return match ($status) {
        'open' => 'is-open',
        'ongoing' => 'is-ongoing',
        'closed' => 'is-closed',
        default => 'is-default',
    };
}

function lex_case_files_highlight(string $value, string $query): string
{
    $safeValue = lex_e($value);
    $safeQuery = trim(lex_e($query));
    if ($safeQuery === '') {
        return $safeValue;
    }

    $pattern = '/' . preg_quote($safeQuery, '/') . '/i';
    return (string) preg_replace($pattern, '<mark>$0</mark>', $safeValue);
}

function lex_case_files_parse_attachments(?string $json): array
{
    $attachments = json_decode((string) $json, true);
    return is_array($attachments) ? $attachments : [];
}

function lex_case_files_format_size(int $size): string
{
    if ($size <= 0) {
        return '0 KB';
    }

    if ($size < 1024) {
        return $size . ' B';
    }

    if ($size < 1024 * 1024) {
        return round($size / 1024) . ' KB';
    }

    return round($size / 1024 / 1024, 1) . ' MB';
}

function lex_case_files_build_filters(array $filters, array &$params): string
{
    $where = [];

    if (!empty($filters['is_client'])) {
        $where[] = 'cf.client_user_id = :current_client_user_id';
        $params['current_client_user_id'] = (int) $filters['current_client_user_id'];
    } elseif (!empty($filters['is_lawyer'])) {
        $where[] = '(cf.created_by_user_id = :current_lawyer_user_id_created OR cf.assigned_lawyer_user_id = :current_lawyer_user_id_assigned)';
        $params['current_lawyer_user_id_created'] = (int) $filters['current_lawyer_user_id'];
        $params['current_lawyer_user_id_assigned'] = (int) $filters['current_lawyer_user_id'];
    }

    if (!empty($filters['search'])) {
        $where[] = '(cf.full_name LIKE :search1 OR cf.case_file_title LIKE :search2 OR cf.case_identifier LIKE :search3 OR cu.full_name LIKE :search4)';
        $searchValue = '%' . $filters['search'] . '%';
        $params['search1'] = $searchValue;
        $params['search2'] = $searchValue;
        $params['search3'] = $searchValue;
        $params['search4'] = $searchValue;
    }

    if (!empty($filters['status']) && in_array($filters['status'], ['open', 'ongoing', 'closed'], true)) {
        $where[] = 'cf.status = :status';
        $params['status'] = $filters['status'];
    }

    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

function lex_case_files_fetch_state(array $filters, int $pageSize): array
{
    $pdo = lex_pdo();
    $sortMap = [
        'updated_at' => 'cf.updated_at',
        'date_created' => 'cf.date_created',
        'full_name' => 'cf.full_name',
        'case_file_title' => 'cf.case_file_title',
        'status' => 'cf.status',
    ];
    $sortColumn = $sortMap[$filters['sort']] ?? 'cf.updated_at';
    $sortDirection = $filters['dir'] === 'asc' ? 'ASC' : 'DESC';

    $params = [];
    $where = lex_case_files_build_filters($filters, $params);

    $baseSelect = ' FROM case_files cf
        JOIN users cu ON cu.id = cf.client_user_id
        LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
        LEFT JOIN users cb ON cb.id = cf.created_by_user_id
        LEFT JOIN users ub ON ub.id = cf.updated_by_user_id';

    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseSelect . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pageCount = max(1, (int) ceil(max(1, $total) / max(1, $pageSize)));
    $page = min(max(1, $filters['page']), $pageCount);
    $offset = ($page - 1) * $pageSize;

    $listParams = $params;
    $listSql = 'SELECT cf.*, cu.full_name AS client_name, cu.email AS client_email, lu.full_name AS assigned_lawyer_name,
                       cb.full_name AS created_by_name, ub.full_name AS updated_by_name'
        . $baseSelect
        . $where
        . ' ORDER BY ' . $sortColumn . ' ' . $sortDirection
        . ' LIMIT ' . (int) $pageSize
        . ' OFFSET ' . (int) $offset;
    $records = lex_recent($listSql, $listParams);

    $selectedRecord = null;
    $selectedError = '';
    if (!empty($filters['record'])) {
        $selectedParams = $params;
        $selectedParams['record_id'] = (int) $filters['record'];
        $selectedWhere = $where . ($where ? ' AND ' : ' WHERE ') . 'cf.id = :record_id';
        $selectedStmt = $pdo->prepare('SELECT cf.*, cu.full_name AS client_name, cu.email AS client_email, lu.full_name AS assigned_lawyer_name,
                                               cb.full_name AS created_by_name, ub.full_name AS updated_by_name'
            . $baseSelect
            . $selectedWhere
            . ' LIMIT 1');
        $selectedStmt->execute($selectedParams);
        $selectedRecord = $selectedStmt->fetch() ?: null;
        if (!$selectedRecord) {
            $selectedError = 'Case file not found.';
        }
    }

    if (!$selectedRecord && !empty($records[0])) {
        $selectedRecord = $records[0];
    }

    $activityStmt = $pdo->prepare('SELECT cf.id, cf.full_name, cf.case_file_title, cf.status, cf.updated_at, cf.date_created,
                                          lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name
                                   ' . $baseSelect . $where . '
                                   ORDER BY cf.updated_at DESC
                                   LIMIT 5');
    $activityStmt->execute($params);
    $activity = $activityStmt->fetchAll();

    $countsStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN cf.status = "open" THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN cf.status = "ongoing" THEN 1 ELSE 0 END) AS ongoing_count,
            SUM(CASE WHEN cf.status = "closed" THEN 1 ELSE 0 END) AS closed_count
         ' . $baseSelect . $where
    );
    $countsStmt->execute($params);
    $counts = $countsStmt->fetch() ?: ['open_count' => 0, 'ongoing_count' => 0, 'closed_count' => 0];

    return [
        'records' => $records,
        'selected' => $selectedRecord,
        'selected_error' => $selectedError,
        'activity' => $activity,
        'counts' => [
            'total' => $total,
            'open' => (int) ($counts['open_count'] ?? 0),
            'ongoing' => (int) ($counts['ongoing_count'] ?? 0),
            'closed' => (int) ($counts['closed_count'] ?? 0),
        ],
        'page' => $page,
        'page_count' => $pageCount,
        'page_size' => $pageSize,
        'sort' => $filters['sort'],
        'dir' => $filters['dir'],
        'search' => $filters['search'],
        'status' => $filters['status'],
        'is_client' => !empty($filters['is_client']),
    ];
}

function lex_case_files_render_status_pill(string $status): string
{
    $class = lex_case_files_status_class($status);
    return '<span class="pill status-pill ' . lex_e($class) . '">' . lex_e(lex_case_files_status_label($status)) . '</span>';
}

function lex_case_files_render_upload_status(string $status): string
{
    $label = match ($status) {
        'pending' => 'Pending approval',
        'rejected' => 'Rejected',
        default => 'Approved',
    };
    return '<span class="pill vault-status vault-status-' . lex_e($status) . '">' . lex_e($label) . '</span>';
}

function lex_case_files_is_default_vault_folder(string $slug): bool
{
    return in_array($slug, array_map('lex_case_file_vault_slug', lex_case_file_vault_default_folders()), true);
}

function lex_case_files_render_summary(array $state): string
{
    ob_start();
    ?>
    <section class="kpi-grid case-stats" aria-label="Case file summary">
      <article class="kpi-card"><span>Total Case Files</span><strong><?= number_format((int) $state['counts']['total']) ?></strong></article>
      <article class="kpi-card"><span>Open</span><strong><?= number_format((int) $state['counts']['open']) ?></strong></article>
      <article class="kpi-card"><span>Ongoing</span><strong><?= number_format((int) $state['counts']['ongoing']) ?></strong></article>
      <article class="kpi-card"><span>Closed</span><strong><?= number_format((int) $state['counts']['closed']) ?></strong></article>
    </section>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_list(array $state, array $filters): string
{
    $records = $state['records'];
    $search = (string) $state['search'];
    $selectedId = (int) (($state['selected']['id'] ?? 0) ?: 0);
    ob_start();
    ?>
    <article class="card case-panel case-list-panel case-compact-panel">
      <div class="card-head case-panel-header case-compact-header">
        <div>
          <h2>Case File List</h2>
          
        </div>
        <span class="pill"><?= number_format((int) $state['counts']['total']) ?> records</span>
      </div>

      <div class="table-wrap case-table-wrap">
        <table class="data-table case-list-table">
          <thead>
            <tr>
              <th>FULLNAME</th>
              <th>CASE FILE</th>
              <th>Status</th>
              <th>Assigned Lawyer</th>
              <th>Date Created</th>
              <th class="case-table-action-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($records)): ?>
              <tr>
                <td colspan="5">
                  <div class="case-empty-state">
                    <strong>No case files yet.</strong>
                    <p class="muted">Create the first file or loosen your filters.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($records as $record): ?>
                <?php
                  $recordId = (int) $record['id'];
                  $isSelected = $recordId === $selectedId;
                ?>
                <tr class="<?= $isSelected ? 'is-active' : '' ?>" data-case-row data-case-id="<?= $recordId ?>">
                  <td>
                    <button class="case-folder-link" type="button" data-case-open-vault data-case-id="<?= $recordId ?>">
                      <?= lex_case_files_highlight((string) $record['full_name'], $search) ?>
                    </button>
                  </td>
                  <td>
                    <div class="case-file-cell">
                      <strong><?= lex_case_files_highlight((string) $record['case_file_title'], $search) ?></strong>
                      <span class="muted">#<?= lex_e((string) $record['case_identifier']) ?></span>
                    </div>
                  </td>
                  <td><?= lex_case_files_render_status_pill((string) $record['status']) ?></td>
                  <td><?= lex_e((string) ($record['assigned_lawyer_name'] ?: 'Unassigned')) ?></td>
                  <td><?= lex_e((string) $record['date_created']) ?></td>
                  <td class="case-table-action-col">
                    <div class="case-row-actions">
                      <button class="button button-secondary case-select-button" type="button" data-case-open-vault data-case-id="<?= $recordId ?>">Folder</button>
                      <button class="button button-secondary case-select-button" type="button" data-case-select data-case-id="<?= $recordId ?>">Details</button>
                      <?php if (!empty($filters['is_lawyer'])): ?>
                        <form method="post" class="case-inline-delete-form" data-persist-form="casefile-delete-row-<?= $recordId ?>">
                          <?= lex_csrf_field() ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="case_file_id" value="<?= $recordId ?>">
                          <button class="button button-danger case-delete-button" type="submit" data-confirm="Permanently delete this case file, its vault folders, documents, attachments, and metadata? This cannot be undone." data-confirm-text="CONFIRM">Delete</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="case-pagination" data-case-pagination-container>
        <?php echo lex_case_files_render_pagination($state); ?>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_pagination(array $state): string
{
    $page = (int) $state['page'];
    $pageCount = (int) $state['page_count'];
    if ($pageCount <= 1) {
        return '<div class="case-pagination-empty muted">Showing all results on one page.</div>';
    }

    $items = [];
    if ($page > 1) {
        $items[] = '<button class="button button-secondary pagination-button" type="button" data-case-page="' . ($page - 1) . '">Previous</button>';
    }
    $items[] = '<span class="pagination-status">Page ' . $page . ' of ' . $pageCount . '</span>';
    if ($page < $pageCount) {
        $items[] = '<button class="button button-secondary pagination-button" type="button" data-case-page="' . ($page + 1) . '">Next</button>';
    }

    return '<div class="pagination-strip">' . implode('', $items) . '</div>';
}

function lex_case_files_render_vault(array $record, array $filters, array $user): string
{
    $vault = lex_case_file_vault_fetch((int) $record['id'], $user);
    $folders = $vault['folders'];
    $documents = $vault['documents'];
    $documentsByFolder = [];
    foreach ($documents as $document) {
        $documentsByFolder[(int) $document['folder_id']][] = $document;
    }
    $isLawyer = !empty($filters['is_lawyer']);
    $isClient = !empty($filters['is_client']);
    $folderCount = count($folders);
    $documentCount = count($documents);
    ob_start();
    ?>
    <div class="case-vault-shell">
      <div class="case-vault-topbar">
        <div class="case-vault-heading">
          <span class="case-vault-kicker">Shared workspace</span>
          <h3>Case Document Vault</h3>
          <p class="muted"><?= $isClient ? 'Client uploads are submitted for lawyer approval before they become part of the vault.' : 'Manage folders, review pending client uploads, and keep case evidence organized.' ?></p>
        </div>
        <div class="case-vault-meta">
          <span class="pill"><?= $folderCount ?> folder<?= $folderCount === 1 ? '' : 's' ?></span>
          <span class="pill"><?= $documentCount ?> file<?= $documentCount === 1 ? '' : 's' ?></span>
        </div>
      </div>

      <div class="case-vault-actions">
        <?php if ($isLawyer): ?>
          <form method="post" class="case-vault-folder-form">
            <?= lex_csrf_field() ?>
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
            <label>
              <span>New folder</span>
              <input type="text" name="folder_name" placeholder="Example: Discovery" maxlength="150" required>
            </label>
            <button class="button button-secondary" type="submit">Create Folder</button>
          </form>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="case-vault-upload-form">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="action" value="upload_document">
          <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
          <label>Folder
            <select name="folder_id" required>
              <?php foreach ($folders as $folder): ?>
                <option value="<?= (int) $folder['id'] ?>"<?= !$isLawyer && (string) $folder['slug'] === 'CLIENT_UPLOADS' ? ' selected' : '' ?>><?= lex_e((string) $folder['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="case-vault-file-input">Document
            <input type="file" name="vault_document" required>
          </label>
          <button class="button button-primary" type="submit"><?= $isClient ? 'Submit for Approval' : 'Upload Document' ?></button>
        </form>
      </div>

      <div class="case-vault-grid">
        <div class="case-vault-documents">
          <?php foreach ($folders as $folder): ?>
            <?php $folderDocuments = $documentsByFolder[(int) $folder['id']] ?? []; ?>
            <details class="case-vault-folder-section" id="vault-folder-<?= (int) $folder['id'] ?>"<?= !empty($folderDocuments) ? ' open' : '' ?>>
              <summary class="case-vault-section-head">
                <div class="case-vault-section-summary">
                  <span class="case-vault-section-folder-icon" aria-hidden="true"></span>
                  <div>
                    <h4><?= lex_e((string) $folder['name']) ?></h4>
                    <p class="muted">Files stored in this folder.</p>
                  </div>
                </div>
                <span class="pill"><?= count($folderDocuments) ?> shown</span>
              </summary>
              <div class="case-vault-folder-content">
                <?php if ($isLawyer && !lex_case_files_is_default_vault_folder((string) $folder['slug'])): ?>
                  <div class="case-vault-folder-tools">
                    <form method="post" class="case-vault-inline-form">
                      <?= lex_csrf_field() ?>
                      <input type="hidden" name="action" value="delete_folder">
                      <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                      <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                      <button class="button button-danger" type="submit" data-confirm="Delete this folder and all documents inside it?">Delete Folder</button>
                    </form>
                  </div>
                <?php endif; ?>
                <?php if (empty($folderDocuments)): ?>
                  <div class="case-empty-state case-subempty">
                    <strong>No documents in this folder.</strong>
                    <p class="muted"><?= $isClient ? 'Approved files will appear here.' : 'Upload or approve documents to fill this folder.' ?></p>
                  </div>
                <?php else: ?>
                  <div class="case-vault-doc-list">
                    <?php foreach ($folderDocuments as $document): ?>
                    <?php
                      $documentUrl = lex_app_url('case_document_file.php?document_id=' . (int) $document['id']);
                      $previewUrl = $documentUrl . '&preview=1';
                    ?>
                    <article class="case-vault-doc">
                      <span class="case-vault-doc-icon" aria-hidden="true"></span>
                      <div class="case-vault-doc-main">
                        <strong><?= lex_e((string) $document['original_name']) ?></strong>
                        <span><?= lex_e(lex_case_files_format_size((int) ($document['file_size'] ?? 0))) ?> | Uploaded by <?= lex_e((string) $document['uploaded_by_name']) ?></span>
                        <small><?= lex_e((string) $document['created_at']) ?></small>
                      </div>
                        <div class="case-vault-doc-actions">
                          <?php if ((string) $document['upload_status'] !== 'approved'): ?>
                            <?= lex_case_files_render_upload_status((string) $document['upload_status']) ?>
                          <?php endif; ?>
                          <?php if ((string) $document['upload_status'] === 'approved' || $isLawyer): ?>
                            <a class="button button-secondary" href="<?= lex_e($previewUrl) ?>" target="_blank" rel="noopener">Preview</a>
                            <a class="button button-secondary" href="<?= lex_e($documentUrl) ?>">Download</a>
                          <?php endif; ?>
                          <?php if ($isLawyer): ?>
                            <?php if ((string) $document['upload_status'] === 'pending'): ?>
                              <form method="post" class="case-vault-inline-form">
                                <?= lex_csrf_field() ?>
                                <input type="hidden" name="action" value="approve_document">
                                <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                                <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                <button class="button button-primary" type="submit">Approve</button>
                              </form>
                              <form method="post" class="case-vault-inline-form">
                                <?= lex_csrf_field() ?>
                                <input type="hidden" name="action" value="reject_document">
                                <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                                <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                <button class="button button-danger" type="submit">Reject</button>
                              </form>
                            <?php endif; ?>
                            <form method="post" class="case-vault-inline-form">
                              <?= lex_csrf_field() ?>
                              <input type="hidden" name="action" value="delete_document">
                              <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                              <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                              <button class="button button-danger" type="submit" data-confirm="Delete this vault document?">Delete</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </article>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_vault_panel(array $state, array $filters, array $user): string
{
    $record = $state['selected'];
    ob_start();
    ?>
    <article class="card case-panel case-vault-panel case-compact-panel">
      <?php if (!$record): ?>
        <div class="case-vault-panel-empty">
          <div>
            <h2>Document Vault</h2>
            <p class="muted">Select a case file from the list above to open its folders and files.</p>
          </div>
        </div>
      <?php else: ?>
        <div class="card-head case-panel-header case-compact-header case-vault-panel-header">
          <div>
            <div class="case-vault-client-banner">
              <h1 class="case-vault-client-name"><?= lex_e((string) $record['full_name']) ?></h1>
            </div>
            <span class="case-vault-kicker">Case Document Vault</span>
            <div class="case-vault-meta">
              <span class="pill">Case File: <?= lex_e((string) $record['case_file_title']) ?></span>
              <span class="pill">Reference: <?= lex_e((string) $record['case_identifier']) ?></span>
            </div>
          </div>
          <button class="button button-secondary" type="button" data-case-select data-case-id="<?= (int) $record['id'] ?>">Open details</button>
        </div>
        <?= lex_case_files_render_vault($record, $filters, $user) ?>
      <?php endif; ?>
    </article>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_detail(array $state, array $filters, array $user): string
{
    $record = $state['selected'];
    $error = $state['selected_error'];
    $search = (string) $state['search'];
    ob_start();
    ?>
    <div class="modal-overlay case-detail-modal-overlay" id="caseFileDetailModal" data-case-detail-modal aria-hidden="true">
      <div class="modal-card case-detail-modal wide case-compact-modal" role="dialog" aria-modal="true" aria-labelledby="caseFileDetailTitle">
        <div class="modal-header">
          <div>
            <h2 id="caseFileDetailTitle">Case File Details</h2>
            <p class="modal-note">Clear sections for client information, description, and status.</p>
          </div>
          <button class="close-button" type="button" data-case-detail-close aria-label="Close">&times;</button>
        </div>

        <div class="modal-body case-detail-modal-body">
          <?php if ($error): ?>
            <div class="alert alert-error"><?= lex_e($error) ?></div>
          <?php endif; ?>

          <?php if (!$record): ?>
            <div class="case-empty-state case-detail-empty">
              <strong>No case file selected.</strong>
              <p class="muted">Choose a record from the list to review the details.</p>
            </div>
          <?php else: ?>
            <div class="case-detail-head">
              <div>
                <div class="case-overline">FULLNAME</div>
                <h3><?= lex_case_files_highlight((string) $record['full_name'], $search) ?></h3>
                <p class="muted"><?= lex_case_files_highlight((string) $record['case_file_title'], $search) ?></p>
              </div>
              <div class="case-detail-head-actions">
                <?= lex_case_files_render_status_pill((string) $record['status']) ?>
                <?php if (!empty($filters['is_lawyer'])): ?>
                  <button
                    class="icon-chip case-edit-chip"
                    type="button"
                    data-case-edit-open
                    data-case-id="<?= (int) $record['id'] ?>"
                    data-full-name="<?= lex_e((string) $record['full_name']) ?>"
                    data-case-file-title="<?= lex_e((string) $record['case_file_title']) ?>"
                    data-description="<?= lex_e((string) $record['description']) ?>"
                    data-status="<?= lex_e((string) $record['status']) ?>"
                    data-client-user-id="<?= (int) $record['client_user_id'] ?>"
                    data-assigned-lawyer-user-id="<?= (int) $record['assigned_lawyer_user_id'] ?>"
                    aria-label="Edit Case File"
                    title="Edit Case File"
                  >&#9998;</button>
                <?php endif; ?>
                <button class="button button-secondary" type="button" data-case-detail-close>Back to list</button>
              </div>
            </div>

            <details class="case-accordion" open>
              <summary>Client Info</summary>
              <div class="case-detail-grid">
                <div class="case-info-card"><span>Client Name</span><strong><?= lex_e((string) $record['client_name']) ?></strong></div>
                <div class="case-info-card"><span>Client Email</span><strong><?= lex_e((string) $record['client_email']) ?></strong></div>
                <div class="case-info-card"><span>Assigned Lawyer</span><strong><?= lex_e((string) ($record['assigned_lawyer_name'] ?: 'Unassigned')) ?></strong></div>
                <div class="case-info-card"><span>Case Identifier</span><strong><?= lex_e((string) $record['case_identifier']) ?></strong></div>
              </div>
            </details>

            <details class="case-accordion" open>
              <summary>Case Description</summary>
              <div class="case-body-copy">
                <?= nl2br(lex_e((string) ($record['description'] ?: 'No description has been added.'))) ?>
              </div>
            </details>

            <details class="case-accordion" open>
              <summary>Status</summary>
              <div class="case-detail-grid">
                <div class="case-info-card"><span>Status</span><strong><?= lex_e(lex_case_files_status_label((string) $record['status'])) ?></strong></div>
                <div class="case-info-card"><span>Date Created</span><strong><?= lex_e((string) $record['date_created']) ?></strong></div>
                <div class="case-info-card"><span>Created By</span><strong><?= lex_e((string) ($record['created_by_name'] ?: 'System')) ?></strong></div>
                <div class="case-info-card"><span>Updated By</span><strong><?= lex_e((string) ($record['updated_by_name'] ?: 'System')) ?></strong></div>
              </div>
            </details>

          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_editor(array $state, array $filters, array $clients, array $lawyers, array $user): string
{
    ob_start();
    ?>
    <?php if (!empty($filters['is_lawyer'])): ?>
      <div class="modal-overlay case-create-modal-overlay" id="caseFileCreateModal" data-case-create-modal aria-hidden="true">
        <div class="modal-card case-create-modal wide case-compact-modal" role="dialog" aria-modal="true" aria-labelledby="caseFileCreateTitle">
          <div class="modal-header">
            <div>
              <h2 id="caseFileCreateTitle">Create Case File</h2>
              <p class="modal-note">FULLNAME is required. The form stores a local draft until submission succeeds.</p>
            </div>
            <button class="close-button" type="button" data-case-create-close aria-label="Close">&times;</button>
          </div>
          <div class="modal-body case-create-modal-body">
            <form method="post" enctype="multipart/form-data" class="case-form" data-casefile-form data-persist-form="casefile-create" novalidate>
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <div class="form-grid">
                <label class="full">Client
                  <select name="client_user_id" required>
                    <option value="">Select client</option>
                    <?php foreach ($clients as $client): ?>
                      <option value="<?= (int) $client['id'] ?>"><?= lex_e((string) $client['full_name']) ?> - Client</option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="full">FULLNAME
                  <input type="text" name="full_name" required minlength="2" placeholder="Client or entity full name">
                </label>
                <label class="full">CASE FILE
                  <input type="text" name="case_file_title" required minlength="2" placeholder="Case file title">
                </label>
                <label class="full">Assigned Lawyer
                  <select name="assigned_lawyer_user_id" required>
                    <?php foreach ($lawyers as $lawyer): ?>
                      <option value="<?= (int) $lawyer['id'] ?>"<?= (int) $lawyer['id'] === (int) $user['id'] ? ' selected' : '' ?>><?= lex_e((string) $lawyer['full_name']) ?> - Lawyer</option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="full">Status
                  <select name="status" required>
                    <option value="open">Open</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="closed">Closed</option>
                  </select>
                </label>
                <label class="full">Case Description
                  <textarea name="description" rows="4" placeholder="Brief legal summary, risks, and notes"></textarea>
                </label>
                <div class="case-upload-inline full">
                  <div class="case-subpanel-head">
                    <h3>Upload Attachment</h3>
                    <p class="muted">Optional file saved with the new case file.</p>
                  </div>
                  <label class="full">Category
                    <select name="upload_category">
                      <option value="DOCUMENTS">Documents</option>
                      <option value="PHOTOS">Photos</option>
                      <option value="EVIDENCE">Evidence</option>
                      <option value="COURT_FILINGS">Court Filings</option>
                      <option value="CORRESPONDENCE">Correspondence</option>
                    </select>
                  </label>
                  <label class="full">Attachment
                    <input type="file" name="attachment">
                  </label>
                </div>
              </div>
              <p class="field-error" data-form-errors aria-live="polite"></p>
              <div class="action-group">
                <button class="button button-primary" type="submit">Create Case File</button>
                <button class="button button-secondary" type="reset">Clear</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php if (!empty($state['selected'])): ?>
        <div class="modal-overlay case-create-modal-overlay" id="caseFileEditModal" data-case-edit-modal aria-hidden="true">
          <div class="modal-card case-create-modal wide case-compact-modal" role="dialog" aria-modal="true" aria-labelledby="caseFileEditTitle">
            <div class="modal-header">
              <div>
                <h2 id="caseFileEditTitle">Edit Case File</h2>
                <p class="modal-note">Lawyers can update the case details, status, and assignment.</p>
              </div>
              <button class="close-button" type="button" data-case-edit-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body case-create-modal-body">
              <form method="post" class="case-form" data-casefile-form novalidate data-persist-form="casefile-edit-<?= (int) $state['selected']['id'] ?>">
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="case_file_id" value="<?= (int) $state['selected']['id'] ?>" data-case-edit-id>
                <div class="form-grid">
                  <label class="full">Client
                    <select name="client_user_id" required data-case-edit-client>
                      <option value="">Select client</option>
                      <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>"<?= (int) $state['selected']['client_user_id'] === (int) $client['id'] ? ' selected' : '' ?>><?= lex_e((string) $client['full_name']) ?> - Client</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="full">FULLNAME
                    <input type="text" name="full_name" required minlength="2" placeholder="Client or entity full name" value="<?= lex_e((string) $state['selected']['full_name']) ?>" data-case-edit-full-name>
                  </label>
                  <label class="full">CASE FILE
                    <input type="text" name="case_file_title" required minlength="2" placeholder="Case file title" value="<?= lex_e((string) $state['selected']['case_file_title']) ?>" data-case-edit-case-title>
                  </label>
                  <label class="full">Assigned Lawyer
                    <select name="assigned_lawyer_user_id" required data-case-edit-lawyer>
                      <?php foreach ($lawyers as $lawyer): ?>
                        <option value="<?= (int) $lawyer['id'] ?>"<?= (int) $state['selected']['assigned_lawyer_user_id'] === (int) $lawyer['id'] ? ' selected' : '' ?>><?= lex_e((string) $lawyer['full_name']) ?> - Lawyer</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="full">Status
                    <select name="status" required data-case-edit-status>
                      <option value="open"<?= (string) $state['selected']['status'] === 'open' ? ' selected' : '' ?>>Open</option>
                      <option value="ongoing"<?= (string) $state['selected']['status'] === 'ongoing' ? ' selected' : '' ?>>Ongoing</option>
                      <option value="closed"<?= (string) $state['selected']['status'] === 'closed' ? ' selected' : '' ?>>Closed</option>
                    </select>
                  </label>
                  <label class="full">Case Description
                    <textarea name="description" rows="4" placeholder="Brief legal summary, risks, and notes" data-case-edit-description><?= lex_e((string) $state['selected']['description']) ?></textarea>
                  </label>
                </div>
                <p class="field-error" data-form-errors aria-live="polite"></p>
                <div class="action-group">
                  <button class="button button-primary" type="submit">Save Changes</button>
                  <button class="button button-secondary" type="reset">Reset</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="case-empty-state">
        <strong>Client access is restricted.</strong>
        <p class="muted">You can only view case files linked to your own account.</p>
      </div>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_render_activity(array $state): string
{
    $activity = $state['activity'];
    ob_start();
    ?>
    <article class="card case-panel case-activity-card">
      <div class="card-head case-panel-header">
        <div>
          <h2>Recent Activity</h2>
          <p class="muted case-activity-note">Freshly updated records from the visible set.</p>
        </div>
        <span class="pill">Latest 5</span>
      </div>
      <div class="activity-feed case-activity-feed">
        <?php if (empty($activity)): ?>
          <div class="case-empty-state">
            <strong>No recent activity.</strong>
            <p class="muted">Activity will appear once case files are created or updated.</p>
          </div>
        <?php else: ?>
          <?php foreach ($activity as $item): ?>
            <div class="activity-item case-activity-item">
              <div>
                <strong><?= lex_e((string) $item['full_name']) ?></strong>
                <span><?= lex_e((string) $item['case_file_title']) ?></span>
              </div>
              <div class="case-activity-meta">
                <?= lex_case_files_render_status_pill((string) $item['status']) ?>
                <small><?= lex_e((string) $item['updated_at']) ?></small>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function lex_case_files_collect_request_filters(array $user): array
{
    $status = (string) ($_GET['status'] ?? 'all');
    $sort = (string) ($_GET['sort'] ?? 'updated_at');
    $dir = strtolower((string) ($_GET['dir'] ?? 'desc'));
    $page = max(1, lex_sanitize_int($_GET['page'] ?? 1));
    $record = lex_sanitize_int($_GET['record'] ?? 0);
    $search = trim((string) ($_GET['q'] ?? ''));

    return [
        'search' => $search,
        'status' => in_array($status, ['all', 'open', 'ongoing', 'closed'], true) ? $status : 'all',
        'sort' => in_array($sort, ['updated_at', 'date_created', 'full_name', 'case_file_title', 'status'], true) ? $sort : 'updated_at',
        'dir' => $dir === 'asc' ? 'asc' : 'desc',
        'page' => $page,
        'record' => $record,
        'is_client' => $user['role'] === 'client',
        'is_lawyer' => $user['role'] === 'lawyer',
        'current_client_user_id' => $user['role'] === 'client' ? (int) $user['id'] : 0,
        'current_lawyer_user_id' => $user['role'] === 'lawyer' ? (int) $user['id'] : 0,
    ];
}

function lex_case_files_redirect_url(array $filters, ?int $recordId = null): string
{
    $params = [];
    if (!empty($filters['search'])) {
        $params['q'] = $filters['search'];
    }
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['sort']) && $filters['sort'] !== 'updated_at') {
        $params['sort'] = $filters['sort'];
    }
    if (!empty($filters['dir']) && $filters['dir'] !== 'desc') {
        $params['dir'] = $filters['dir'];
    }
    if (!empty($filters['page']) && (int) $filters['page'] > 1) {
        $params['page'] = (int) $filters['page'];
    }
    if ($recordId !== null) {
        $params['record'] = $recordId;
    }

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return 'case_files.php' . ($query !== '' ? '?' . $query : '');
}

function lex_case_files_send_json(array $state, array $filters, array $clients, array $lawyers, array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'summaryHtml' => lex_case_files_render_summary($state),
        'listHtml' => lex_case_files_render_list($state, $filters),
        'detailHtml' => lex_case_files_render_detail($state, $filters, $user),
        'vaultHtml' => lex_case_files_render_vault_panel($state, $filters, $user),
        'activityHtml' => lex_case_files_render_activity($state),
        'editorHtml' => lex_case_files_render_editor($state, $filters, $clients, $lawyers, $user),
        'paginationHtml' => lex_case_files_render_pagination($state),
        'meta' => [
            'page' => (int) $state['page'],
            'pageCount' => (int) $state['page_count'],
            'total' => (int) $state['counts']['total'],
            'selectedId' => (int) (($state['selected']['id'] ?? 0) ?: 0),
            'selectedError' => (string) $state['selected_error'],
            'search' => (string) $state['search'],
            'status' => (string) $state['status'],
            'sort' => (string) $state['sort'],
            'dir' => (string) $state['dir'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = lex_pdo();
lex_case_files_seed_from_cases($pdo);

$user = lex_require_role(['lawyer', 'client']);
$isLawyer = $user['role'] === 'lawyer';
$isClient = $user['role'] === 'client';
$clients = lex_recent('SELECT u.id, u.full_name, u.email FROM users u JOIN clients c ON c.user_id = u.id WHERE u.role = "client" AND u.is_active = 1 ORDER BY u.full_name ASC');
$lawyers = lex_recent('SELECT u.id, u.full_name, u.email FROM users u JOIN lawyers l ON l.user_id = u.id WHERE u.role = "lawyer" AND u.is_active = 1 AND l.status = "active" ORDER BY u.full_name ASC');

$message = '';
$error = '';
$failedAction = '';
$filters = lex_case_files_collect_request_filters($user);
$pageSize = 8;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
        $returnUrl = lex_case_files_redirect_url($filters, $caseFileId > 0 ? $caseFileId : null);

        if (in_array($action, ['create_folder', 'delete_folder', 'upload_document', 'approve_document', 'reject_document', 'delete_document'], true) && $caseFileId > 0) {
            $stmt = $pdo->prepare('SELECT cf.* FROM case_files cf WHERE cf.id = :id LIMIT 1');
            $stmt->execute(['id' => $caseFileId]);
            $caseFile = $stmt->fetch();
            $access = $caseFile ? lex_case_file_vault_access($caseFile, $user) : 'none';
            if (!$caseFile || $access === 'none') {
                $error = 'Case file not found.';
            } elseif ($action === 'create_folder') {
                if ($access !== 'manage') {
                    $error = 'Only the assigned lawyer can manage vault folders.';
                } else {
                    $folderName = lex_sanitize_text($_POST['folder_name'] ?? '');
                    if ($folderName === '') {
                        $error = 'Folder name is required.';
                    } else {
                        lex_case_file_vault_ensure_defaults($caseFileId, (int) $user['id']);
                        $slug = lex_case_file_vault_slug($folderName);
                        $folderExists = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = :slug LIMIT 1');
                        $folderExists->execute(['case_file_id' => $caseFileId, 'slug' => $slug]);
                        if ($folderExists->fetchColumn()) {
                            $error = 'That vault folder already exists.';
                        } else {
                        $pdo->prepare(
                            'INSERT IGNORE INTO case_file_folders (case_file_id, parent_folder_id, name, slug, created_by_user_id)
                             VALUES (:case_file_id, NULL, :name, :slug, :created_by_user_id)'
                        )->execute([
                            'case_file_id' => $caseFileId,
                            'name' => $folderName,
                            'slug' => $slug,
                            'created_by_user_id' => (int) $user['id'],
                        ]);
                        $folderPath = lex_case_files_folder_path((string) $caseFile['folder_name']) . DIRECTORY_SEPARATOR . $slug;
                        if (!is_dir($folderPath)) {
                            @mkdir($folderPath, 0775, true);
                        }
                        lex_audit('create_case_file_folder', 'case_file_folders', (string) $caseFileId);
                        lex_flash_set('success', 'Vault folder added.');
                        header('Location: ' . $returnUrl);
                        exit;
                        }
                    }
                }
            } elseif ($action === 'delete_folder') {
                if ($access !== 'manage') {
                    $error = 'Only the assigned lawyer can manage vault folders.';
                } else {
                    $folderId = lex_sanitize_int($_POST['folder_id'] ?? 0);
                    $folderStmt = $pdo->prepare(
                        'SELECT * FROM case_file_folders
                         WHERE id = :id AND case_file_id = :case_file_id
                         LIMIT 1'
                    );
                    $folderStmt->execute(['id' => $folderId, 'case_file_id' => $caseFileId]);
                    $folder = $folderStmt->fetch();
                    if (!$folder) {
                        $error = 'Vault folder not found.';
                    } elseif (lex_case_files_is_default_vault_folder((string) $folder['slug'])) {
                        $error = 'Default vault folders cannot be deleted.';
                    } else {
                        $folderPath = lex_case_file_vault_folder_dir($caseFile, $folder);
                        lex_case_files_recursive_delete($folderPath);
                        $pdo->prepare('DELETE FROM case_file_folders WHERE id = :id')->execute(['id' => $folderId]);
                        lex_audit('delete_case_file_folder', 'case_file_folders', (string) $folderId);
                        lex_flash_set('success', 'Vault folder deleted.');
                        header('Location: ' . $returnUrl);
                        exit;
                    }
                }
            } elseif ($action === 'upload_document') {
                $folderId = lex_sanitize_int($_POST['folder_id'] ?? 0);
                try {
                    lex_case_file_vault_ensure_defaults($caseFileId, (int) $user['id']);
                    if ($access === 'client') {
                        $clientFolderStmt = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = "CLIENT_UPLOADS" LIMIT 1');
                        $clientFolderStmt->execute(['case_file_id' => $caseFileId]);
                        $folderId = (int) ($clientFolderStmt->fetchColumn() ?: $folderId);
                    }
                    $status = $access === 'manage' ? 'approved' : 'pending';
                    $document = lex_case_file_vault_store_document($caseFile, $folderId, $_FILES['vault_document'] ?? [], $user, $status);
                    $pdo->prepare('UPDATE case_files SET updated_by_user_id = :updated_by_user_id WHERE id = :id')->execute([
                        'updated_by_user_id' => (int) $user['id'],
                        'id' => $caseFileId,
                    ]);
                    lex_audit($status === 'pending' ? 'submit_case_file_document' : 'upload_case_file_document', 'case_file_documents', (string) $document['id']);
                    if ($status === 'pending' && !empty($caseFile['assigned_lawyer_user_id'])) {
                        lex_notify((int) $caseFile['assigned_lawyer_user_id'], 'case_file', 'A client submitted a document for approval.');
                    } elseif ($status === 'approved') {
                        lex_notify((int) $caseFile['client_user_id'], 'case_file', 'A new case document is available in your vault.');
                    }
                    lex_flash_set('success', $status === 'pending' ? 'Document submitted for lawyer approval.' : 'Document uploaded to the vault.');
                    header('Location: ' . $returnUrl);
                    exit;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            } elseif (in_array($action, ['approve_document', 'reject_document', 'delete_document'], true)) {
                if ($access !== 'manage') {
                    $error = 'Only the assigned lawyer can review vault documents.';
                } else {
                    $documentId = lex_sanitize_int($_POST['document_id'] ?? 0);
                    $docStmt = $pdo->prepare(
                        'SELECT d.*, f.slug AS folder_slug, f.name AS folder_name
                         FROM case_file_documents d
                         JOIN case_file_folders f ON f.id = d.folder_id
                         WHERE d.id = :id AND d.case_file_id = :case_file_id
                         LIMIT 1'
                    );
                    $docStmt->execute(['id' => $documentId, 'case_file_id' => $caseFileId]);
                    $document = $docStmt->fetch();
                    if (!$document) {
                        $error = 'Vault document not found.';
                    } elseif ($action === 'approve_document') {
                        $pdo->prepare(
                            'UPDATE case_file_documents
                             SET upload_status = "approved", approved_by_user_id = :approved_by_user_id, approved_at = NOW(), rejection_reason = NULL
                             WHERE id = :id'
                        )->execute(['approved_by_user_id' => (int) $user['id'], 'id' => $documentId]);
                        lex_audit('approve_case_file_document', 'case_file_documents', (string) $documentId);
                        lex_notify((int) $caseFile['client_user_id'], 'case_file', 'Your submitted case document was approved.');
                        lex_flash_set('success', 'Document approved.');
                        header('Location: ' . $returnUrl);
                        exit;
                    } elseif ($action === 'reject_document') {
                        $pdo->prepare(
                            'UPDATE case_file_documents
                             SET upload_status = "rejected", approved_by_user_id = :approved_by_user_id, approved_at = NOW()
                             WHERE id = :id'
                        )->execute(['approved_by_user_id' => (int) $user['id'], 'id' => $documentId]);
                        lex_audit('reject_case_file_document', 'case_file_documents', (string) $documentId);
                        lex_notify((int) $caseFile['client_user_id'], 'case_file', 'Your submitted case document was rejected.');
                        lex_flash_set('success', 'Document rejected.');
                        header('Location: ' . $returnUrl);
                        exit;
                    } else {
                        $path = lex_case_files_folder_path((string) $caseFile['folder_name']) . DIRECTORY_SEPARATOR . lex_case_file_vault_slug((string) $document['folder_slug']) . DIRECTORY_SEPARATOR . basename((string) $document['stored_name']);
                        if (is_file($path)) {
                            @unlink($path);
                        }
                        $pdo->prepare('DELETE FROM case_file_documents WHERE id = :id')->execute(['id' => $documentId]);
                        lex_audit('delete_case_file_document', 'case_file_documents', (string) $documentId);
                        lex_flash_set('success', 'Vault document deleted.');
                        header('Location: ' . $returnUrl);
                        exit;
                    }
                }
            }
        } elseif ($isClient) {
            $error = 'Clients can view only their own case files.';
        } elseif ($action === 'create') {
            $clientUserId = lex_sanitize_int($_POST['client_user_id'] ?? 0);
            $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
            $caseFileTitle = lex_sanitize_text($_POST['case_file_title'] ?? '');
            $description = lex_sanitize_text($_POST['description'] ?? '');
            $status = (string) ($_POST['status'] ?? 'open');
            $assignedLawyerUserId = lex_sanitize_int($_POST['assigned_lawyer_user_id'] ?? 0);
            if ($assignedLawyerUserId === 0) {
                $assignedLawyerUserId = (int) $user['id'];
            }

            $clientCheck = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id AND role = "client" AND is_active = 1 LIMIT 1');
            $clientCheck->execute(['id' => $clientUserId]);
            $clientRow = $clientCheck->fetch();
            $lawyerCheck = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id AND role = "lawyer" AND is_active = 1 LIMIT 1');
            $lawyerCheck->execute(['id' => $assignedLawyerUserId]);
            $lawyerRow = $lawyerCheck->fetch();

            if (!$clientRow) {
                $error = 'Select a valid client.';
            } elseif ($fullName === '') {
                $error = 'FULLNAME is required.';
            } elseif ($caseFileTitle === '') {
                $error = 'CASE FILE is required.';
            } elseif (!$lawyerRow) {
                $error = 'Select a valid assigned lawyer.';
            } elseif (!in_array($status, ['open', 'ongoing', 'closed'], true)) {
                $error = 'Select a valid status.';
            } else {
                $caseIdentifier = 'CF-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $folderName = lex_case_files_folder_name($fullName, $caseFileTitle, $caseIdentifier);
                $pdo->prepare(
                    'INSERT INTO case_files
                        (full_name, case_identifier, case_file_title, description, date_created, client_user_id, assigned_lawyer_user_id, status, folder_name, attachments_json, created_by_user_id, updated_by_user_id)
                     VALUES
                        (:full_name, :case_identifier, :case_file_title, :description, CURDATE(), :client_user_id, :assigned_lawyer_user_id, :status, :folder_name, :attachments_json, :created_by_user_id, :updated_by_user_id)'
                )->execute([
                    'full_name' => $fullName,
                    'case_identifier' => $caseIdentifier,
                    'case_file_title' => $caseFileTitle,
                    'description' => $description,
                    'client_user_id' => (int) $clientRow['id'],
                    'assigned_lawyer_user_id' => (int) $lawyerRow['id'],
                    'status' => $status,
                    'folder_name' => $folderName,
                    'attachments_json' => '[]',
                    'created_by_user_id' => (int) $user['id'],
                    'updated_by_user_id' => (int) $user['id'],
                ]);
                $newId = (int) $pdo->lastInsertId();
                $record = lex_recent(
                    'SELECT cf.*, lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name, cu.email AS client_email, cb.full_name AS created_by_name, ub.full_name AS updated_by_name
                     FROM case_files cf
                     JOIN users cu ON cu.id = cf.client_user_id
                     LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
                     LEFT JOIN users cb ON cb.id = cf.created_by_user_id
                     LEFT JOIN users ub ON ub.id = cf.updated_by_user_id
                     WHERE cf.id = :id LIMIT 1',
                    ['id' => $newId]
                )[0] ?? null;
                if ($record) {
                    lex_case_file_vault_ensure_defaults($newId, (int) $user['id']);
                    $attachmentError = '';
                    if (!empty($_FILES['attachment']['name'])) {
                        $category = strtoupper(trim((string) ($_POST['upload_category'] ?? 'DOCUMENTS')));
                        $allowedCategories = ['DOCUMENTS', 'PHOTOS', 'EVIDENCE', 'COURT_FILINGS', 'CORRESPONDENCE'];
                        if (!in_array($category, $allowedCategories, true)) {
                            $category = 'DOCUMENTS';
                        }
                        $folderStmt = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = :slug LIMIT 1');
                        $folderStmt->execute(['case_file_id' => $newId, 'slug' => $category]);
                        $folderId = (int) ($folderStmt->fetchColumn() ?: 0);
                        try {
                            $document = lex_case_file_vault_store_document($record, $folderId, $_FILES['attachment'], $user, 'approved');
                            lex_audit('upload_case_file_document', 'case_file_documents', (string) $document['id']);
                        } catch (Throwable $e) {
                            $attachmentError = $e->getMessage();
                        }
                    }
                    if ($attachmentError !== '') {
                        lex_case_files_recursive_delete(lex_case_files_folder_path((string) $record['folder_name']));
                        $pdo->prepare('DELETE FROM case_files WHERE id = :id')->execute(['id' => $newId]);
                        $error = $attachmentError;
                    }
                    lex_case_files_sync_record($record);
                }
                if ($error === '') {
                    lex_audit('create_case_file', 'case_files', (string) $newId);
                    lex_notify((int) $clientRow['id'], 'case_file', 'A case file was created for your account.');
                    lex_flash_set('success', 'Case file created.');
                    $redirectFilters = $filters;
                    $redirectFilters['search'] = '';
                    $redirectFilters['status'] = 'all';
                    $redirectFilters['page'] = 1;
                    $redirectFilters['sort'] = 'updated_at';
                    $redirectFilters['dir'] = 'desc';
                    header('Location: ' . lex_case_files_redirect_url($redirectFilters, $newId));
                    exit;
                }
            }
        } elseif ($action === 'update' && $isLawyer && $caseFileId > 0) {
            $stmt = $pdo->prepare('SELECT cf.* FROM case_files cf WHERE cf.id = :id LIMIT 1');
            $stmt->execute(['id' => $caseFileId]);
            $existing = $stmt->fetch();
            $canEdit = $existing && (
                (int) $existing['created_by_user_id'] === (int) $user['id']
            );
            if (!$existing || !$canEdit) {
                $error = 'Case file not found.';
            } else {
                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $caseFileTitle = lex_sanitize_text($_POST['case_file_title'] ?? '');
                $description = lex_sanitize_text($_POST['description'] ?? '');
                $status = (string) ($_POST['status'] ?? 'open');
                $assignedLawyerUserId = lex_sanitize_int($_POST['assigned_lawyer_user_id'] ?? 0);
                $clientUserId = lex_sanitize_int($_POST['client_user_id'] ?? 0);

                $clientCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "client" AND is_active = 1 LIMIT 1');
                $clientCheck->execute(['id' => $clientUserId]);
                $lawyerCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "lawyer" AND is_active = 1 LIMIT 1');
                $lawyerCheck->execute(['id' => $assignedLawyerUserId]);

                if ($fullName === '') {
                    $error = 'FULLNAME is required.';
                } elseif ($caseFileTitle === '') {
                    $error = 'CASE FILE is required.';
                } elseif (!$clientCheck->fetchColumn()) {
                    $error = 'Select a valid client.';
                } elseif (!$lawyerCheck->fetchColumn()) {
                    $error = 'Select a valid assigned lawyer.';
                } elseif (!in_array($status, ['open', 'ongoing', 'closed'], true)) {
                    $error = 'Select a valid status.';
                } else {
                    $pdo->prepare(
                        'UPDATE case_files
                         SET full_name = :full_name,
                             case_file_title = :case_file_title,
                             description = :description,
                             client_user_id = :client_user_id,
                             assigned_lawyer_user_id = :assigned_lawyer_user_id,
                             status = :status,
                             updated_by_user_id = :updated_by_user_id
                         WHERE id = :id'
                    )->execute([
                        'full_name' => $fullName,
                        'case_file_title' => $caseFileTitle,
                        'description' => $description,
                        'client_user_id' => $clientUserId,
                        'assigned_lawyer_user_id' => $assignedLawyerUserId,
                        'status' => $status,
                        'updated_by_user_id' => (int) $user['id'],
                        'id' => $caseFileId,
                    ]);
                    $record = lex_recent(
                        'SELECT cf.*, lu.full_name AS assigned_lawyer_name, cu.full_name AS client_name, cu.email AS client_email, cb.full_name AS created_by_name, ub.full_name AS updated_by_name
                         FROM case_files cf
                         JOIN users cu ON cu.id = cf.client_user_id
                         LEFT JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
                         LEFT JOIN users cb ON cb.id = cf.created_by_user_id
                         LEFT JOIN users ub ON ub.id = cf.updated_by_user_id
                         WHERE cf.id = :id LIMIT 1',
                        ['id' => $caseFileId]
                    )[0] ?? null;
                    if ($record) {
                        lex_case_files_sync_record($record);
                    }
                    lex_audit('update_case_file', 'case_files', (string) $caseFileId);
                    lex_notify($clientUserId, 'case_file', 'Your case file was updated.');
                    lex_flash_set('success', 'Case file updated.');
                    header('Location: ' . lex_case_files_redirect_url($filters, $caseFileId));
                    exit;
                }
            }
        } elseif ($action === 'delete' && $isLawyer && $caseFileId > 0) {
            $stmt = $pdo->prepare('SELECT id, folder_name FROM case_files WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $caseFileId]);
            $caseFile = $stmt->fetch();
            $folderName = (string) ($caseFile['folder_name'] ?? '');
            if (!$caseFile || $folderName === '') {
                $error = 'Case file not found.';
            } else {
                $caseFolderPath = lex_case_files_folder_path($folderName);
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM case_file_documents WHERE case_file_id = :case_file_id')->execute(['case_file_id' => $caseFileId]);
                    $pdo->prepare('DELETE FROM case_file_folders WHERE case_file_id = :case_file_id')->execute(['case_file_id' => $caseFileId]);
                    $pdo->prepare('DELETE FROM case_files WHERE id = :id')->execute(['id' => $caseFileId]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Unable to permanently delete the case file.';
                }

                if ($error === '') {
                    lex_case_files_recursive_delete($caseFolderPath);
                    lex_audit('hard_delete_case_file', 'case_files', (string) $caseFileId);
                    lex_flash_set('success', 'Case file permanently deleted.');
                    $redirectFilters = $filters;
                    $redirectFilters['record'] = 0;
                    header('Location: ' . lex_case_files_redirect_url($redirectFilters));
                    exit;
                }
            }
        }
    }
}

if ($error !== '') {
    $failedAction = (string) ($_POST['action'] ?? '');
}

$state = lex_case_files_fetch_state($filters, $pageSize);
$requestFormat = strtolower((string) ($_GET['format'] ?? ''));
if ($requestFormat === 'json') {
    lex_case_files_send_json($state, $filters, $clients, $lawyers, $user);
}

lex_page_header('Case Files', 'case-files', $user);
?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= lex_e($error) ?></div>
<?php endif; ?>
<div id="case-files-summary" data-case-summary-container>
  <?= lex_case_files_render_summary($state) ?>
</div>

    <section class="card case-hero case-compact-hero" id="case-overview">
  <form method="get" class="case-search-bar" data-case-filter-form data-no-loading novalidate>
    <input type="hidden" name="page" value="<?= (int) $state['page'] ?>">
    <label class="case-search-field">
      <span>Search</span>
      <input type="search" name="q" placeholder="Search FULLNAME or CASE FILE" value="<?= lex_e((string) $state['search']) ?>" autocomplete="off">
    </label>
    <label class="case-search-field">
      <span>Status</span>
      <select name="status">
        <option value="all"<?= $state['status'] === 'all' ? ' selected' : '' ?>>All</option>
        <option value="open"<?= $state['status'] === 'open' ? ' selected' : '' ?>>Open</option>
        <option value="ongoing"<?= $state['status'] === 'ongoing' ? ' selected' : '' ?>>Ongoing</option>
        <option value="closed"<?= $state['status'] === 'closed' ? ' selected' : '' ?>>Closed</option>
      </select>
    </label>
    <label class="case-search-field">
      <span>Sort</span>
      <select name="sort">
        <option value="updated_at"<?= $state['sort'] === 'updated_at' ? ' selected' : '' ?>>Recently updated</option>
        <option value="date_created"<?= $state['sort'] === 'date_created' ? ' selected' : '' ?>>Date created</option>
        <option value="full_name"<?= $state['sort'] === 'full_name' ? ' selected' : '' ?>>FULLNAME</option>
        <option value="case_file_title"<?= $state['sort'] === 'case_file_title' ? ' selected' : '' ?>>CASE FILE</option>
        <option value="status"<?= $state['sort'] === 'status' ? ' selected' : '' ?>>Status</option>
      </select>
    </label>
    <label class="case-search-field">
      <span>Direction</span>
      <select name="dir">
        <option value="desc"<?= $state['dir'] === 'desc' ? ' selected' : '' ?>>Newest first</option>
        <option value="asc"<?= $state['dir'] === 'asc' ? ' selected' : '' ?>>Oldest first</option>
      </select>
    </label>
    <div class="case-search-actions">
      <?php if ($user['role'] === 'lawyer'): ?>
        <button class="button button-primary" type="button" data-case-create-open>Create case file</button>
      <?php endif; ?>
      <button class="button button-primary" type="submit">Apply</button>
      <a class="button button-secondary" href="case_files.php">Reset</a>
    </div>
  </form>
  <p class="case-search-status" data-case-search-status aria-live="polite">Showing <?= number_format((int) $state['counts']['total']) ?> case files.</p>
</section>

<section class="case-grid case-compact-grid" id="case-list" data-case-files-app data-endpoint="<?= lex_e(lex_app_url('case_files.php')) ?>" data-role="<?= lex_e($user['role']) ?>">
  <div id="case-files-list" data-case-list-container>
    <?= lex_case_files_render_list($state, $filters) ?>
  </div>

  <div id="case-files-detail" data-case-detail-container>
    <?= lex_case_files_render_detail($state, $filters, $user) ?>
  </div>
</section>

<section class="case-grid case-vault-section case-compact-grid">
  <div id="case-files-vault" data-case-vault-container>
    <?= lex_case_files_render_vault_panel($state, $filters, $user) ?>
  </div>
</section>

<section class="case-grid case-grid-bottom case-compact-grid">
  <div id="case-files-activity" data-case-activity-container>
    <?= lex_case_files_render_activity($state) ?>
  </div>
</section>

<?= lex_case_files_render_editor($state, $filters, $clients, $lawyers, $user) ?>

<script>
window.LEX_CASE_FILES_STATE = <?= json_encode([
  'page' => (int) $state['page'],
  'pageCount' => (int) $state['page_count'],
  'search' => (string) $state['search'],
  'status' => (string) $state['status'],
  'sort' => (string) $state['sort'],
  'dir' => (string) $state['dir'],
  'record' => (int) (($state['selected']['id'] ?? 0) ?: 0),
  'failedAction' => $failedAction,
  'error' => $error,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php lex_page_footer(); ?>
