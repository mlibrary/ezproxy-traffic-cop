<?php
function manage_cookies($threshold = 6000) {
  $keep_these_cookies = [
    "skynet", "STICKY", "rack.session", "_ga", "_gid", "_gat", "_clck",
    "_clsk", "ezproxy", "ezproxyl", "ezproxyn", "mod_auth_openidc_session",
  ];
  if (empty($_SERVER['HTTP_COOKIE']) || strlen($_SERVER['HTTP_COOKIE']) < $threshold) {
    return false;
  }
  $count = 0;
  foreach (array_keys($_COOKIE) as $name) {
    if (in_array($name, $keep_these_cookies)) { continue; }
    setcookie($name, "", 1, "", ".umich.edu");
    setcookie($name, "", 1, "/", ".umich.edu");
    if ($count++ > 10) {
      break;
    }
  }
}
