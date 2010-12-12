<?php


class curl {
    public $data;
    public $url;
    public $authorised;
    public $type;
    public $db;
    
    function __construct() {
        $this->db = db::getInstance();
    }
    function post() {

        $ch = curl_init();
        $post_curl_options = array(
            CURLOPT_URL => $this->url ,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS=> $this->data
        );
        
        $get_curl_options = array(
            CURLOPT_URL => $this->url ,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 60
        );
        
        if($this->type == "post") {
            $headers = $post_curl_options;
        } else {
            $headers = $get_curl_options;
        }
        
        if($this->authorised) {
            $headers[CURLOPT_HTTPHEADER] = array('Connection: close','Content-type: application/xml','Authorization: '. AUTH_CODE);
        } else {
            $headers[CURLOPT_HTTPHEADER] = array('Connection: close','Content-type: application/xml');
        }

        curl_setopt_array($ch, $headers);
//        print_r($headers);
        
        curl_setopt($ch, CURLOPT_HEADER, true); // Display headers
//        curl_setopt($ch, CURLOPT_VERBOSE, 1); 
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        
//echo $this->data;

        /**
         * Execute the request and also time the transaction
         */
        $start = array_sum(explode(' ', microtime()));
        $result = curl_exec($ch);
        $headers = curl_getinfo($ch);
//        print_r($headers);
//        print_r($result);
        $stop = array_sum(explode(' ', microtime()));
        $totalTime = $stop - $start;
        
        /**
         * Check for errors
         */
        if ( curl_errno($ch) ) {
            $result = 'cURL ERROR -> ' . curl_errno($ch) . ': ' . curl_error($ch);
        } else {
            $returnCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            switch($returnCode){
                case 200:
                case 204:
                    break;
                default:
                    $result = 'HTTP ERROR -> ' . $returnCode;
                    break;
            }
        }
        $this->data = '';
        $this->type = '';
        /**
         * Close the handle
         */
        curl_close($ch);
        return $result;
    }
    
    function download_file($url,$dest,$size) {
       
       // build tmp filename
       $tmp_filename = ".".end(explode("/",$dest)).".tmp";
       $tmp_dest = TMP_DIR.$tmp_filename;
        
        // get original file last modified time
        // we recheck this time after file has finished to check if the original file has changed during download
        // If original file has changed, then we will create a new file  filename.X.ext
        clearstatcache();
        $original_file_mtime = false;
        if(file_exists($dest)) {
            $original_file_mtime = filemtime($dest);
        }
        
        tee("file: $tmp_dest");
        $out = fopen($tmp_dest, 'wb');
        if ($out == FALSE){
           tee("File not opened");
           exit;
        }
        $GLOBALS["DOWNLOAD_SIZE"] = $size;
        $GLOBALS["DOWNLOAD_DONE"] = 0;
        
        $ch = curl_init();
        
        $headers[CURLOPT_HTTPHEADER] = array('Connection: close','Content-type: application/xml','Authorization: '. AUTH_CODE);
        
        curl_setopt_array($ch, $headers);

        curl_setopt($ch, CURLOPT_FILE, $out);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_NOPROGRESS,false);
        curl_setopt($ch,CURLOPT_PROGRESSFUNCTION,'curl_progress');

        curl_exec($ch);
        if(curl_error ( $ch)) {
           tee(curl_error ( $ch));
           return false;
        }
        
         curl_close($ch);

        // recheck original file
        clearstatcache(); # needed to clear cache's
        $original_file_mtime_recheck = false;
        if(file_exists($dest)) {
            $original_file_mtime_recheck = filemtime($dest);
        }
        
        if($original_file_mtime == $original_file_mtime_recheck) { // file has remained unchanged  - ok to update
            $msg = "File Successfully Downloaded: ".end(explode("/",$dest));
            rename($tmp_dest,$dest);
//            unlink($tmp_dest);
            tee($msg);
            
        } else { // file has been updated during download - we need to create a new file
            /**
             * Here is the clever stuff - change destination filename for filename.X.ext then iterate through X to find a number that is not being used. This allows for:
             * file.txt
             * file.1.txt
             * file.2.txt
             * file.3.txt
             */
            $new_file = $dest;
            $iteration = 1;
            while(file_exists($new_file)) {
                tee("$new_file Exists - Iterating");
                $new_ext = end(explode(".",$dest));
                $new_file = str_replace($new_ext,"$iteration.$new_ext",$dest);
                $iteration++;
            }
            $msg = "File Downloaded but original file changed - New File: ".end(explode("/",$new_file));
            rename($tmp_dest,$new_file);
//            unlink($tmp_dest);
            tee($msg);
        }

        
        // send notifications
        if(DBUS_NOTIFY) {
            dbus_message($msg);
        }
        return 1;
    }
    
    function upload_file($filename,$file_url) {
        $fp = fopen ($filename, "r");         
        $size = filesize($filename);

        $GLOBALS["DOWNLOAD_SIZE"] = $size;
        $GLOBALS["DOWNLOAD_DONE"] = 0;
        
         $ch = curl_init();
            
        $headers[CURLOPT_HTTPHEADER] = array('Connection: close','Content-type: application/xml','Authorization: '. AUTH_CODE);
        curl_setopt_array($ch, $headers);

        
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HEADER, true); // Display headers
//        curl_setopt($ch, CURLOPT_VERBOSE, 1); 
        curl_setopt($ch, CURLOPT_URL, $file_url);
        curl_setopt($ch, CURLOPT_PUT, 1); 
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

        curl_setopt($ch,CURLOPT_NOPROGRESS,false);
