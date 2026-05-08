<?php
require_once __DIR__ . '/config/bootstrap.php';

$user = lex_current_user();
$searchQuery = trim(lex_sanitize_text($_GET['q'] ?? ''));
$selectedSpecialization = trim(lex_sanitize_text($_GET['specialization'] ?? ''));

$specializations = lex_recent(
    'SELECT DISTINCT l.specialization
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.status = "active"
       AND l.specialization <> ""
     ORDER BY l.specialization ASC'
);

$heroStats = lex_recent(
    'SELECT
        (SELECT COUNT(*) FROM lawyers l2 JOIN users u2 ON u2.id = l2.user_id WHERE u2.is_active = 1 AND l2.status = "active") AS active_lawyers,
        COALESCE((SELECT AVG(rating) FROM lawyer_reviews), 0) AS avg_rating,
        COALESCE((SELECT COUNT(*) FROM lawyer_reviews), 0) AS review_count'
);
$heroStats = $heroStats[0] ?? ['active_lawyers' => 0, 'avg_rating' => 0, 'review_count' => 0];

$lawyerSql = 'SELECT l.id, l.bar_number, l.specialization, l.status, l.bio, l.background, u.full_name, u.email, u.avatar_stored_name, u.created_at,
        COALESCE(stats.avg_rating, 0) AS avg_rating,
        COALESCE(stats.review_count, 0) AS review_count
 FROM lawyers l
 JOIN users u ON u.id = l.user_id
 LEFT JOIN (
    SELECT lawyer_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
    FROM lawyer_reviews
    GROUP BY lawyer_id
 ) stats ON stats.lawyer_id = l.id
 WHERE u.is_active = 1 AND l.status = "active"';
