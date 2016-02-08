<?
class MailBox {
	
    function __construct($server, $user, $pass, $folder='INBOX', $certificate=false){
    	$test = $this->testConnection($server, $user, $pass);
        if($test != 'success'){
        	$this->connectionError = $test;
        	return false;
        }
        $this->connect($server, $user, $pass, $folder, $certificate);
    }
    
    
    function __destruct() {
    	@imap_close($this->con);
    }
	
    
    function testConnection($imap, $username, $pw){ // 'ssl://imap.codev.it'
        if(!$username) return 'user';
        if(!$pw) return 'user';
        list($host,$port,$ssl) = explode(':',$imap);
//        $ssl?'ssl://':''
		$ssl = $ssl ? 'ssl://' : '';
        $timeout = 1;
        $socket = fsockopen($ssl.$host, $port, $errno, $errstr, $timeout);
        if(!$socket){
            return 'host';
        } else {
            fgets($socket)."\n"."\n"; 
            fputs($socket,"a1 LOGIN $username $pw\r\n");
            while(1){
               $test = fgets($socket);
               $sub = substr($test,3,2);
               if($sub=='OK'){fclose($socket); return 'success';}
               if($sub=='NO'){fclose($socket); return 'user';}
            }
        }
    }      
    
    function connect($server, $user, $pass, $folder='INBOX', $certificate=false){
        list($host,$port,$ssl) = explode(':',$server);
//        if(substr($server,-4)==':ssl'){
//            $server = substr($server,0,-4);
//            $ssl = '/ssl';
//        } 
		$ssl = $ssl ? '/ssl' : '';
        $cert = $certificate ? '' : '/novalidate-cert';
        $string = "{"."$host:$port/imap$ssl$cert}";
        imap_timeout(IMAP_OPENTIMEOUT, 2); // PHP - BUG!!! FUCK IT
        $this->con = @imap_open($string.$folder, $user, $pass); 
        $this->folder = $folder;
        $this->string = $string;
    }
    
    
    function status(){
        $stat = (array)imap_status($this->con, $this->string, SA_ALL);
        return $stat;
    }

	
    
    
	
    
    
    // delete mails
	function remove($ids){
    	if(!is_array($ids)){ $ids = explode(',',$ids);}
        foreach($ids as $id){
            imap_delete ($this->con, $id, FT_UID);
        }
        imap_expunge($this->con);
    }

	
    
    
    
    
    
    
    // FOLDER ACTIONS
    function listFolders(){
		$list = imap_list($this->con, $this->string, "*"); // "{imap.example.org}"
    	foreach($list as $i=>$val){
        	$list[$i] = str_replace($this->string,"",$val);
        }
        return $list;
    }
    function changeFolder($folder){
    	$this->folder = $folder;
    	$suc = imap_reopen($this->con, $this->string.$this->folder);
    }
    
    
    
    
    
    
    
    
    
    // COUNT MESSAGES
    function count(){
    	return imap_num_msg($this->con);
    }
    
    function countAll(){
    	$ret = array();
        foreach($this->listFolders() as $folder){
        	$this->changeFolder($folder);
            $ret[$folder] = $this->count();
        }
        return $ret;
    }
    
    
    
    
    
    
    
    
    
    // load IDs...
    function ids($date=''){//$filter=''){
		$d = date('"d-M-Y"',strtotime(substr($date,1)));
        if(substr($date,0,1)=='>'){ $date = "SINCE $d"; }
        if(substr($date,0,1)=='<'){ $date = "BEFORE $d"; }
        if(!$address){ return imap_sort($this->con, SORTDATE, 1, SE_UID || SE_NOPREFETCH, $date); }     
    }
    
