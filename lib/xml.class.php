<?php

class xml {
    private $curl;
    private $_xml_writer;
    private $db;
    public $ignore_folders = array();
    
    function __construct() {
        $this->_xml_writer = new XMLWriter();
        $this->curl = new curl();
        $this->db = db::getInstance();
    }
    
    function send_get($url,$strip_headers=true) {
        
        $this->curl->url = $url;
        $this->curl->authorised = true;
        
        $result = $this->curl->post();
        //echo $location;
        if($strip_headers) {
            return $this->curl->strip_headers($result);
        } else {
            return $result;
        }
    }
    
    function get_auth() {
        $this->_xml_writer->openMemory();
        $this->_xml_writer->startDocument('1.0','UTF-8');
        $this->_xml_writer->startElement("authRequest");

            $this->_xml_writer->startElement("username");
                $this->_xml_writer->text(USERNAME);
            $this->_xml_writer->endElement();

            $this->_xml_writer->startElement("password");
                $this->_xml_writer->text(PASSWORD);
            $this->_xml_writer->endElement();

            $this->_xml_writer->startElement("accessKeyId");
                $this->_xml_writer->text(API_USER);
            $this->_xml_writer->endElement();

            $this->_xml_writer->startElement("privateAccessKey");
                $this->_xml_writer->text(API_PASS);
            $this->_xml_writer->endElement();

        $this->_xml_writer->endElement();
        $fnc_request = $this->_xml_writer->outputMemory(true);
        
        $this->curl->data = $fnc_request;
        $this->curl->url = AUTH;
        $this->curl->type = "post";
        
        $location = str_replace(array("\r","\n"),"",$this->curl->get_header("Location",$this->curl->post()));
        return $location;
    }
    
    function create_new_folder($folder_name,$folder_path) {
        /**
         * Using $folder_path (array) we loop through each folder and check to see if it exists in the database
         * if it does not exist then we add it to the recursion array, as we may need to create more then one folder to successfully upload the file
         * (test files/folders script proves this)
         * Once we have finished creating folders we then return the final url of the folder to put file in
         */
        
        //remove the chance of creating "syncdir"
        $sync_parts = explode("/",SYNC_DIR);
        //print_r($sync_parts);
        //System_Daemon::info($folder_path[0]." == ".$sync_parts[count($sync_parts)-2]);
        if($folder_path[0] == $sync_parts[count($sync_parts)-2]){
            array_shift($folder_path);
        }
        //print_r($folder_path);
        //die;
        
        // flip array - start at "top level"
        $folder_path = array_reverse($folder_path);

        foreach($folder_path as $test_folder) {
            System_Daemon::info("Testing creation of: $test_folder");
            
            #this has problems potentially where folders have the same name
            $q = "SELECT * FROM folders WHERE name LIKE '$test_folder' ";
            $folder_info = $this->db->query($q);
            if(!$folder_info) {
                System_Daemon::info("\t$test_folder Does Not Exist - Queue for creation");
                $folders_to_create[] = $test_folder;
            } else {
                $current_folder = $folder_info->next();
                print_r($current_folder);
                System_Daemon::info("\t$test_folder Exists - Stop recursion and start folder creation");
                break;
            }
        }
        
        $folders_to_create = array_reverse($folders_to_create);
        $path = '';
        foreach($folders_to_create as $create) {
            $path .= $create."/";
            System_Daemon::info("Creating: $path");
            $this->_xml_writer->openMemory();
            $this->_xml_writer->startDocument('1.0','UTF-8');
            $this->_xml_writer->startElement("folder");
    
                $this->_xml_writer->startElement("displayName");
                    $this->_xml_writer->text($create);
                $this->_xml_writer->endElement();
    
            $this->_xml_writer->endElement();
            $fnc_request = $this->_xml_writer->outputMemory(true);
    
            $this->curl->data = $fnc_request;
    
            $url = str_replace("/contents","",$current_folder->s_id);
            $this->curl->url = $url;
    
            $this->curl->type = "post";
            $this->curl->authorised = true;
    
            $post_data = $this->curl->post();
            //print_r($post_data);
            //die;
            $location = $this->curl->get_header("Location",$post_data);
            
            
            // add folder into db - This lets us find it internally
            $q = "INSERT IGNORE INTO folders SET name='$create', s_id='$location', parent=$current_folder->folder_id";
            
            $this->db->query($q);
            
            //$current_folder = '';
            $current_folder_ar["folder_id"] = mysql_insert_id();
            $current_folder_ar["s_id"] = $location;
            $current_folder = (object) $current_folder_ar;
        }
        //die;
        return $location;

        //echo "$folder_name,$folder_path";
    }
    function create_new_file($filename) {
        $filename_parts = explode("/",$filename);
        $file = $this->db->escape(array_pop($filename_parts));
        $folder = $this->db->escape(array_pop($filename_parts));
        
        $this->_xml_writer->openMemory();
        $this->_xml_writer->startDocument('1.0','UTF-8');
        $this->_xml_writer->startElement("file");

            $this->_xml_writer->startElement("displayName");
                $this->_xml_writer->text($file);
            $this->_xml_writer->endElement();

            $this->_xml_writer->startElement("mediaType");
                $this->_xml_writer->text(_mime_content_type($filename));
            $this->_xml_writer->endElement();

        $this->_xml_writer->endElement();
        $fnc_request = $this->_xml_writer->outputMemory(true);
        $this->curl->data = $fnc_request;
        
        # needs a check to ensure folder exists also.
        /** we need to check the "location" of this folder - as we can cause a deadlock by doing:
         * /tmp1/newfolder/a
         * /tmp2/newfolder/a
         * both would match - meaning looping code
         *
         */
        
        $q = "SELECT s_id FROM folders WHERE name LIKE '$folder' ";
        $folder_info = $this->db->query($q);
        if(!$folder_info) {
            $folder_path = explode("/",$filename);
            array_pop($folder_path);
            
            $url = $this->create_new_folder($folder,$folder_path);
            
            System_Daemon::info("Folder Created");
//            die;
        } else {
            $url = $folder_info->next()->s_id;
        }
        
        #debug for some urls have contents on the end - just remove that
        $url = str_replace("/contents","",$url);
        $this->curl->url = $url;

        $this->curl->type = "post";
        $this->curl->authorised = true;

        $location = str_replace(array("\n","\r"),"",$this->curl->get_header("Location",$this->curl->post()));
        
        $q = "SELECT folder_id FROM folders WHERE s_id = '$url'";
        $folder_info = $this->db->query($q);
        if($folder_info) {
            $folder_id = $folder_info->next()->folder_id;
        }
        
        return array("file_loc" => $location,"folder_id" => $folder_id);
    }
    
