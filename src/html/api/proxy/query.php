<?php

foreach (array('format','arg') as $var) {
  if (isset($_REQUEST[$var])) {
    $$var = $_REQUEST[$var];
  } else {
    $$var = false;
  }
}

if(!(substr($arg, 0, 7) == 'http://' || substr($arg, 0, 8) == 'https://')) {
  $arg = 'http://' . $arg;
}

$ezproxy = 'http://proxy.lib.umich.edu/proxy_url?xml=';
$request = '<?xml version="1.0"?><proxy_url_request password="' . getenv('EZPROXY_API_PASSWORD') . '"><urls><url>%s</url></urls></proxy_url_request>';

$url = $ezproxy . rawurlencode(sprintf($request,htmlentities($arg)));$ezproxy = 'http://proxy.lib.umich.edu/proxy_url?xml=';

$xml = new simpleXmlElement(file_get_contents($url));

$out = [];
$elements = $xml->xpath('./proxy_urls/url');
foreach ($elements[0]->attributes() as $name => $value) {
  $out[$name] = (string) $value;
}
$out['url'] = (string) $elements[0];

switch($format) {
    case 'text':
        header('Content-Type: text/plain');
        printf("%5s\t%s\n",$out['proxy'],$out['url']);
        break;   
    case 'json':
        header('Content-Type: text/javascript');
        print json_encode($out);
        break;
}