$lawyerParams = [];
if ($selectedSpecialization !== '') {
    $lawyerSql .= ' AND l.specialization = :specialization';
    $lawyerParams['specialization'] = $selectedSpecialization;
}
$searchTerm = function_exists('mb_strtolower') ? mb_strtolower($searchQuery) : strtolower($searchQuery);
if ($searchTerm !== '') {
    $tokens = preg_split('/\s+/u', $searchTerm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

    if ($tokens) {
        $searchFields = [
            'LOWER(u.full_name)',
            'LOWER(l.specialization)',
            'LOWER(l.bar_number)',
            'LOWER(COALESCE(l.background, ""))',
            'LOWER(COALESCE(l.bio, ""))',
        ];
        $searchClauses = [];

        foreach ($tokens as $index => $token) {
            $fieldClauses = [];
            foreach ($searchFields as $fieldIndex => $field) {
                $placeholder = ':search_term_' . $index . '_' . $fieldIndex;
                $fieldClauses[] = $field . ' LIKE ' . $placeholder;
                $lawyerParams['search_term_' . $index . '_' . $fieldIndex] = '%' . $token . '%';
            }
            $searchClauses[] = '(' . implode(' OR ', $fieldClauses) . ')';
        }

        $lawyerSql .= ' AND (' . implode(' AND ', $searchClauses) . ')';
    }
}
$lawyerSql .= ' ORDER BY u.full_name ASC';
$lawyers = lex_recent($lawyerSql, $lawyerParams);
$visibleLawyerCount = count($lawyers);

$portalLink = $user ? lex_app_url($user['role'] . '/index.php') : lex_app_url('auth/login.php');
$appointLink = $user && ($user['role'] === 'client')
    ? lex_app_url('client/appointment.php')
    : lex_app_url('auth/register.php');
$clearFiltersLink = lex_app_url('index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LEXSHIELD | Integrated Cybersecurity and Legal Compliance Platform for Philippine Law Firms</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= lex_e(lex_app_url('public/css/style.css')) ?>">
  <script defer src="<?= lex_e(lex_app_url('public/js/main.js')) ?>"></script>
</head>
<body>
  <main class="landing-shell">
    <section class="landing-hero card" aria-labelledby="landing-hero-title">
      <div class="landing-hero__copy">
        
        <header class="landing-hero__header">
          <h1 id="landing-hero-title">LEXSHIELD: Integrated Cybersecurity and Legal Compliance Platform for Law Firms</h1>
          <p class="landing-hero__text">LexShield is the assurance of working with high-level experts who are committed to providing you with quality legal solutions, without undue solicitation. Our firm is here to meet your legal needs with professionalism and excellence.</p>
        </header>

        <div class="landing-hero__actions">
          <a class="button button-primary" href="<?= lex_e($appointLink) ?>">Appoint now</a>
          <a class="button button-secondary" href="<?= lex_e($portalLink) ?>"><?= $user ? 'Open portal' : 'Login' ?></a>
        </div>

        <div class="landing-hero__stats" aria-label="Directory statistics">
          <div class="landing-stat"><span>Active lawyers</span><strong><?= number_format((int) $heroStats['active_lawyers']) ?></strong></div>
          <div class="landing-stat"><span>Average rating</span><strong><?= number_format((float) $heroStats['avg_rating'], 1) ?>/5</strong></div>
          <div class="landing-stat"><span>Reviews</span><strong><?= number_format((int) $heroStats['review_count']) ?></strong></div>
        </div>
      </div>

      <aside class="landing-hero__panel" aria-label="Directory guide and style notes">
        <figure class="landing-hero__image-frame">
          <img
            src="<?= lex_e(lex_app_url('public/assets/lady-justice.png')) ?>"
            alt="Lady Justice holding scales, symbolizing law and balance"
          >
          <figcaption class="landing-hero__image-caption">
            <span class="profile-kicker">Legal clarity</span>
            <strong><?= number_format($visibleLawyerCount) ?> active lawyers ready for appointment</strong>
          </figcaption>
        </figure>
      </aside>
    </section>

    <section class="card landing-directory" aria-labelledby="landing-directory-title">
      <div class="card-head">
        <div>
          
              <form class="landing-search" method="get" action="<?= lex_e(lex_app_url('index.php')) ?>" role="search" aria-label="Search and filter lawyers">
          <div class="landing-search__field landing-search__field--search">
            <label class="sr-only" for="landing-q">Search lawyers</label>
            <span class="landing-search__icon" aria-hidden="true">&#128269;</span>
            <input id="landing-q" type="search" name="q" value="<?= lex_e($searchQuery) ?>" placeholder="Search by name, specialization, or bar number" autocomplete="off">
          </div>
          <div class="landing-search__field">
            <label class="sr-only" for="landing-specialization">Filter by specialization</label>
            <select id="landing-specialization" name="specialization">
              <option value="">All specializations</option>
              <?php foreach ($specializations as $specialization): ?>
                <?php $specializationValue = (string) $specialization['specialization']; ?>
                <option value="<?= lex_e($specializationValue) ?>"<?= $selectedSpecialization === $specializationValue ? ' selected' : '' ?>><?= lex_e($specializationValue) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="landing-search__actions">
            <button class="button button-primary" type="submit">Search</button>
            <a class="button button-secondary" href="<?= lex_e($clearFiltersLink) ?>">Clear</a>
          </div>
        </form>

        </div>
        <div class="landing-directory__toolbar">
          <span class="landing-results-badge"><?= number_format($visibleLawyerCount) ?> result<?= $visibleLawyerCount === 1 ? '' : 's' ?></span>
          <a class="button button-primary" href="<?= lex_e($appointLink) ?>">Start appointment</a>
        </div>
      </div>

      <?php if (!$lawyers): ?>
        <div class="empty-state empty-state--lawyers">
          <h3>No lawyers match your current filters</h3>
          <p>
            <?php if ($searchQuery !== '' && $selectedSpecialization !== ''): ?>
              We couldn't find a lawyer for "<?= lex_e($searchQuery) ?>" in <?= lex_e($selectedSpecialization) ?>.
            <?php elseif ($searchQuery !== ''): ?>
              We couldn't find a lawyer for "<?= lex_e($searchQuery) ?>".
            <?php elseif ($selectedSpecialization !== ''): ?>
              There are no active lawyers in <?= lex_e($selectedSpecialization) ?> right now.
            <?php else: ?>
              Try a broader search or clear the filters to see every active lawyer in the directory.
            <?php endif; ?>
          </p>
          <div class="empty-state__actions">
            <?php if ($searchQuery !== '' || $selectedSpecialization !== ''): ?>
              <a class="button button-primary" href="<?= lex_e($clearFiltersLink) ?>">Reset search</a>
            <?php endif; ?>
            <a class="button button-secondary" href="<?= lex_e($appointLink) ?>">Start appointment</a>
          </div>
        </div>
      <?php else: ?>
        <div class="lawyer-directory-grid">
          <?php foreach ($lawyers as $lawyer): ?>
            <?php
              $avatarUrl = lex_profile_avatar_url((string) ($lawyer['avatar_stored_name'] ?? ''));
              $lawyerInitials = strtoupper(substr(preg_replace('/\s+/', '', (string) ($lawyer['full_name'] ?? 'LW')) ?: 'LW', 0, 2));
              $avgRating = number_format((float) ($lawyer['avg_rating'] ?? 0), 1);
              $reviewCount = (int) ($lawyer['review_count'] ?? 0);
              $background = trim((string) ($lawyer['background'] ?? ''));
              $bio = trim((string) ($lawyer['bio'] ?? ''));
              $statusClass = strtolower((string) $lawyer['status']) === 'active' ? 'is-active' : 'is-inactive';
              $ratingValue = (float) ($lawyer['avg_rating'] ?? 0);
              $filledStars = max(0, min(5, (int) round($ratingValue)));
              $ratingPercent = max(0, min(100, (int) round(($ratingValue / 5) * 100)));
              $lawyerName = (string) $lawyer['full_name'];
            ?>
            <article class="lawyer-directory-card" aria-labelledby="lawyer-<?= (int) $lawyer['id'] ?>-title">
              <header class="lawyer-directory-card__header">
                <div class="lawyer-directory-card__identity">
                  <div class="lawyer-avatar">
                    <?php if ($avatarUrl !== ''): ?>
                      <img src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e($lawyerName) ?>">
                    <?php else: ?>
                      <span><?= lex_e($lawyerInitials) ?></span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <h3 id="lawyer-<?= (int) $lawyer['id'] ?>-title"><?= lex_e($lawyerName) ?></h3>
                    <p><?= lex_e((string) $lawyer['specialization']) ?></p>
                  </div>
                </div>
                <span class="pill <?= lex_e($statusClass) ?>"><?= lex_e((string) $lawyer['status']) ?></span>
              </header>

              <div class="lawyer-directory-card__meta">
                <div class="lawyer-rating-card">
                  <span>Rating</span>
                  <strong><?= lex_e($avgRating) ?>/5</strong>
                  <div class="rating-stars" aria-label="<?= lex_e($avgRating) ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                      <span class="<?= $i <= $filledStars ? 'is-filled' : '' ?>" aria-hidden="true">&#9733;</span>
                    <?php endfor; ?>
                  </div>
                  <div class="rating-bar" aria-hidden="true"><span style="width: <?= (int) $ratingPercent ?>%"></span></div>
                </div>
                <div><span>Reviews</span><strong><?= number_format($reviewCount) ?></strong></div>
                <div><span>Bar</span><strong><?= lex_e((string) $lawyer['bar_number']) ?></strong></div>
              </div>

              <p class="lawyer-directory-card__background">
                <?= lex_e($background !== '' ? $background : $bio) ?>
              </p>

              <div class="lawyer-directory-card__actions">
                <div class="lawyer-directory-card__action-group">
                  <a class="button button-primary" href="<?= lex_e($user && $user['role'] === 'client' ? lex_app_url('client/appointment.php?lawyer_id=' . (int) $lawyer['id']) : lex_app_url('auth/register.php')) ?>">
                    Appoint
                  </a>
                  <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/view.php?id=' . (int) $lawyer['id'])) ?>">View profile</a>
                </div>
                <?php if ($user && $user['role'] === 'client'): ?>
                  <span class="muted">Prefills your appointment page.</span>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <footer class="card landing-footer">
      <center><p class="muted">LEXSHIELD.</p></center>
    </footer>
  </main>
</body>
</html>