    function upload_new_file($filename,$file_url) {
        $res = $this->curl->upload_file($filename,$file_url);
        print_r($res);
    }

    function get_user() {
        return $this->send_get(USER);
    }

    function get_workspace() {
        return $this->send_get(WORKSPACE);
    }

    function get_folder($url) {
        return $this->send_get($url);
    }

    function get_contents($url) {
        return $this->send_get($url);
    }

    function get_file_info($url) {
        return $this->send_get($url);
    }

    function recurse_dir($folder,$rec="") {
        $files = false;
        $folders = false;
        $dir_folder = SYNC_DIR.$rec.$folder->displayName;
        $dir_id = $this->db->get_dir_id($folder->ref);
        if(!$dir_id) {
            System_Daemon::info("Create Local: $dir_folder");
            mkdir($dir_folder);
            // presume folder is not in db - add to db
            $dir_id = $this->db->add_folder($folder->displayName,$folder->ref,$rec);
        }
        
        $dir_contents = simplexml_load_string($this->get_contents($folder->contents));
        
        if(isset($dir_contents->file)) {
            foreach($dir_contents->file as $f) $files[] = $f;
        } elseif(isset($dir_contents->collection)) {
            foreach($dir_contents->collection as $f) {
                if($f["type"] == "folder") {
                    $folders[] = $f;
                } else {
                    $files[] = $f;
                }
            }
        }
        
        // download any files
        $current_dir = $rec.$folder->displayName."/";
        System_Daemon::info("Current dir:$current_dir");
        
        if(is_array($files)) { # download any files in current folder - before recursing a level
            $this->download_files($files,$current_dir,$dir_id);
        }
        
        // any folders to recurse into?
        if(is_array($folders)) {
            foreach($folders as $rec_folder) {
                $this->recurse_dir($rec_folder,$current_dir);
            }
        }
        
        return 1;
    }
    
