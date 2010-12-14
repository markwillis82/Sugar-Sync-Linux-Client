<?php

// Parse ini file for config settings
$config_parts = parse_ini_file("setup.conf");


// maybe a useful loop would be more efficent(not high priority)
if($config_parts["db_host"]){
    define("DB_HOST", $config_parts["db_host"]);
} else {
    System_Daemon::info("Cannot find db_host - exiting");
    end_program();
}

if($config_parts["db_user"]){
    define("DB_USER", $config_parts["db_user"]);
} else {
    System_Daemon::info("Cannot find db_user - exiting");
    end_program();
}

if($config_parts["db_pass"]){
    define("DB_PASS", $config_parts["db_pass"]);
} else {
    System_Daemon::info("Cannot find db_pass - exiting");
    end_program();
}

if($config_parts["db_name"]){
    define("DB_NAME", $config_parts["db_name"]);
} else {
    System_Daemon::info("Cannot find db_name - exiting");
    end_program();
}



if($config_parts["username"]){
    define("USERNAME", $config_parts["username"]);
} else {
    System_Daemon::info("Cannot find username - exiting");
    end_program();
}

if($config_parts["password"]){
    define("PASSWORD", $config_parts["password"]);
} else {
    System_Daemon::info("Cannot find password - exiting");
    end_program();
}
if($config_parts["path_to_sync"]){
    define("SYNC_DIR", $config_parts["path_to_sync"]);
} else {
    System_Daemon::info("Cannot find path_to_sync - exiting");
    end_program();
}
if($config_parts["path_to_tmp"]){
    define("TMP_DIR", $config_parts["path_to_tmp"]);
} else {
    System_Daemon::info("Cannot find path_to_tmp - exiting");
    end_program();
}
if($config_parts["notify_dbus"]){
    define("DBUS_NOTIFY", $config_parts["notify_dbus"]);
} else {
    System_Daemon::info("Cannot find notify_dbus - exiting");
    end_program();
}

/** define global constants **/
define("ABS_DIR",dirname(__FILE__)."/../");
define("AUTH", "https://api.sugarsync.com/authorization");
define("USER", "https://api.sugarsync.com/user");
define("WORKSPACE", "https://api.sugarsync.com/workspace");
define("FILES", "https://api.sugarsync.com/files/");
define("API_USER", "NDA4Mzg1MTI3Mzk2NjIwMTYwNA");
define("API_PASS", "ZjZlZmUyZmUyNjUwNGQ1NmJjOGMyOTA1YzljYjYyYTI");

/** include all the files required **/
include(ABS_DIR."lib/functions.php");
include(ABS_DIR."lib/curl.class.php");
include(ABS_DIR."lib/inotify.class.php"); /** This needs to be compiled **/
include(ABS_DIR."lib/xml.class.php");
include(ABS_DIR."lib/db_core.class.php");
include(ABS_DIR."lib/db.class.php");


System_Daemon::info("Setup");

/** Checking for iNotify - will exit without it **/
if (!function_exists('inotify_init')) {
    System_Daemon::err("inotify functions are not available - Please install inotify through PEAR/PECL");
    die;
}

//find . -printf "%CY-%Cm-%Cd %CH:%CM:%CS\t%p\n" | sort -nr | head -n 1  // old obsolete code

$curl = new curl();
$xml = new xml();
$db = db::getInstance();
$inotify = inotify::getInstance();

// Test database connection - improve error messages
if(!$db->connect()) {
    tee("Cannot connect to database - check privileges/database name");
    die;
}


// We don't need to download these folders as they are archives of older files or been deleted
$xml->ignore_folders = array(
    "Deleted Files",
    "Web Archive",
    );


System_Daemon::info("Get Authorisation");

$auth_code = $xml->get_auth();

if(!$auth_code) {
    System_Daemon::info("No Auth Code - Ending");
    exit;
}

define("AUTH_CODE", $auth_code);



System_Daemon::info("Get user details");

$user_data_xml = simplexml_load_string($xml->get_user());

define("DELETE_URL", $user_data_xml->deleted);

//print_r($user_data_xml);

System_Daemon::info("User: ".$user_data_xml->nickname);
System_Daemon::info("Usage: ".calc_usage($user_data_xml->quota->usage)."/" . calc_usage($user_data_xml->quota->limit)."Mb");

function end_program() {
    System_Daemon::info("Clean up...");
    System_Daemon::info("Exiting");
    System_Daemon::stop();
}
?>
