<?php
require_once __DIR__ . '/config/bootstrap.php';

$requestedRole = strtolower(trim((string) ($_GET['role'] ?? 'client')));
if (!in_array($requestedRole, ['client', 'lawyer'], true)) {
    $requestedRole = 'client';
}

$user = lex_current_user();
$isRoleMatch = $user && in_array($user['role'], ['client', 'lawyer'], true);

$demoProfiles = [
    'client' => [
        'name' => 'Ava Mendoza',
        'title' => 'Client',
        'status' => 'In Consultation',
        'bio' => 'Corporate client with an active commercial dispute and ongoing document review. Prefers concise updates and quick turnaround on case milestones.',
        'email' => 'ava.mendoza@example.com',
        'phone' => '+1 (415) 555-0148',
        'accent' => '#2563eb',
        'profile_href' => 'profile_card.php?role=client',
        'contact_href' => 'mailto:ava.mendoza@example.com',
    ],
    'lawyer' => [
        'name' => 'Jordan Reyes, Esq.',
        'title' => 'Senior Counsel',
        'status' => 'Available',
        'bio' => 'Litigation specialist focused on commercial matters, dispute resolution, and fast client response times across sensitive case work.',
        'email' => 'jordan.reyes@example.com',
        'phone' => '+1 (212) 555-0197',
        'accent' => '#4f46e5',
        'profile_href' => 'profile_card.php?role=lawyer',
        'contact_href' => 'mailto:jordan.reyes@example.com',
    ],
];

$profile = $demoProfiles[$requestedRole];

if ($isRoleMatch) {
    if ($user['role'] === 'client') {
        $clientId = lex_user_client_id((int) $user['id']);
        $row = lex_recent(
            'SELECT c.contact_number, c.address, u.full_name, u.email
             FROM clients c
             JOIN users u ON u.id = c.user_id
             WHERE c.id = :id
             LIMIT 1',
            ['id' => $clientId]
        )[0] ?? null;
        if ($row) {
            $profile = [
                'name' => (string) $row['full_name'],
                'title' => 'Client',
                'status' => 'In Consultation',
                'bio' => trim((string) ($row['address'] ?? '')) !== '' ? (string) $row['address'] : 'Secure client account for ongoing matters.',
                'email' => (string) $row['email'],
                'phone' => (string) ($row['contact_number'] ?? 'Not on file'),
                'accent' => '#2563eb',
                'profile_href' => lex_app_url('client/profile.php'),
                'contact_href' => 'mailto:' . (string) $row['email'],
            ];
        }
    } elseif ($user['role'] === 'lawyer') {
        $lawyerId = lex_user_lawyer_id((int) $user['id']);
        $row = lex_recent(
            'SELECT l.specialization, l.bio, u.full_name, u.email
             FROM lawyers l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = :id
             LIMIT 1',
            ['id' => $lawyerId]
        )[0] ?? null;
        if ($row) {
            $profile = [
                'name' => (string) $row['full_name'],
                'title' => (string) ($row['specialization'] ?? 'Lawyer'),
                'status' => 'Available',
                'bio' => trim((string) ($row['bio'] ?? '')) !== '' ? (string) $row['bio'] : 'Experienced counsel available for consultation and matter updates.',
                'email' => (string) $row['email'],
                'phone' => 'Contact via portal',
                'accent' => '#4f46e5',
                'profile_href' => lex_app_url('lawyer/view.php?id=' . (int) $lawyerId),
                'contact_href' => 'mailto:' . (string) $row['email'],
            ];
        }
    }
}

$initials = strtoupper(substr(preg_replace('/\s+/', '', (string) $profile['name']) ?: 'PR', 0, 2));
$avatarSvg = sprintf(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%%" stop-color="%s"/><stop offset="100%%" stop-color="#0f172a"/></linearGradient></defs><rect width="128" height="128" rx="64" fill="url(#g)"/><circle cx="64" cy="50" r="24" fill="rgba(255,255,255,0.92)"/><path d="M28 112c5-21 22-33 36-33s31 12 36 33" fill="rgba(255,255,255,0.92)"/><text x="64" y="74" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="28" font-weight="800" fill="#0f172a">%s</text></svg>',
    htmlspecialchars((string) $profile['accent'], ENT_QUOTES, 'UTF-8'),
    htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
);
$avatarSrc = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($avatarSvg);

$statusLabel = (string) $profile['status'];
$roleTitle = (string) $profile['title'];
$profileHref = (string) $profile['profile_href'];
$contactHref = (string) $profile['contact_href'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profile Card | <?= lex_e(LEX_APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= lex_e(lex_app_url('public/css/style.css')) ?>">
</head>
<body class="profile-card-demo-page">
  <main class="profile-card-demo-shell">
    <article class="profile-card-demo" aria-labelledby="profileCardTitle">
      <div class="profile-card-demo__avatar-wrap">
        <img class="profile-card-demo__avatar" src="<?= lex_e($avatarSrc) ?>" alt="Profile image for <?= lex_e((string) $profile['name']) ?>">
      </div>

      <header class="profile-card-demo__header">
        <h1 class="profile-card-demo__name" id="profileCardTitle"><?= lex_e((string) $profile['name']) ?></h1>
        <p class="profile-card-demo__title"><?= lex_e($roleTitle) ?></p>
        <span class="profile-card-demo__badge"><?= lex_e($statusLabel) ?></span>
      </header>

      <p class="profile-card-demo__bio"><?= lex_e((string) $profile['bio']) ?></p>

      <div class="profile-card-demo__divider" aria-hidden="true"></div>

      <section class="profile-card-demo__info" aria-label="Contact details">
        <div class="profile-card-demo__row">
          <svg class="profile-card-demo__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 6h16v12H4z"></path>
            <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6"></path>
          </svg>
          <div class="profile-card-demo__row-body">
            <span class="profile-card-demo__row-label">Email</span>
            <strong class="profile-card-demo__row-value"><?= lex_e((string) $profile['email']) ?></strong>
          </div>
        </div>

        <div class="profile-card-demo__row">
          <svg class="profile-card-demo__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-3 2c1.5 3 3.5 5 6 6l2-3 5 2v4c0 1-1 2-2 2C9 22 2 15 2 6c0-1 1-2 2-2h1z"></path>
          </svg>
          <div class="profile-card-demo__row-body">
            <span class="profile-card-demo__row-label">Phone</span>
            <strong class="profile-card-demo__row-value"><?= lex_e((string) $profile['phone']) ?></strong>
          </div>
        </div>
      </section>

      <div class="profile-card-demo__actions" aria-label="Profile actions">
        <a class="button button-primary" href="<?= lex_e($contactHref) ?>">Contact</a>
        <a class="button button-secondary" href="<?= lex_e($profileHref) ?>">View Profile</a>
      </div>
    </article>
  </main>
</body>
</html>
