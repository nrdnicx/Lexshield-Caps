<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_current_user();
$lawyerId = lex_sanitize_int($_GET['id'] ?? 0);

$lawyer = null;
if ($lawyerId > 0) {
    $rows = lex_recent(
        'SELECT l.id, l.bar_number, l.specialization, l.status, l.bio, l.background, u.full_name, u.email, u.avatar_stored_name, u.created_at,
                COALESCE(stats.avg_rating, 0) AS avg_rating,
                COALESCE(stats.review_count, 0) AS review_count
         FROM lawyers l
         JOIN users u ON u.id = l.user_id
         LEFT JOIN (
            SELECT lawyer_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
            FROM lawyer_reviews
            GROUP BY lawyer_id
         ) stats ON stats.lawyer_id = l.id
         WHERE l.id = :id
           AND u.is_active = 1
           AND l.status = "active"
         LIMIT 1',
        ['id' => $lawyerId]
    );
    $lawyer = $rows[0] ?? null;
}

$reviews = $lawyerId > 0 ? lex_recent(
    'SELECT r.rating, r.comment, r.created_at, u.full_name AS client_name
     FROM lawyer_reviews r
     JOIN clients c ON c.id = r.client_id
     JOIN users u ON u.id = c.user_id
     WHERE r.lawyer_id = :id
     ORDER BY r.created_at DESC
     LIMIT 8',
    ['id' => $lawyerId]
) : [];

$ratingValue = (float) ($lawyer['avg_rating'] ?? 0);
$filledStars = max(0, min(5, (int) round($ratingValue)));
$ratingPercent = max(0, min(100, (int) round(($ratingValue / 5) * 100)));
$avatarUrl = $lawyer ? lex_profile_avatar_url((string) ($lawyer['avatar_stored_name'] ?? '')) : '';
$initials = $lawyer ? strtoupper(substr(preg_replace('/\s+/', '', (string) ($lawyer['full_name'] ?? 'LW')) ?: 'LW', 0, 2)) : 'LW';
$ctaLink = $user && ($user['role'] === 'client')
    ? lex_app_url('client/appointment.php?lawyer_id=' . $lawyerId)
    : lex_app_url('auth/register.php');
$defaultBackLink = lex_app_url('index.php');
$returnTo = (string) ($_GET['return_to'] ?? '');
$backLink = $defaultBackLink;
$backLinkLabel = 'Back to home';
if ($returnTo !== '') {
    $allowedBackLinks = [
        lex_app_url('index.php'),
        lex_app_url('client/lawyers.php'),
    ];

    foreach ($allowedBackLinks as $allowedBackLink) {
        if ($returnTo === $allowedBackLink || str_starts_with($returnTo, $allowedBackLink . '?')) {
            $backLink = $returnTo;
            $backLinkLabel = $allowedBackLink === lex_app_url('client/lawyers.php') ? 'Back to lawyers' : 'Back to home';
            break;
        }
    }
}

$maskReviewerName = static function (string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        return 'Anonymous';
    }

    $parts = preg_split('/\s+/u', $name) ?: [];
    if (count($parts) === 1) {
        return preg_replace('/./u', '*', $parts[0]) ?: '*****';
    }

    $firstName = array_shift($parts);
    $lastName = (string) array_pop($parts);
    $maskedLastName = preg_replace('/./u', '*', $lastName) ?: '*****';

    return trim($firstName . ' ' . $maskedLastName);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $lawyer ? lex_e((string) $lawyer['full_name']) : 'Lawyer Profile' ?> | LEXSHIELD</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= lex_e(lex_app_url('public/css/style.css')) ?>">
  <script defer src="<?= lex_e(lex_app_url('public/js/main.js')) ?>"></script>
