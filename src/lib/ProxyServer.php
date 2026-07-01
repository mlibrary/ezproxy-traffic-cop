<?php

class ProxyServer {
  public $path = null;
  public $server = null;
  public $hash = null;
  public $secret = null;
  public $roles = null;
  public $ipRanges = null;
  public $proxiedOnCampus = null;
  public $domainsOnCampus = null;
  public $safe = null;
  public $proxied = null;
  public $domains = null;
  public $basePath = null;

  public function __construct($config, $basePath = '.') {
    $this->path     = mb_substr($config['path'], 0, 1) === '/' ? $config['path'] : $basePath . '/' . $config['path'];
    $this->server   = $config['server'];
    $this->hash     = $config['hash'];
    $this->secret   = $config['secret'];
    $this->roles    = $config['roles'];
    $this->ipRanges = $this->parseRanges($config['ip.ranges']);

    $this->proxiedOnCampus = isset($config['proxiedOnCampus']) ? $config['proxiedOnCampus'] : [];
    $this->domainsOnCampus = isset($config['domainsOnCampus']) ? $config['domainsOnCampus'] : [];

    $this->safe    = [];
    $this->proxied = [];
    $this->domains = [];

    $this->init();
  }

  private function parseRanges($ranges) {
    $ret = [];
    foreach ($ranges as $range) {
      list($low, $high) = explode('-', $range, 2);
      $ret[] = [ip2long(trim($low)), ip2long(trim($high))];
    }
    return $ret;
  }

  private function init() {
    foreach (glob("{$this->path}/*.[tc][xf][tg]") as $file) {
      $data = file_get_contents($file);
      foreach (explode("\n", $data) as $line) {
        $line = preg_replace('/\s+/', ' ', $line);
        if (preg_match('/^(Host|H|HostJavascript|HJ|URL) [^ ]+$/i', $line)) {
          list($directive, $url) = explode(' ', $line, 2);
          $hostname = strtolower((string) parse_url($url, PHP_URL_HOST));
          if (empty($hostname)) {
            $hostname = strtolower((string) parse_url($url, PHP_URL_PATH));
          }
          if (!empty($hostname)) {
            $this->proxied[] = $hostname;
          }
        }
        elseif (preg_match('/^(Domain|DomainJavascript|DJ|D) [^ ]+$/i', $line)) {
          list($directive, $url) = explode(' ', $line, 2);
          $hostname = strtolower((string) parse_url($url, PHP_URL_HOST));
          if (empty($hostname)) {
            $hostname = strtolower((string) parse_url($url, PHP_URL_PATH));
          }
          if (!empty($hostname)) {
            $this->domains[] = $hostname;
          }
        }
        elseif (preg_match('/^(RedirectSafe|NeverProxy) [^ ]+$/i', $line)) {
          list($directive, $hostname) = explode(' ', $line, 2);
          if (!empty($hostname)) {
            $this->safe[] = strtolower($hostname);
          }
        }
      }
    }
  }

  public function unsafe($client) {
    return !$this->isSafe($client) && !$this->isProxied($client);
  }

  public function isSafe($client) {
    return in_array($client->hostname, $this->safe);
  }

  public function isProxied($client) {
    return in_array($client->hostname, $this->proxied) || $this->proxiedDomain($client);
  }

  public function isProxiedOnCampus($client) {
    return in_array($client->hostname, $this->proxiedOnCampus) || $this->proxiedDomainOnCampus($client);
  }

  public function proxiedDomain($client) {
    return $this->checkHostnameAgainstDomains($client->hostname, $this->domains);
  }

  public function proxiedDomainOnCampus($client) {
    return $this->checkHostnameAgainstDomains($client->hostname, $this->domainsOnCampus);
  }

  public function checkHostnameAgainstDomains($hostname, $domains) {
    $client_length = strlen($hostname);
    foreach ($domains as $domain) {
      $length = strlen($domain);
      if ($client_length >= $length &&
          substr_compare($hostname, $domain, -$length) === 0) {
        return true;
      }
    }
    return false;
  }

  public function onCampus($ip) {
    foreach ($this->ipRanges as $range) {
      list($low, $high) = $range;
      if ($low <= $ip && $ip < $high) {
        return true;
      }
    }
    return false;
  }

  public function directAccess($client) {
    if ($this->isSafe($client)) {
      return true;
    }

    if (!$this->isProxiedOnCampus($client) &&
        $this->isProxied($client) &&
        $this->onCampus($client->ip)) {
      return true;
    }
  }

  public function authorized($client) {
    if (empty(array_intersect($this->roles, $client->roles))) {
      return false;
    }
    return true;
  }

  public function getAuthorizedLink($client) {
    if (!$this->authorized($client)) {
      return false;
    }
    $ezproxy = new EZProxyTicket(
      $this->hash,
      $this->server,
      $this->secret,
      $client->username
    );
    return $ezproxy->url($client->url);
  }
}