    function download_files($files,$path='',$dir_id=1) {
        clearstatcache(); # clear any caching
        
        foreach($files as $file) {
            System_Daemon::info("Check File: $path".$file->displayName);
//            print_r($file);die;
//            $file_info = simplexml_load_string($this->get_file_info($file->ref));
//            print_r($file_info);

            $download = true;
            $filename = SYNC_DIR.$path.$file->displayName;
            
            $this->db->add_file($file->displayName,$file->ref,$dir_id,$file->lastModified);
            
            if(file_exists($filename)) {
                $last_m_time = filemtime($filename);
                System_Daemon::info($file->lastModified . " - " . strtotime($file->lastModified). " - " .$last_m_time);
                if(strtotime($file->lastModified) > $last_m_time) {
                    System_Daemon::info("\tServer File Newer - Download");
                } elseif(strtotime($file->lastModified) < $last_m_time) {
                    System_Daemon::info("\tSource File Newer - Potential Upload?");
                    # we could record this for later use - reducing iterations
                    $download = false;
                    
                } else {
                    System_Daemon::info("\tFile up-to-date - Skip");
                    $download = false;
                }
            }
            
            //$download = true; # debug for checking tmp file creation
            if($download) {
                System_Daemon::info("\tDownload file:");
                $res = $this->curl->download_file($file->fileData,$filename,$file->size);
               // print_r($res);
               // die;
                if($res) {
                    System_Daemon::info("\tFile Downloaded");
                } else {
                    System_Daemon::info("\t\tError Downloading File: $res");
                }
            }
        //        die;
            
            //$file_info = simplexml_load_string($xml->get_file_info($file->ref));
            //print_r($file_info);
            
        }        
    return 1;
    }
    
    function check_absolute_file_exists($filename) {
        $filename_parts = explode("/",$filename);
        array_shift($filename_parts);
        $parent_id = 0;
        foreach($filename_parts as $folder) {
            $q = "SELECT folder_id FROM folders WHERE name LIKE '$folder' AND parent = $parent_id";
            $res = $this->db->query($q);
            if($res) {
                $parent_id = $res->next()->folder_id;
            } else {
                System_Daemon::info("Folder does not exist - create new file");
                return false;
            }
        }
        // whole folder structure exists - return the folder_id
        System_Daemon::info("Folder structure exists - update");
        return $parent_id;
    }
    
    function recurse_download($user_data_xml) {

        $folder_to_check = array();
        foreach($user_data_xml as $key => $value) {
            if(in_array($key,array("syncfolders", "receivedShares"))){
                $folder_to_check[$key] = (string)$value;
            }
        }
        

        System_Daemon::info("Get 'syncfolders'");
        $collection_details = simplexml_load_string($this->get_folder($folder_to_check["syncfolders"]));
        foreach($collection_details as $head_folder) {
            if(in_array($head_folder->displayName,$this->ignore_folders)) continue;
            
            //break; /*******************************************/
        
            System_Daemon::info("Get $head_folder->displayName");
            $folder_details = simplexml_load_string($this->get_folder($head_folder->contents));
            //print_r($folder_details);
        
            // add magic briefcase to db folders
            if(!file_exists(SYNC_DIR.$head_folder->displayName)) {
                System_Daemon::info("Making sync folder for $head_folder->displayName");
                mkdir(SYNC_DIR.$head_folder->displayName);
            }
            
            $this->db->add_folder($head_folder->displayName,$head_folder->contents);
            
            
            System_Daemon::info("Get Contents");
            /**
             *  this fails using sync folders - using "collection" rather then per folder contents -
             *  so we need to loop through each collection and create / download folders
             *
             */
            $folder_contents = simplexml_load_string($this->get_contents($head_folder->contents));
            
            System_Daemon::info("Recurse Folders Inside $head_folder->displayName");
            //print_r($briefcase_contents);
            foreach($folder_contents->collection as $folder) {
                $this->recurse_dir($folder,"$head_folder->displayName/");
            //    die;
            }
            
            System_Daemon::info("Get Files In $head_folder->displayName");
            $this->download_files($folder_contents->file,"$head_folder->displayName/",1);
        }

        System_Daemon::info("Get 'receivedShares'");
        $collection_details = simplexml_load_string($this->get_folder($folder_to_check["receivedShares"]));
        
    
        foreach($collection_details->receivedShare as $share) {
            System_Daemon::info("Get Owner Info");
            $share_user = simplexml_load_string($this->send_get($share->owner));
            
            
            if($share->permissions->readAllowed) {
                System_Daemon::info("Allowed to read folder - add to db + download");
            } else {
                System_Daemon::info("Not Allowed to read folder - ignore");
                continue;
            }
            // check if "Shares" folder exists
            if(!file_exists(SYNC_DIR."Shares")) {
                System_Daemon::info("Making sync folder for 'Shares'");
                mkdir(SYNC_DIR."Shares");
            }
            
            // create folder for share name
            // get userid
            $user_parts = explode("/",$share->owner);
            $user_id = $user_parts[count($user_parts)-2];
            $share_username = $share_user->firstName."_".$share_user->lastName."_".$user_id;
        
            System_Daemon::info("Get $share_username");
            $folder_details = simplexml_load_string($this->get_folder($share->sharedFolder));
            //print_r($folder_details);
        
            // add magic briefcase to db folders
            if(!file_exists(SYNC_DIR."Shares/$share_username")) {
                System_Daemon::info("Making sync folder for $share_username");
                mkdir(SYNC_DIR."Shares/$share_username");
            }
            
            $this->db->add_folder($share_username,$share->sharedFolder);
            
            
            System_Daemon::info("Get Contents");
            
            /**
             *  this fails using sync folders - using "collection" rather then per folder contents -
             *  so we need to loop through each collection and create / download folders
             *
             */
            $folder_contents = simplexml_load_string($this->get_contents($share->sharedFolder));
            
            System_Daemon::info("Recurse Folders Inside $share_username");
            $folder_contents->ref = $folder_contents->contents;
                //print_r($folder_contents);
                $this->recurse_dir($folder_contents,"Shares/$share_username/");
            
    //        System_Daemon::info("Get Files In $folder_contents->displayName");
    //        $xml->download_files($folder_contents->files,"Shares/$share_username/",1);
        }
    }
    
