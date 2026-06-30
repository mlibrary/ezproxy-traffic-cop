<?php

function find_proxy($host, $ip, $roles) {
  print_r($host);
  print_r($ip);
  print_r($roles);
}

function proxied($host, $ip) {
  if ($host == 'search.proquest.com') {
    return TRUE;
  }
  return FALSE;
}

function safe($host) {
  if ($host == 'www.google.com') {
    return TRUE;
  }
  return FALSE;
}
