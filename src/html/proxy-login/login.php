<?php

if (strpos($_SERVER['REQUEST_URI'], '/login') === 0) {
  mlibrary_login();
}

function mlibrary_login() {
  $dest = $_GET['dest'] ?? '/';
  $scheme = $_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? $_SERVER['REQUEST_SCHEME'] ?? 'https';
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
  
  if (strpos($_SERVER['QUERY_STRING'], 'dest=/proxy-login/?') === 0) {
    $path = mb_substr($_SERVER['QUERY_STRING'], 5, mb_strlen($_SERVER['QUERY_STRING']));
    header("Location: {$scheme}://{$host}{$path}");
    exit();
  }
  elseif (strpos($_SERVER['QUERY_STRING'], 'q=login&dest=/proxy-login/?') === 0) {
    $path = mb_substr($_SERVER['QUERY_STRING'], 13, mb_strlen($_SERVER['QUERY_STRING']));
    header("Location: {$scheme}://{$host}{$path}");
    exit();
  }
  elseif (strpos($dest, '/') === 0) {
    header("Location: {$scheme}://{$host}{$dest}");
    exit();
  }
  elseif (strpos($dest, 'https://') !== 0 && strpos($dest, 'http://') !== 0) {
    list($path, $query_string) = explode('?', $dest, 2);
    if (empty($query_string)) {
      header("Location: {$scheme}://{$host}{$path}");
      exit();
    }
    else {
      $query = [];
      foreach (explode('&', $query_string) as $kv) {
        list($key, $value) = explode('=', $kv, 2);
        $query[$key] = $value;
      }
      header("Location: {$scheme}://{$host}{$path}?{$query_string}");
      exit();
    }
  }
  else {
    header("Location: {$scheme}://{$host}/");
    exit();
  }
}