    /** Compare all file timestamps and find any that are updated AFTER the timestamp stored in the database **/
    function check_if_upload_needed() {
        $upload = false;
        /** Clever bash code to sort all files in all folders and sort by timestamps **/
        $cmd = 'find  '.SYNC_DIR.' -type f -printf "%CY-%Cm-%Cd %CH:%CM:%CS\t%p\n" | sort -nr';
        exec($cmd,$out);

        $q = "SELECT UNIX_TIMESTAMP(last_update) AS last_update FROM files ORDER BY last_update DESC LIMIT 1";
        $latest_res = $this->db->query($q);
        $latest_date = $latest_res->next()->last_update;

        foreach($out as $line) {
            list($file_date,$file_name) = explode("\t",$line);
            
            
            // remove micro seconds
            list($keep_date,$ignore) = explode(".",$file_date);
            $file_date = strtotime($keep_date);
            
            if($file_date >= $latest_date) {
                //System_Daemon::info("Upload: $file_name ($file_date) - ($latest_date)");
                $upload[] = $file_name;
            }
            
            
        }
        return $upload;
    }
    
    function check_upload_new_file($filename,$full_folder) {
            // get files folder too
            $folder_parts = explode("/",$full_folder);
            $folder = end($folder_parts);
            tee("$filename - $folder");
            $q = "SELECT files . * , folders.name AS folder
                    FROM `files` , folders
                    WHERE folders.folder_id = files.folder_id
                    AND files.name LIKE '$filename'
                    AND folders.name LIKE '$folder' ";
            /*** This may cause an issue if the same file exists in 2 folders of the same name in 2 different parents **/
        
            $res = $this->db->query($q);
            if(!$res) {
                //create new file at sugarsync and a new DB entry for file
                tee("Create File: $filename");
                $file_details = $this->create_new_file($full_folder."/".$filename);
                $file_loc = $file_details["file_loc"];
                $folder_id = $file_details["folder_id"];
                
                // insert record into db
                $q = "INSERT INTO files SET s_id = '$file_loc', name='$filename', folder_id ='$folder_id',last_modified=NOW()";
                $this->db->query($q);
            } else {
                /**
                 * If file exists - we need to recursively loop back through the folder structure on the DB and see if the directories match - all the way to the first sync folder
                 * This is too fix issues with same named file in 2 folders
                 */
                $file_exists = $this->check_absolute_file_exists($full_folder."/".$filename);
                /**
                 * Check filemtime against last_modified in db - this will rule out any unneccesary uploads
                 *
                 */
                
                tee("File Already Exists - Update: $filename");
                $file = $res->next();
                if(filemtime($filename) >= strtotime($file->last_modified)) {
                    tee("File is up to date - don't upload");
                    
                }
        
                $file_loc = $file->s_id;
                $this->db->query("UPDATE files SET last_update = NOW() WHERE s_id LIKE '$file->s_id'");
            }
            
            tee("Upload File: $filename");
            $upload_res = $this->upload_new_file($full_folder."/".$filename,$file_loc."/data/");
            tee($file_loc);
        
    }
}

?>