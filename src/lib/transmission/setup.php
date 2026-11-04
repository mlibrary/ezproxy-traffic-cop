<?php
require_once(__DIR__ . '/generate.php');
require_once(__DIR__ . '/receiver.php');
require_once(__DIR__ . '/sender.php');

function setup() {
  if (file_exists(getenv("APP_HOME") . '/config/receiver.ini')) {
    $config = parse_ini_file(getenv("APP_HOME") . '/config/receiver.ini', true);
  }
  elseif (file_exists(getenv("RECEIVER_INI"))) {
    $config = parse_ini_file(getenv("RECEIVER_INI"), true);
  }
  else {
    $config = [
      'public_key_path' => '/dev/null',
      'private_key_path' => '/dev/null',
      'staging_root' => '/dev/null',
      'release_root' => '/dev/null',
      'lock_path' => '/dev/null',
      'max_archive_size' => 100,
      'max_file_size' => 25,
      'max_expanded_size' => 250,
      'max_files' => 10000,
      'max_path_length' => 240,
      'max_path_depth' => 20,
      'connect_timeout_seconds' => 10,
      'total_timeout_seconds' => 300,
      'response_limit' => 1
    ];
  }

  if (!isset($config) || empty($config)) {
    throw new Exception("Configuration file is empty or invalid: " . getenv("APP_HOME") . '/config/receiver.ini');
  }

  define("PUBLIC_KEY_PATH", $config["public_key_path"]);
  define("PRIVATE_KEY_PATH", $config["private_key_path"]);
  define("STAGING_ROOT", $config["staging_root"]);
  define("RELEASE_ROOT", $config["release_root"]);
  define("LOCK_PATH", $config["lock_path"]);
  define("MAX_ARCHIVE_SIZE", (int)$config["max_archive_size"] * 1024 * 1024);
  define("MAX_FILE_SIZE", (int)$config["max_file_size"] * 1024 * 1024);
  define("MAX_EXPANDED_SIZE", (int)$config["max_expanded_size"] * 1024 * 1024);
  define("MAX_FILES", (int)$config["max_files"]);
  define("MAX_PATH_LENGTH", (int)$config["max_path_length"]);
  define("MAX_PATH_DEPTH", (int)$config["max_path_depth"]);
  define("CONNECT_TIMEOUT_SECONDS", (int)$config["connect_timeout_seconds"]);
  define("TOTAL_TIMEOUT_SECONDS", (int)$config["total_timeout_seconds"]);
  define("RESPONSE_LIMIT", (int)$config["response_limit"] * 1024 * 1024);
}