    function month($d=''){ // week,day,year... deleted... api clean up
        $t0 = microtime(true)*1000;
    	if(!$d){ $d = date('Y-m'); }
        $since = date('d M Y H:i:s', strtotime($d) );
        $before = date('d M Y H:i:s', strtotime($d.' +1 month') );
        $ids = imap_sort($this->con, SORTARRIVAL, 1, SE_UID || SE_NOPREFETCH, 'SINCE "'.$since.'" BEFORE "'.$before.'"');
        return array('folder'=>$this->folder, 'month'=>$d, 'ids'=>$ids, 'time'=>round(microtime(true)*1000-$t0));
    }
    function year($d=''){ // week,day,year... deleted... api clean up
        $t0 = microtime(true)*1000;
    	if(!$d){ $d = date('Y'); }
        $since = date('d M Y H:i:s', strtotime($d) );
        $before = date('d M Y H:i:s', strtotime($d.' +1 year') );
        echo "SINCE: ".$since;
        $ids = imap_sort($this->con, SORTARRIVAL, 1, SE_UID || SE_NOPREFETCH, 'SINCE "'.$since.'" BEFORE "'.$before.'"');
        return array('folder'=>$this->folder, 'year'=>$d, 'ids'=>$ids, 'time'=>round(microtime(true)*1000-$t0));
    }
    
    
    
    
    
    
    
    
    
    
    // load State...
    function getState($uid){
        $t0 = microtime(true)*1000;
        $h = imap_fetch_overview($this->con, $uid);//, FT_UID);
        $h = $h[0];
        $head = imap_header($this->con,$h->msgno);

        $tmp['folderID'] = $h->uid;
        $tmp['serverID'] = trim($h->message_id,'<>');
        $tmp['folderNumber'] = $h->msgno;
        $tmp['size'] = $h->size;
        // DRAFT, DELETED ...
        
        $tmp['seen'] = true;
        if($h->Recent=='N') $tmp['seen'] = false;
        if($h->Unseen=='U') $tmp['seen'] = false;
        
        $tmp['answered'] = false;
        if($h->Answered=='A') $tmp['answered'] = true;
        
  		$tmp['star'] = ($h->Flagged=='F') ? true : false;
        
        $tmp['processingTime'] = round(microtime(true)*1000 - $t0);
        return $tmp;
	}    
    
    
    
    
    
    
    
    
    
    
    
    
    // read the full mail-header and normalize the result
    function getHeader($uid){ // get header by UID
        $t0 = microtime(true)*1000;
        $header = imap_header($this->con,$uid);
        $tmp = array();
        
        $tmp['time'] = date('Y-m-d H:i:s', strtotime($header->date));
        $tmp['subject'] = $this->decodeHeader($header->Subject);
        $tmp['size'] = $header->Size*1;
        
        $tmp['people'] = $this->getPeople($header);
        
        $tmp['ID']['folderName'] = $this->folder;
        $tmp['ID']['folderNumber'] = $header->Msgno*1;
//        $tmp['folderNumber'] = $id; // ??
        $tmp['ID']['folderID'] = imap_uid($this->con,$uid)*1;
//        $tmp['folderID'] = $uid*1;
        $tmp['ID']['serverID'] = trim($header->message_id,'<>');
        $replyID = trim($header->in_reply_to,'<>'); 
        if($replyID)
            $tmp['ID']['inReplyToID'] = $replyID; 
        $refs1 = explode(' ',$header->references); 
        $refs2 = explode(',',$header->references);
        $refIDs = count($refs1)>count($refs2) ? $refs1 : $refs2;
        foreach($refIDs as $k=>$v)
        	$refIDs[$k] = trim($v,'<>');
        if($refIDs[0]) 
        	$tmp['ID']['references'] = $refIDs;
        

        
        $tmp['flags']['seen'] = true;
        if($h->Recent=='N') $tmp['flags']['seen'] = false;
        if($h->Unseen=='U') $tmp['flags']['seen'] = false;
        
        $tmp['flags']['answered'] = false;
        if($h->Answered=='A') $tmp['flags']['answered'] = true;
        
  		$tmp['flags']['star'] = ($h->Flagged=='F') ? true : false;
        
        $tmp['processingTime'] = round(microtime(true)*1000 - $t0);
        return $tmp;
//        
//        $tmp['userAgent'] = trim($h->User-Agent?$h->User-Agent:$h->X-Mailer);  
//        if(!$tmp['userAgent']){ unset($tmp['userAgent']); }

	}
    
 
    function getPeople($header, $fields='from,to,cc,bcc,reply_to'){ // from,to,cc,bcc,reply_to,...
    	$fields = explode(',',$fields);
        $header = (array) $header;
        foreach($fields as $field){
        	$tmp = $this->normalizeMailIDs($header[$field]);
            if($tmp) $ret[$field] = $tmp;
        }
        return $ret;
    }
    // normalize the mailbox/host/name - set
    function normalizeMailIDs($p){
        if(!$p){return array();}
        foreach($p as $q)
        	if($q->host)
                $ret[$q->mailbox.'@'.$q->host] = $this->decodeHeader($q->personal);
            else
            	$ret['?'] = 'unknown';
        return $ret;
    }
    
    function decodeHeader($h){ 
    	$ret = '';
        $tmp = imap_mime_header_decode($h); 
        foreach ($tmp as $part) 
            switch($part->charset){
            	case 'GB2312':
            	case 'ISO-8859-1':
					$ret .= iconv($part->charset, 'utf-8', $part->text);
					break;
                default:
		        	$ret .= iconv_mime_decode($part->text, 2, 'utf-8');
            }
        return $ret;
    }
    
    
    
    
    
    
    
    
    
