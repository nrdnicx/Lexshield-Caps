<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);
$selectedSpecialization = trim(lex_sanitize_text($_GET['specialization'] ?? ''));
$message = '';
$error = '';
$isJsonRequest = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || str_contains(strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')), 'xmlhttprequest');

$specializations = lex_recent(
    'SELECT DISTINCT l.specialization
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.specialization <> ""
     ORDER BY l.specialization ASC'
);

$lawyerSql = 'SELECT l.id, l.bar_number, l.specialization, l.status, l.bio, l.background, u.full_name, u.email, u.avatar_stored_name,
        COALESCE(stats.avg_rating, 0) AS avg_rating,
        COALESCE(stats.review_count, 0) AS review_count,
        my.rating AS my_rating,
        my.comment AS my_comment
 FROM lawyers l
 JOIN users u ON u.id = l.user_id
 LEFT JOIN (
    SELECT lawyer_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
    FROM lawyer_reviews
    GROUP BY lawyer_id
 ) stats ON stats.lawyer_id = l.id
 LEFT JOIN lawyer_reviews my ON my.lawyer_id = l.id AND my.client_id = :client_id
 WHERE u.is_active = 1';
$lawyerParams = ['client_id' => $clientId];
if ($selectedSpecialization !== '') {
    $lawyerSql .= ' AND l.specialization = :specialization';
    $lawyerParams['specialization'] = $selectedSpecialization;
}
$lawyerSql .= ' ORDER BY u.full_name ASC';
$returnTo = lex_app_url('client/lawyers.php' . ($selectedSpecialization !== '' ? '?specialization=' . rawurlencode($selectedSpecialization) : ''));

$normalizeLawyer = static function (array $row) use ($clientId, $returnTo): array {
    $ratingValue = round((float) ($row['avg_rating'] ?? 0), 1);
    $reviewCount = (int) ($row['review_count'] ?? 0);
    $summary = trim((string) ($row['background'] ?: $row['bio']));
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['full_name'],
        'specialization' => (string) ($row['specialization'] ?? ''),
        'status' => (string) ($row['status'] ?? 'inactive'),
        'rating' => $ratingValue,
        'reviewsCount' => $reviewCount,
        'barRoll' => (string) ($row['bar_number'] ?? ''),
        'bio' => $summary !== '' ? $summary : 'No bio provided yet.',
        'avatarUrl' => lex_profile_avatar_url((string) ($row['avatar_stored_name'] ?? '')),
        'appointUrl' => lex_app_url('client/appointment.php?lawyer_id=' . (int) $row['id']),
        'viewProfileUrl' => lex_app_url('lawyer/view.php?id=' . (int) $row['id'] . '&return_to=' . rawurlencode($returnTo)),
        'canAppoint' => strtolower((string) ($row['status'] ?? '')) === 'active',
        'review' => [
            'rating' => (int) ($row['my_rating'] ?? 0),
            'comment' => (string) ($row['my_comment'] ?? ''),
        ],
    ];
};

