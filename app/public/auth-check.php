<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
http_response_code(\DockerManger\Auth::loggedIn() ? 204 : 401);
header('Cache-Control: no-store');