//        curl_setopt($ch,CURLOPT_PROGRESSFUNCTION,'curl_progress');

        $res = curl_exec($ch);
         if(curl_error ( $ch)) {
            return false;
         }
         curl_close($ch);
    
        // send notifications
        if(DBUS_NOTIFY) {
            dbus_message("File Uploaded: ".$this->db->escape($filename));
        }
        return $res ;
    }
    
    
    function delete_file($file_url) {
         $ch = curl_init();
            
        $headers[CURLOPT_HTTPHEADER] = array('Connection: close','Content-type: application/xml','Authorization: '. AUTH_CODE);
        curl_setopt_array($ch, $headers);

        
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_HEADER, true); // Display headers
        curl_setopt($ch, CURLOPT_VERBOSE, 1); 
        curl_setopt($ch, CURLOPT_URL, $file_url);

         curl_exec($ch);
         if(curl_error ( $ch)) {
            return false;
         }
         curl_close($ch);
    
        // send notifications
        if(DBUS_NOTIFY) {
            dbus_message("File Deleted: ".$this->db->escape($file_url));
        }
        return 1;
    }

    function get_header($key,$data) {
        $parts = explode("\n",$data);
        foreach($parts as $line) {
            if(strpos($line,": ")) {
                list($k,$v) = explode(": ",$line);
                if($k == $key) {
                        $v = $this->clean_url($v);
                    return $v;
                }
            }
        }
        return false;
    }
    
    function strip_headers($response) {
        if(!$response) return false;
        list($headers,$body) = explode("\n<",$response);
        return "<".$body;
    }

    function clean_url ($v) {
                        //this is url debug code checking MD5 hashes of the header
/*                        System_Daemon::log(System_Daemon::LOG_INFO,"");
                        System_Daemon::log(System_Daemon::LOG_INFO,"\t-->original: ".md5($v));
                        System_Daemon::log(System_Daemon::LOG_INFO,"\t-->trim: ".md5(trim($v)));
                        System_Daemon::log(System_Daemon::LOG_INFO,"\t-->preg_replace: ".md5(preg_replace("[^a-zA-Z\:\/]","",$v))."-".preg_replace("[^a-zA-Z\:\/]","",$v));
                        System_Daemon::log(System_Daemon::LOG_INFO,"\t-->str_replace_r_n: ".md5(str_replace(array("\r","\n"),"",$v)));
                        System_Daemon::log(System_Daemon::LOG_INFO,"\t-->All: ".md5(trim(preg_replace("[^a-zA-Z\:\/]","",str_replace(array("\r","\n"),"",$v))))."-".preg_replace("[^a-zA-Z\:\/]","",str_replace(array("\r","\n"),"",$v)));
*/                        
                        $v = trim(preg_replace("[^a-zA-Z\:\/]","",str_replace(array("\r","\n"),"",$v)));
                        return $v;
    }

}
function curl_progress($download_size, $downloaded, $upload_size, $uploaded) {
    if($GLOBALS["DOWNLOAD_SIZE"] == 0) return false;
    if($downloaded== 0) return false;
    $down_percent = round(($downloaded / $GLOBALS["DOWNLOAD_SIZE"]) * 100);
    
//    tee("Doing: $down_percent% - ". ($down_percent - $GLOBALS["DOWNLOAD_DONE"]));
    
    if(($down_percent - $GLOBALS["DOWNLOAD_DONE"]) >=10) {
        tee("Done: $down_percent%");
        $GLOBALS["DOWNLOAD_DONE"] = ((int) ($down_percent/10)) * 10;
    }

//    echo $str;
    return ;//$str;
}
?>
