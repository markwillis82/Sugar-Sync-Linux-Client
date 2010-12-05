<?php
date_default_timezone_set('Europe/London');
/**
* @name Db
* @author Mark Willis and Dan Carter
* @copyright IMMAT Limited (C) 2009
* @version 1.1
*/
class db_core
{
    private static $objInstance;
    
    private $dbconn;
    private $queries=array();
    private $host,$user,$pass,$db_name;
    private $strLastError;

    public $echo_errors=false;  //true or false
    public $echo_queries=false;  //true or false
    public $email_errors=false;  //email address, or false;
    public $log_queries=true;  //true or false
    public $auto_reconnect=true;  //true or false
    
    const VERSION = '1.1';

    public static function getInstance(){
        if (!(self::$objInstance instanceof db)){
            self::$objInstance = new db();
        }
        
        return self::$objInstance;
    }

    private function __construct($host = NULL,$user = NULL,$pass = NULL,$db_name = NULL){

        
        if (func_num_args() > 3){
            $this->host=$host;
            $this->user=$user;
            $this->pass=$pass;
        }
        else{
            $this->host=DB_HOST;
            $this->user=DB_USER;
            $this->pass=DB_PASS;

            $this->db_name=DB_NAME;
        }
        
        $this->connect();
    }
    
    public function get_dyk() {
        $q = "SELECT * FROM newsletter_dyk";
        $obj = $this->query($q);
        if(!$obj) {
            return false;
        } else {
            return $obj->next()->dy_know;
        }
    }
    
    public function obj_array($obj) {
        if(!$obj) {
            return false;
        } else {
            while($o = $obj->next()) {
                $ar[] = $o;
            }
            return $ar;
        }        
    }
    
    public function one_row($obj) {
        if(!$obj) {
            return false;
        } else {
            return $obj->next();
        }        
    }
    
    public function __destruct()
    {
        @mysql_close();
    }
    
    public function formatQuery($strQueryTemplate)
    {
        $arrArguments = func_get_args();
        array_shift($arrArguments); // Remove the initial template parameter.
        
        return vsprintf($strQueryTemplate, $arrArguments);
    }

    protected function connect(){
        $this->dbconn=mysql_connect($this->host,$this->user,$this->pass);
        mysql_select_db($this->db_name,$this->dbconn);
//        mysql_query('SET NAMES UTF8');
//        mysql_query('SET CHARACTER_SET UTF8');
    }

    public function query($q){
        $mxdResult = FALSE;
        $starttime = 0;
        $endtime = 0;
        $totaltime = 0;
        $error = '';
        
        if(!mysql_ping($this->dbconn) && $this->auto_reconnect){
            $this->connect();
        }
        
        if($this->echo_queries) {
            echo "$q\n";
        }
        
        // Trim the query string to remove surrounding white space, then determine query type.
        $q = trim($q);
        $strQueryType = trim(strtoupper(substr($q, 0, strpos($q, ' '))));
        
        $starttime=microtime(true);
        $res=mysql_query($q, $this->dbconn);
        $endtime=microtime(true);
        $totaltime = $endtime - $starttime;
        
        if($res === FALSE){
            $error=mysql_error();
            $errno=mysql_errno();
            $this->strLastError = $error;
            
            if($this->echo_errors) {
                echo "$error ($errno) [$q]<br/>\n";
            }
            if($this->email_errors){
                $servars=print_r($_SERVER,true);
                mail('','error',"$error ($errno)\n\n$q\n\n$servars",$this->email_errors);
            }
            echo ("MySQL: $error ($errno) [$q]");
        }
        else {
            
            $mxdResult = 0;
            
            switch ($strQueryType) 
            {
                case 'SELECT':
                case '(SELECT':
                case 'SHOW':
                    if (mysql_num_rows($res)) {
                        $mxdResult = new db_result($res);
                    }
                break;
                
                case 'INSERT':
                case 'UPDATE':
                case 'DELETE':
                    $mxdResult = mysql_affected_rows($this->dbconn);
                break;
                
                default:
                    $mxdResult = $res; // Just return the 'raw' result from call to mysql_query().
                break;
            }
        }    
        
        if($this->log_queries) {
            $backtrace = debug_backtrace();
            
            if(sizeof($backtrace)>1) {
                $funct=$backtrace[1]['function'];
            }
            
            if(!isset($_SERVER['PWD'])) {
                $this->queries[]=array('page'=>$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'],'function'=>$funct,'query'=>$q,'time'=>$totaltime,'error'=>$error);
            }
        }

        return $mxdResult;
    }
    
    // Turns associtiave array into `$key1`='$value1' , `$key2`='$value2' for use in queries, escapes values for SQL
    public function mysql_vars($vars,$join=',',$parse_nulls=false){
        foreach($vars as $k=>$v){
            $v=db::escape($v);
            if($v!='NULL' || !$parse_nulls) $v="'$v'";
            if($string) $string.=" $join ";
            $string.="`$k`=$v";
        }
        return $string;
    }
    
    public function mysql_vars_exclude($vars,$join=',',$parse_nulls=false, array $arrSkipVars = array()){
        foreach ($arrSkipVars as $strEachVarName){
            if (array_key_exists($strEachVarName, $vars)){
                unset($vars[$strEachVarName]);
            }
        }
        
        return $this->mysql_vars($vars,$join,$parse_nulls);
    }
    
    // Similar to above, but uses the keys from $vars and the values from $from[$key]
    // Useful for picking values from $_POST or such.
    public function mysql_vars_from($vars,$from,$join=',',$parse_nulls=false){
        $string = '';
        foreach($vars as $k){
            $v=db::escape($from[$k]);
            $k=db::escape($k);
            if($v!='NULL' || !$parse_nulls) $v="'$v'";
            if($string) $string.=" $join ";
            $string.="`$k`=$v";
        }
        return $string;
    }
    public function get_last_error() {
        return $this->strLastError;
    }
    
    public function get_last_insert_id() {
        return mysql_insert_id($this->dbconn);
    }
    
    public function get_query_log(){
        return $this->queries;
    }
    
    public function escape($string){
        return mysql_real_escape_string($string, $this->dbconn);
    }
    public static function mysql_time_2_php($str) {
        $time_chunk = explode(":",$str);
        $ts = mktime($time_chunk[1],$time_chunk[2],0,0,0,0);
        $time = date("H:i",$ts);
        return $time;
    }
    public function db_phone_number($str) {
        return $this->escape(preg_replace("/[^0-9]/","",$str));
    }
}

class db_result{
    private $res;
    private $intCurrRow = -1;
    
    public function __construct($res){
        $this->res=$res;
    }
    
    public function __destruct(){
        @mysql_free_result($this->res);
    }

    public function length(){
        return mysql_num_rows($this->res);
    }
    
    public function next(){
        return mysql_fetch_object($this->res);
    }
    
    public function next_assoc(){
        return mysql_fetch_assoc($this->res);
    }
    
    public function next_result($mxdField = NULL){
        $this->intCurrRow++;

        return mysql_result($this->res, $this->intCurrRow, $mxdField);
    }
}
?>
