<?php
$config = parse_ini_file(getenv("APP_HOME") . '/config/receiver.ini', true);
            
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

require_once(getenv("APP_HOME") . '/lib/transmission/generate.php');
require_once(getenv("APP_HOME") . '/lib/transmission/receiver.php');
require_once(getenv("APP_HOME") . '/lib/transmission/sender.php');