    function compactParts($part){ // for debugging only... not uses in production
        $list=array();
    	$tmp['type'] = $this->TYPES[$part->type];
        $tmp['subtype'] = $part->subtype;
        foreach($part->parameters as $p) // add parameters
            $tmp[strtolower($p->attribute)] = iconv_mime_decode($p->value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8"); 
            
        if($part->parts)
        	foreach($part->parts as $p)
                $tmp['parts'][] = $this->compactParts($p);
                
        if(isset($part->type))
        	$list[] = $tmp;
		return $list;            
    }
    
    
             
    
    
    
	
    public $TYPES = array('text','multipart','message','application','audio','image','video','other');
    public $ENCODINGS = array('7BIT','8BIT','BINARY','BASE64','QUOTED-PRINTABLE','OTHER');
    

    function getParts($b, $level=0, $i=0){
        $ret = array();
    	$tmp['type'] 	= $this->TYPES[$b->type];
        $tmp['subtype'] = $b->subtype;
        if($tmp['type']=='text') 
        	$tmp['lines'] = $b->lines;
        if($b->type>2) 
            $tmp['disposition'] = $b->disposition;
        if($b->bytes)
            $tmp['bytes'] = $b->bytes;
        $tmp['encoding'] = $this->ENCODINGS[$b->encoding];
        if($b->parameters)
            foreach($b->parameters as $p)
                $tmp[strtolower($p->attribute)] = iconv_mime_decode($p->value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8"); 
        if($b->dparameters)
            foreach($b->dparameters as $p)
                $tmp[strtolower($p->attribute)] = iconv_mime_decode($p->value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8"); 
        $tmp['level'] = $level.'.'.($i+1);
        if($level<2) $tmp['partNumber'] = ($i+1);//'1';
        else $tmp['partNumber'] = ($level-1).'.'.($i+1);
        
        if(in_array($b->type, array(0,3,4,5,6,7), true)) $ret[] = $tmp;
        
        if($b->parts)
            foreach($b->parts as $i=>$part)
                $ret = array_merge($ret, $this->getParts($part, $level+1, $i));
        return $ret;
    }
    
    function getBodyStructure($uid){
        $t0 = microtime(true)*1000;
		$structure = imap_fetchstructure($this->con, $uid, FT_UID);
        $parts = $this->getParts($structure);
        $parts2 = $this->compactParts($structure);
        foreach($parts as $part)
        	if($part['filename'] || $part['name'])
            	$files[] = array('filename'=>$part['name']?$part['name']:$part['filename'], 'size'=>$part['bytes'], 'extension'=>$part['subtype']);
        foreach($parts as $part)
            if($part['subtype']=='HTML')
            	$html += $part['bytes'];
        $out = array('folderName'=>$this->folder, 'folderID'=>$uid, 'parts'=>$parts, 'parts2'=>$parts2, 'html'=>$html,
        	'processingTime'=>round(microtime(true)*1000-$t0) );
        if(count($files)) $out['files'] = $files;
        return $out;
    }
    
     
    
    
    
    
    
    
    
    
    
    
    
    function decodeBody($data, $encoding){ 
		switch($encoding) {
			case 'BASE64': 				return base64_decode($data); 
			case 'QUOTED-PRINTABLE': 	return quoted_printable_decode($data); 
			default: 					return $data;
		}
    }
    
    
    function getPart($uid, $part){
    	if(!$part['partNumber']) return;
        $t0 = microtime(true)*1000;
		$data = imap_fetchbody($this->con, $uid, $part['partNumber'], FT_UID);
		$data = $this->decodeBody($data, $part['encoding']);
        $data = iconv($part['charset'], 'UTF-8', $data); 
        return array('folderName'=>$this->folder, 'folderID'=>$uid, 'data'=>$data, 'processingTime'=>round(microtime(true)*1000-$t0) );
    }
    
    
    
    function getText($uid, $parts, $typ='PLAIN'){
    	$out = array('text'=>'', 'processingTime'=>0);
        foreach($parts as $part)
            if(strtolower($part['subtype'])==strtolower($typ)){
//            	print_r($part);
            	$tmp = $this->getPart($uid, $part);
                $out['text'] .= $tmp['data'];
                $out['processingTime'] += $tmp['processingTime'];
            }
        return $out;
    }
  
    
    
    
    
    
}


?>