$lawyers = array_map($normalizeLawyer, lex_recent($lawyerSql, $lawyerParams));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token.';
    } elseif ((string) ($_POST['action'] ?? '') === 'review') {
        $lawyerId = lex_sanitize_int($_POST['lawyer_id'] ?? 0);
        $rating = lex_sanitize_int($_POST['rating'] ?? 0);
        $comment = lex_sanitize_text($_POST['comment'] ?? '');

        $stmt = $pdo->prepare(
            'SELECT l.id
             FROM lawyers l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = :lawyer_id
               AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['lawyer_id' => $lawyerId]);
        $lawyerExists = (int) ($stmt->fetchColumn() ?: 0);

        if (!$lawyerExists) {
            $error = 'Choose a lawyer first.';
        } elseif ($rating < 1 || $rating > 5) {
            $error = 'Choose a rating from 1 to 5.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO lawyer_reviews (lawyer_id, client_id, rating, comment)
                 VALUES (:lawyer_id, :client_id, :rating, :comment)
                 ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), updated_at = NOW()'
            );
            $stmt->execute([
                'lawyer_id' => $lawyerId,
                'client_id' => $clientId,
                'rating' => $rating,
                'comment' => $comment !== '' ? $comment : null,
            ]);
            lex_audit('rate_lawyer', 'lawyer_reviews', (string) $lawyerId);

            $updatedRows = lex_recent(
                preg_replace('/ ORDER BY u\.full_name ASC$/', ' AND l.id = :lawyer_id ORDER BY u.full_name ASC', $lawyerSql),
                $lawyerParams + ['lawyer_id' => $lawyerId]
            );
            $updatedLawyer = $updatedRows[0] ?? null;
            $payload = [
                'ok' => true,
                'message' => ($rating === 0 ? 'Review saved.' : 'Review saved.'),
                'lawyer' => $updatedLawyer ? $normalizeLawyer($updatedLawyer) : null,
            ];

            if ($isJsonRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $redirect = $returnTo;
            lex_flash_set('success', 'Your review was saved.');
            header('Location: ' . $redirect);
            exit;
        }
    } else {
        $error = 'Unsupported action.';
    }

    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8', true, 422);
        echo json_encode([
            'ok' => false,
            'message' => $error !== '' ? $error : 'Unable to save the review.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$pageProps = [
    'csrfToken' => lex_csrf_token(),
    'reviewEndpoint' => $returnTo,
    'lawyers' => $lawyers,
    'useSampleFallback' => !$lawyers && $selectedSpecialization === '',
    'specialization' => $selectedSpecialization,
    'specializations' => array_map(static fn (array $row): string => (string) $row['specialization'], $specializations),
];

lex_page_header('Lawyers', 'lawyers', $user);
?>
<section class="card client-lawyers-card client-lawyers-react-card">
  <div class="card-head">
    <div>
      <h2>Lawyers</h2>
      <p class="muted">Browse active and inactive lawyers, filter by specialization, appoint counsel, and leave structured reviews.</p>
    </div>
    <div class="landing-directory__toolbar">
      <form method="get" class="landing-filter">
        <label class="sr-only" for="client-specialization">Filter by specialization</label>
        <select id="client-specialization" name="specialization" onchange="this.form.submit()">
          <option value="">All specializations</option>
          <?php foreach ($specializations as $specialization): ?>
            <?php $specializationValue = (string) $specialization['specialization']; ?>
            <option value="<?= lex_e($specializationValue) ?>"<?= $selectedSpecialization === $specializationValue ? ' selected' : '' ?>><?= lex_e($specializationValue) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="button button-secondary" type="submit">Filter</button></noscript>
      </form>
      <a class="button button-primary" href="<?= lex_e(lex_app_url('client/appointment.php')) ?>">Book appointment</a>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>

  <?php if (!$lawyers && $selectedSpecialization !== ''): ?>
    <div class="empty-state empty-state--lawyers">
      <h3>No lawyers match this filter</h3>
      <p>Try a different specialization or clear the filter to see every lawyer in the directory.</p>
      <a class="button button-primary" href="<?= lex_e(lex_app_url('client/lawyers.php')) ?>">Show all lawyers</a>
    </div>
  <?php else: ?>
    <script type="application/json" id="lawyers-app-data"><?= json_encode($pageProps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
    <div id="lawyers-app" class="lawyers-react-root" aria-live="polite"></div>
  <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/react@18/umd/react.development.js" crossorigin></script>
<script src="https://cdn.jsdelivr.net/npm/react-dom@18/umd/react-dom.development.js" crossorigin></script>
<script src="https://cdn.jsdelivr.net/npm/@babel/standalone/babel.min.js" crossorigin></script>
<script type="text/babel" data-presets="env,react" src="<?= lex_e(lex_app_url('public/js/lawyers.js')) ?>"></script>
<?php lex_page_footer(); ?>
