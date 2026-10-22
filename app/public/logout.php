<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
require_csrf();
$_SESSION=[];
session_regenerate_id(true);
header('Location: /login.php', true, 303);
