<?php
class ProxyServerList {
  public $config = null;
  public $servers = null;

  public function __construct($ini) {
    $basePath = dirname($ini);
    $this->config = parse_ini_file($ini, true);
    $this->servers = [];

    foreach ($this->config as $location => $config) {
      $this->servers[] = new ProxyServer($config, $basePath);
    }
  }

  public function any($fn, $client) {
    foreach ($this->servers as $server) {
      if ($server->$fn($client)) {
        return true;
      }
    }
    return false;
  }

  public function all($fn, $client) {
    foreach ($this->servers as $server) {
      if (!$server->$fn($client)) {
        return false;
      }
    }
    return true;
  }

  public function unsafe($client) {
    return $this->all('unsafe', $client);
  }

  public function directAccess($client) {
    return $this->any('directAccess', $client);
  }

  public function getAuthorizedLink($client) {
    foreach ($this->servers as $server) {
      if ($link = $server->getAuthorizedLink($client)) {
        return $link;
      }
    }
    return FALSE;
  }

  public function rewrite($url) {
    foreach ($this->servers as $server) {
      if ($rewrite = $server->rewrite($url)) {
        return $rewrite;
      }
    }
    return $url;
  }
}
