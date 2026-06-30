<?php

$url = $_SERVER['QUERY_STRING'];
$content_code = false;

if (preg_match('@^https://www.astm.org/DIGITAL_LIBRARY/STP/SOURCE_PAGES/(.+)\.htm$@', $url, $matches)) {
  $content_code = "ASTM|" . strtoupper($matches[1]) . "-EB|en-US";
} elseif (preg_match('@^https?://www.astm.org/cgi-bin/resolver.cgi\?(.+)$@', $url, $matches)) {
  $content_code = "ASTM|" . strtoupper($matches[1]) . "|en-US";
} elseif (preg_match('@^https?://store.astm.org/(.+)\.html$@', $url, $matches)) {
  $content_code = "ASTM|" . strtoupper($matches[1]) . "|en-US";
}

if (!$content_code) {
  $compass_url = "https://compass.astm.org";
} else {
  $compass_url = "https://compass.astm.org/document/?contentCode=" . rawurlencode($content_code) . "&proxycl=https%3A%2F%2Fsecure.astm.org&fromLogin=true";
}

$secure_url = "https://secure.astm.org/login?redirectUrl=" . str_replace("=", "~", base64_encode($compass_url)) . "&newApproach=true";
header("Location: https://proxy.lib.umich.edu/login?qurl=" . rawurlencode($secure_url));