</head>
<body>
  <main class="landing-shell">
    <section class="card public-lawyer-card">
      <?php if (!$lawyer): ?>
        <div class="public-lawyer-empty">
          <div class="hero-badge">LEXSHIELD Lawyer</div>
          <h1>Lawyer not found.</h1>
          <p class="muted">The lawyer you selected may be inactive or no longer available.</p>
          <div class="landing-hero__actions">
            <a class="button button-primary" href="<?= lex_e($backLink) ?>"><?= lex_e($backLinkLabel) ?></a>
            <a class="button button-secondary" href="<?= lex_e($user ? lex_app_url($user['role'] . '/index.php') : lex_app_url('auth/login.php')) ?>">Open portal</a>
          </div>
        </div>
      <?php else: ?>
        <section class="landing-hero public-lawyer-hero">
          <div class="landing-hero__copy">
            <div class="hero-badge">Lawyer Profile</div>
            <div class="public-lawyer-header">
              <div class="lawyer-avatar public-lawyer-avatar">
                <?php if ($avatarUrl !== ''): ?>
                  <img src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e((string) $lawyer['full_name']) ?>">
                <?php else: ?>
                  <span><?= lex_e($initials) ?></span>
                <?php endif; ?>
              </div>
              <div>
                <h1><?= lex_e((string) $lawyer['full_name']) ?></h1>
                <p class="landing-hero__text"><?= lex_e((string) $lawyer['specialization']) ?></p>
                <div class="public-lawyer-pills">
                  <span class="pill"><?= lex_e((string) $lawyer['status']) ?></span>
                  <span class="pill"><?= lex_e((string) $lawyer['bar_number']) ?></span>
                </div>
              </div>
            </div>
            <div class="lawyer-directory-card__meta public-lawyer-meta">
              <div class="lawyer-rating-card">
                <span>Rating</span>
                <strong><?= number_format($ratingValue, 1) ?>/5</strong>
                <div class="rating-stars" aria-label="<?= number_format($ratingValue, 1) ?> out of 5">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="<?= $i <= $filledStars ? 'is-filled' : '' ?>">★</span>
                  <?php endfor; ?>
                </div>
                <div class="rating-bar" aria-hidden="true"><span style="width: <?= (int) $ratingPercent ?>%"></span></div>
              </div>
              <div><span>Reviews</span><strong><?= number_format((int) ($lawyer['review_count'] ?? 0)) ?></strong></div>
              <div><span>Joined</span><strong><?= lex_e((string) $lawyer['created_at']) ?></strong></div>
            </div>
            <p class="landing-hero__text"><?= lex_e(trim((string) ($lawyer['background'] ?: $lawyer['bio']))) ?></p>
            <div class="landing-hero__actions">
              <a class="button button-primary" href="<?= lex_e($ctaLink) ?>">Appoint</a>
              <a class="button button-secondary" href="<?= lex_e($backLink) ?>"><?= lex_e($backLinkLabel) ?></a>
            </div>
          </div>

          <div class="landing-hero__panel">
            <div class="public-lawyer-portrait">
              <?php if ($avatarUrl !== ''): ?>
                <img src="<?= lex_e($avatarUrl) ?>" alt="Portrait of <?= lex_e((string) $lawyer['full_name']) ?>">
              <?php else: ?>
                <div class="public-lawyer-portrait__fallback" aria-hidden="true">
                  <span><?= lex_e($initials) ?></span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="card public-lawyer-content">
          <div class="card-head">
            <div>
              <h2>Recent Reviews</h2>
              <p class="muted">Comments from clients who have already rated this lawyer.</p>
            </div>
          </div>
          <div class="public-review-list">
            <?php foreach ($reviews as $review): ?>
              <article class="public-review-item">
                <div class="public-review-item__top">
                  <strong><?= lex_e($maskReviewerName((string) $review['client_name'])) ?></strong>
                  <span class="pill"><?= (int) $review['rating'] ?>/5</span>
                </div>
                <div class="rating-stars" aria-hidden="true">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="<?= $i <= (int) $review['rating'] ? 'is-filled' : '' ?>">★</span>
                  <?php endfor; ?>
                </div>
                <p><?= lex_e((string) ($review['comment'] ?: 'No comment provided.')) ?></p>
              </article>
            <?php endforeach; ?>
            <?php if (!$reviews): ?>
              <p class="muted">No reviews yet. Be the first to leave feedback after your appointment.</p>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
