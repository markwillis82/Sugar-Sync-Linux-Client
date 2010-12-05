<?php

class db extends db_core {
    
    public function check_db() {
        $q = "SELECT folder_id FROM folders";
        return $this->query($q);
    }
    
    public function add_folder($name,$s_id,$parent="/") {
        $name = $this->escape($name);
        $s_id = $this->escape($s_id);

        $parent_id = 0;
        if($parent != "/" && $parent != '') {
            $path_parts = explode("/",$parent);
            print_r($path_parts);
            $end_dir = $path_parts[count($path_parts)-2];
            tee("Get Parent: $end_dir");
            $q = "SELECT folder_id FROM folders WHERE name like '$end_dir'";
            $parent_obj = $this->query($q);
            if($parent_obj) {
                $parent_id = $parent_obj->next()->folder_id;
            }
        }
            $s_id = str_replace("/contents","",$s_id);

        $q = "INSERT IGNORE INTO folders SET name='$name', s_id='$s_id', parent=$parent_id";
        if($this->query($q)) {
            $id = mysql_insert_id();
        } else {
            return false;
        }
        return $id;
    }

    function add_file($name,$s_id,$dir_id,$lastmod) {
        $name = $this->escape($name);
        $s_id = $this->escape($s_id);
        $dir_id = $this->escape($dir_id);
        $lastmod = strtotime($lastmod);
        
        $q = "INSERT IGNORE INTO files SET name='$name', s_id='$s_id', folder_id=$dir_id,last_modified = FROM_UNIXTIME($lastmod)";
        if($this->query($q)) {
            $id = mysql_insert_id();
        } else {
            return false;
        }
        return $id;
    }
    
    function get_dir_id($name)  {
        $name = $this->escape($name);
        $q = "SELECT folder_id FROM folders WHERE s_id LIKE '$name'";
        $res = $this->query($q);
        if($res) {
            return $res->next()->folder_id;
        } else {
            return false;
        }
    }
}