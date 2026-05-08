<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
http_response_code(410);
exit('Payments export coming soon.');
