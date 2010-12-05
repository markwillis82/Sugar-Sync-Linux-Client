#!/usr/bin/php -q
<?php
// We like errors at the moment
error_reporting(E_ALL);

// Allowed arguments & their defaults 
$runmode = array(
    'no-daemon' => false,
    'help' => false,
    'write-initd' => false,
);

// Scan command line attributes for allowed arguments
foreach ($argv as $k=>$arg) {
    if (substr($arg, 0, 2) == '--' && isset($runmode[substr($arg, 2)])) {
        $runmode[substr($arg, 2)] = true;
    }
}

// Help mode. Shows allowed argumentents and quit directly
if ($runmode['help'] == true) {
    echo 'Usage: '.$argv[0].' [runmode]' . "\n";
    echo 'Available runmodes:' . "\n";
    foreach ($runmode as $runmod=>$val) {
        echo ' --'.$runmod . "\n";
    }
    die();
}


// require PEAR Daemon files - provided in source
require_once("lib/System/Daemon.php");


// Setup
$options = array(
    'appName' => 'sugarsyncclient',
    'appDir' => dirname(__FILE__),
    'appDescription' => 'SugarSync Linux Client',
    'authorName' => 'Mark Willis',
    'authorEmail' => 'mark.w@immat.co.uk',
    'sysMaxExecutionTime' => '0',
    'sysMaxInputTime' => '0',
    'sysMemoryLimit' => '1024M',
    'usePEAR' => false,
    'logVerbosity' => 7, # not working?
    'logLocation' => dirname(__FILE__)."/debug.log"
);

System_Daemon::setOptions($options); // setup all options

// Overrule the signal handler with any function
/** This doesn't work with CTRL+C on terminal in ubuntu 10.04 - not sure why though **/
System_Daemon::setSigHandler(SIGTERM, 'sig_handler');
System_Daemon::setSigHandler(SIGINT, 'sig_handler');

/********************************* Writing init scripts is off at the moment - didn't work outside of PEAR (didn't test thoroughly as not at that point yet)
if (!$runmode['write-initd']) {
    System_Daemon::info('not writing an init.d script this time');
} else {
    System_Daemon::info('init.d script creation OFF at this time');
    
    /*
    if (($initd_location = System_Daemon::writeAutoRun()) === false) {
        System_Daemon::notice('unable to write init.d script');
    } else {
        System_Daemon::info(
            'sucessfully written startup script: %s',
            $initd_location
        );
    } *//*
}
*/



// Spawn Deamon!
if (!$runmode['no-daemon']) {
    // Spawn Daemon 
    System_Daemon::start();
    System_Daemon::log(System_Daemon::LOG_INFO, "Daemon: '".System_Daemon::getOption("appName"). "' spawned! This will be written to ". System_Daemon::getOption("logLocation"));
}



require_once("lib/setup.php");

// Run your code
// Check for first run instance - any records in database - execute recursive download
$first_run = false;
if(!$db->check_db()) {
    $xml->recurse_download($user_data_xml);
    $first_run = true;
}


System_Daemon::info("Run initial upload");
// we run the upload first - then download the new data - if any
$startup_upload = $xml->check_if_upload_needed();
//print_r($startup_upload);
if(is_array($startup_upload)) {
    foreach($startup_upload as $file) {
        $upload_parts = explode("/",$file);
        $upload_filename = array_pop($upload_parts);
        $upload_folder = implode("/",$upload_parts);
        $xml->check_upload_new_file($upload_filename,$upload_folder);
    }
}
//die;
/** If not first run then download after doing upload  **/
if(!$first_run) {
    $xml->recurse_download($user_data_xml);
}


/** We watch for these event triggers to detect wether a file has been added/renamed/deleted/updated **/
$inotify->active_watches = IN_CLOSE_WRITE | IN_MOVED_TO | IN_MOVED_FROM | IN_CREATE | IN_DELETE;
$inotify->recurse_watch_dir(SYNC_DIR);



// This variable gives your own code the ability to breakdown the daemon:
$runningOkay = true;

// This variable keeps track of how many 'runs' or 'loops' your daemon has
// done so far. For example purposes, we're quitting on 3.
$cnt = 1;


/*********************** Main system loop *************/
while (!System_Daemon::isDying() && $runningOkay && $cnt <=50) { /** Limit to 50 recursions for the moment **/
    
    /** sugarsync client bits
     * This is where we need to setup
     * -> inotify watches
     * -> check for new downloads
     */
    
	if($inotify->check_queue()) {
		System_Daemon::info("iNotify Event Found");
		// Read events
                $events = $inotify->read_events();
                foreach($events as $event) {
                    
                    System_Daemon::info("Mask: ".$inotify->get_mask_type($event["mask"]));
                    System_Daemon::info("Event Dir: ".$inotify->get_event_dir($event["wd"]));
                    print_r ($event);
                    
                    switch($event["mask"]) {
                        case 8: # write finished - upload fine
                        case 128: # File moved in (drag/drop) - upload fine
                            System_Daemon::info("Write File: ".$inotify->get_mask_type($event["mask"]));
                            $xml->check_upload_new_file($event["name"],$inotify->get_event_dir($event["wd"]));
                            
                        break;

                        /** Rename File **/
                        
                        /** Delete File **/
                        case 64:
                        case 512:
                            System_Daemon::info("Delete File - TODO: ".$inotify->get_mask_type($event["mask"]));
                        default:
                            System_Daemon::info("No Action Taken");
                        break;
                    }
                }
	} else {
		System_Daemon::info("Sleep ($cnt)");
	}
    
    
    $runningOkay = true;
    
    if (!$runningOkay) {
        System_Daemon::err('Something produced an error, so this will be my last run');
    }
    
    // Relax the system by sleeping for a little bit
    // iterate also clears statcache
    System_Daemon::iterate(2);

    $cnt++;
}


end_program();

function sig_handler($signal) {
    System_Daemon::info('Sig:'.$signal);
    if ($signal === SIGTERM || $signal === SIGINT) {
        System_Daemon::warning('I received the termination signal. ' . $sig);
        // Execute some final code
        // and be sure to:
        end_program();
    }
}
