<?php

class inotify {
    private static $objInstance;
    private $notify;
    private $mask_types = array(
        64 => "delete", //  (possibly move_file_out)
        512 => "delete", // 
        256 => "create", // used with 8 -> creates "paste" action"
        8 => "close write",
        128 => "move_in"
        );
    public $active_watches = null;
    private $recursive_data = array();
    private $watch_res = array();
    private $current_events = array();
    private $path;
    
    public static function getInstance(){
        if (!(self::$objInstance instanceof inotify)){
            self::$objInstance = new inotify();
        }

        return self::$objInstance;
    }

    private function __construct(){
        $this->notify = inotify_init();
    }
    
    public function recurse_watch_dir($path) {
        $this->path = $path;
        $this->recursive_data = $this->profile($this->path);
        $this->watch_dir($this->path); # watch main dir
        #print_r($this->recursive_data["folders"]);
        foreach($this->recursive_data["folders"] as $folder) {
            $this->watch_dir($folder);
        }
        return 1;
    }
    
    public function watch_dir($path) {
        $wd = inotify_add_watch($this->notify, $path, $this->active_watches);
        $this->watch_res[$wd] = $path;
        tee("Added watch: $wd -> $path");
    }
    
    public function check_queue() {
        $this->current_events = array();
        return inotify_queue_len($this->notify);
    }
    
    public function read_events() {
        $this->current_events = inotify_read($this->notify);
        return $this->current_events;
    }
    
    public function remove_watch() {}
    
    public function get_mask_type($mask) {
        if(isset($this->mask_types[$mask])) {
            return $this->mask_types[$mask];
        } else {
            tee("Mask not calculated: Help? $mask (".dechex($mask).")");
            die;
        }
    }
    
    public function get_event_dir($descriptor) {
        return $this->watch_res[$descriptor];
    }
    
    private function profile( $dir ) {
        static $info = array();
            if( is_dir( $dir = rtrim( $dir, "/\\" ) ) ) {
                foreach( scandir( $dir) as $item ) {
                    if( $item != "." && $item != ".." ) {
                        $info['all'][] = $absPath = $dir . DIRECTORY_SEPARATOR . $item;
                        $stat = stat( $absPath );
                        switch( $stat['mode'] & 0170000 ) {
                            case 0010000: $info['files'][] = $absPath; break;
                            case 0040000: $info['folders'][] = $absPath; $this->profile( $absPath ); break;
                            case 0120000: $info['links'][] = $absPath; break;
                            case 0140000: $info['sockets'][] = $absPath; break;
                            case 0010000: $info['pipes'][] = $absPath; break;
                        }
                    }
                }
            }
            clearstatcache();
        return $info;
    }

}

?>