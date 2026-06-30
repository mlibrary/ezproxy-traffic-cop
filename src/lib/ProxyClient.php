<?php
class ProxyClient {
  public $ip;
  public $hostname;
  public $username;
  public $url;
  public $roles;

  private $ldapStatus;
  private $ldapInfo;
  private $almaStatus;
  private $almaInfo;

  public function __construct($ip, $url, $username) {
    $this->ip = ip2long($ip);
    $this->url = $url;
    $this->hostname = strtolower((string) parse_url($url, PHP_URL_HOST));
    $this->username = $username; 
    $this->ldapStatus = false;
    $this->ldapInfo   = null;
    $this->roles      = [];
    $this->almaStatus = false;
    $this->almaInfo   = null;
  }

  public function hasEZproxyCookie() {
    if (isset($_COOKIE['ezproxy'])) {
      $opts = [
        'http'=> [
          'method'=>"GET",
          'header'=> "Cookie: ezproxy={$_COOKIE['ezproxy']}\r\n",
        ],
        'ssl' => [
          'verify_peer' => false,
          'verify_peer_name' => false,
        ]
      ];

      $context = stream_context_create($opts);
      $menu = file_get_contents('https://proxy.lib.umich.edu/menu', false, $context);
      if (mb_strpos($menu, '<title>Database Menu</title>') !== FALSE) {
        return true;
      }
    }
    return false;
  }

  public function debugInfo() {
    return "Alma Status: '{$this->almaInfo['status']['value']}'\n" .
      "Campus Code: '{$this->almaInfo['campus_code']['value']}'\n";
  }

  public function emptyInfo() {
    $this->resolveLDAP();
    $this->resolveAlma();
    return $this->emptyLDAP() && $this->emptyAlma();
  }

  public function emptyAlma() {
    $this->resolveAlma();
    return (empty($this->almaInfo));
  }

  public function resolveAlma() {
    if ($this->almaStatus) { return null; }
    $alma_url = sprintf(
      parse_ini_file(getenv('ALMA_INI'))['template'],
      $this->username
    );
    $opts = [
      'http'=> [
        'method'=>"GET",
        'header'=> "Accept: application/json\r\n",
      ]
    ];
    $context = stream_context_create($opts);

    set_error_handler(function($errno, $errstr, $errfile, $errline) {
      throw new Exception($errstr);
    });
    try {
      $response = $this->almaInfo = json_decode(file_get_contents($alma_url, false, $context), true);
    } catch (Exception $e) {
      $response = $this->almaInfo = [
        'expiry_date' => '0000-00-00',
      ];
    }
    restore_error_handler();

    if (date('Y-m-d') < $response['expiry_date']
      && $response['status']['value'] == 'ACTIVE'
      && $response['user_group']['desc'] != 'Guest') {
      if (empty($response['campus_code']['value'])) {
        $this->roles[] = 'UMAA';
      } else {
        $this->roles[] = $response['campus_code']['value'];
      }
    }
    $this->almaStatus = TRUE;
  }

  public function emptyLDAP() {
    $this->resolveLDAP();
    return (empty($this->ldapInfo));
  }

  public function resolveLDAP() {
    if ($this->ldapStatus) { return null; }
    $ldap = new Ldap();
    $this->ldapInfo = $ldap->search('search', [':uniqname' => $this->username]);
    if (empty($this->ldapInfo)) {
      sleep(1);
      $this->ldapInfo = $ldap->search('search', [':uniqname' => $this->username]);
    }
    $this->ldapStatus = true;
    $retiree = false;
    $faculty = false;
    if (!empty($this->ldapInfo[0])) {
      foreach ($this->ldapInfo[0]['umichinstroles'] as $key => $value) {
        if (!is_numeric($key)) {
          continue;
        }
        $this->roles[] = $value;

        if ($value == 'Retiree') {
          $retiree = true;
        }
        if (strpos($value, 'Faculty') === 0) {
          $faculty = true;
        }
      }
      $this->roles = $this->ldapInfo[0]['umichinstroles'];
      unset($this->roles['count']);
      if ($retiree && $faculty) {
         $this->roles[] = 'Emeritus';
      }
      if (!empty($this->ldapInfo[0]['umichsponsorshipdetail'])) {
        foreach ($this->ldapInfo[0]['umichsponsorshipdetail'] as $key => $value) {
          if (!is_numeric($key)) {
            continue;
          }
          if (preg_match("/\{umichSponsorReason=([^}]+)\}/", $value, $matches)) {
            $this->roles[] = "HasSponsorReason";
            $this->roles[] = "SponsorReason={$matches[1]}";
          }
        }
      }
    }
  }

  public function anonymous() {
    return empty($this->username);
  }

  public function blocked() {
    return FALSE;
  }
}
