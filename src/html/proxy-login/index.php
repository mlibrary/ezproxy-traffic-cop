<?php
require_once(getenv("APP_HOME") . '/lib/setup.php');
manage_cookies();

$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

if (isset($_GET['qurl'])) {
  $url    = rawurldecode($_GET['qurl']);
}
else {
  $url    = substr(
    $_SERVER['QUERY_STRING'],
    strpos($_SERVER['QUERY_STRING'], 'url=') + 4,
    strlen($_SERVER['QUERY_STRING'])
  );
}

if (isset($_SERVER['REMOTE_USER'])) {
  $username = $_SERVER['REMOTE_USER'];
}
else {
  $username = NULL;
}

try {
  if (file_exists(getenv("APP_HOME") . "/config/custom_user_redirect.json")) {
    $custom_user_redirect = json_decode(file_get_contents(getenv("APP_HOME") . "/config/custom_user_redirect.json"), true);
    if (isset($custom_user_redirect[$username])) {
      header("Location: " . $custom_user_redirect[$username]);
      exit(0);
    }
  }
}
catch (Exception $e) { }

$servers = new ProxyServerList(getenv('SERVERS_INI'));
$url = $servers->rewrite($url);
$client = new ProxyClient($ip, $url, $username);

if ($servers->unsafe($client)) {
  proxy_server_problem();
  exit(0);
}

if ($servers->directAccess($client)) {
  header('Location: ' . $url);
  exit(0);
}

if ($client->hasEZproxyCookie()) {
  header('Location: https://proxy.lib.umich.edu/login?qurl=' . rawurlencode($url));
  exit(0);
}

if ($client->anonymous()) {
  $scheme = $_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? $_SERVER['REQUEST_SCHEME'] ?? 'https';
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
  header("Location: {$scheme}://{$host}/login?dest=/proxy-login/?qurl=" . rawurlencode($url));
  exit(0);
}

if ($client->emptyInfo()) {
  noinfo();
  exit(0);
}

if ($destination = $servers->getAuthorizedLink($client)) {
  header('Location: ' . $destination);
  exit(0);
}

noaccess();
