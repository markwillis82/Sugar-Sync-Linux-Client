<?php
    $log_file_handler=false;
    
    /** put content into log (old dependency - soon to be obsolete?) **/
    function tee($r) {
        System_Daemon::info($r);
    }
    
    function calc_usage($bytes) {
        return round($bytes / 1024 / 1024);
    }
    
    /** Used to show small notification on ubuntu 10.04 **/ 
    function dbus_message($str) {
        exec("echo '$str''' | xargs -0 notify-send -t 500");
    }
    
    //hiphop doesn't support the mime_content_type function
    /** Ideally wanted to use hiphop to convert to C - but not able to due to iNotify requirements **/
    function _mime_content_type($filename) {
        $finfo = finfo_open();
        $fileinfo = finfo_file($finfo, $filename, FILEINFO_MIME);
        finfo_close($finfo);
        return reset(explode(";",$fileinfo));
        
        
        //hiphop workaround hiphop does not work with inotify
        //exec("file ".str_replace(" ","\ ",$filename)." --mime",$output);
        
        $half = explode(": ",$output[0]);
        $done = explode("; ",$half[1]);
        return $done[0];
    }
