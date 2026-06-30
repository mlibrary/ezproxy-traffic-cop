<?php

//A class for accessing the ldap server
class ldap {
  // Windows FILETIME starts atg 1601-01-01T00:00:00Z.
  // And it is in units of 1/100 nanoseconds.
  const FILETIME_TICK = 10000000;
  const FILETIME_START = 11644473600;

  public $server = 'ldap.umich.edu';
  public $dn = 'dc=umich,dc=edu';
  public $link = null;
  public $config = null;
  public $bind_dn = null;
  public $bind_pw = null;

  public function __construct ($config = NULL) {
    $this->load_config($config);
    //Nothing to do here, it's all initialized already.
  }

  public static function ft2ut($ft) {
    return(((int) $ft/self::FILETIME_TICK) - self::FILETIME_START);
  }

  public function load_config($file) {
    if (is_null($file)) {
      $file = getenv('LDAP_INI');
    }
    $this->config = parse_ini_file($file, TRUE);

    if (isset($this->config['env'])) {
      foreach ($this->config['env'] as $name => $value) {
         putenv("$name=$value");
      }
    }

    $this->bind_dn = $this->config['bind']['dn'];
    $this->bind_pw = $this->config['bind']['pw'];
    $this->server  = $this->config['connect']['uri'];
  }

  public function search($name, $placeholders = []) {
    $return = [];

    if (isset($this->config[$name]['dn'])) {
      $this->connect();

      $dn = $this->config[$name]['dn'];
      $filter = $this->config[$name]['filter'];

      // Replace any placeholders in the filter.
      $replace = [];
      if (!empty($this->config[$name]['placeholders'])) {
        foreach ($this->config[$name]['placeholders'] as $placeholder) {
          if (isset($placeholders[$placeholder])) {
            $replace[] = $placeholders[$placeholder];
          }
          else {
            $replace[] = '';
          }
        }
        $filter = str_replace($this->config[$name]['placeholders'], $replace, $filter);
        $dn = str_replace($this->config[$name]['placeholders'], $replace, $dn);
      }

      $result = ldap_search($this->link, $dn, $filter);
      $entries =  ldap_get_entries($this->link, $result);
      unset($entries['count']);

      if (!empty($this->config[$name]['subquery'])) {
        $subquery = $this->config[$name]['subquery'];
        $attribute = $this->config[$name]['attribute'];
        foreach ($entries as $entry) {
          unset($entry[$attribute]['count']);
          foreach ($entry[$attribute] as $value) {
            $new_params = [":{$attribute}" => $value];
            foreach ($this->search($subquery, $new_params) as $sub_entry) {
              $return[] = $sub_entry;
            }
          }
        }
      }
      else {
          $return = $entries;
      }
    }
    return($return);
  }

  public function connect ($attempts = 3) {
    //If it's already connected, don't bother doing it again.
    if( ! is_null($this->link) ) {
      return $this->link;
    }

    //Otherwise, connect to our sever.
    $this->link = ldap_connect($this->server);

    if ($this->link && !empty($this->bind_dn)) {
      $bind = ldap_bind($this->link, $this->bind_dn, $this->bind_pw);
      if (!$bind && $attempts > 0) {
        $this->link = null;
        sleep(1);
        return $this->connect($attempts - 1);
      }
    }
    return $this->link;
  }

  public function query($filter) {
    //If we're not connected, connect.
    if (is_null($this->link)) {
      $this->connect();
    }

    //Execute the search.
    $res = ldap_search( $this->link, $this->dn, $filter);

    //return the results.
    return ldap_get_entries($this->link,$res);
  }

  public function get_by_uniqname($uid=null) {
    //We're searching on uid, there's no reason to search if we don't have one.
    if (is_null($uid)) {
      return false;
    }

    return $this->query("(uid=$uid)");
  }
        
  public function is_member($uid=null,$cn=null) {
    //We're searching on uid or cn, there's no reason to search if we don't have one.
    if (is_null($uid) || is_null($cn) ) {
      return $false;
    }

    //If we're not connected, connect.
    if (is_null($this->link)) {
      $this->connect();
    }
    //Execute the search.
    $res = ldap_search($this->link, $this->dn,"(&(objectclass=rfc822mailgroup)(|(rfc822mail=$uid)(member=uid=$uid,ou=People,dc=umich,dc=edu))(cn=$cn))");
    $ent = ldap_get_entries($this->link,$res);

    //return the count of results 0 => false, >0 => true 
    return $ent['count'];
  }

  public function get_memberships($uid=null) {
    //We're searching on uid, there's no reason to search if we don't have one.
    if (is_null($uid)) {
      return $false;
    }

    //If we're not connected, connect.
    if (is_null($this->link)) {
      $this->connect();
    }
    //Execute the search.
    $res = ldap_search( $this->link, $this->dn,"(&(objectclass=rfc822mailgroup)(|(rfc822mail=$uid)(member=uid=$uid,ou=People,dc=umich,dc=edu)))");

    //return the count of results 0 => false, >0 => true
    return ldap_get_entries($this->link,$res);
  }
}
