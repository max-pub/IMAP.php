<?php


class MailBox {
    public $hosts = array(
    	"gmail.com" => array( "imap.gmail.com:993/imap/ssl", 'user@host' ),
    	"luplu.com" => array( "luplu.com:110/pop/novalidate-cert", 'user@host' ),
    	"ubu.me" => array( "ubu.me:993/imap/ssl/novalidate-cert", 'user' )
    );
    public $contentTypes = array(
    	'pdf' => "application/pdf"
    );
    
    
    
    
//    function __construct($host, $user, $pass){
//        $conStr = '{'.$this->hosts[$host][0].'}INBOX';
//        $userStr = str_replace('host', $host, str_replace('user',$user,$this->hosts[$host][1]));
//        $this->con = (imap_open($conStr,$userStr,$pass)) or die("can't connect: " . imap_last_error());
////        return $con; 
//    }
    function __construct($mail, $pass, $options=array() ){
    	$tmp = explode('@',$mail);
        
        $this->user = $options['user'] ? $options['user'] : $tmp[0];
        $this->in = $options['in'] ? $options['in'] : 'imap.'.$tmp[1];
        $this->out = $options['out'] ? $options['out'] : 'smtp.'.$tmp[1];
        $this->port = $options['port'] ? $options['port'] : 143;
        $this->mode = $options['mode'] ? $options['mode'] : 'imap';
        $this->ssl = $options['ssl'] ? $options['ssl'] : false;
        $this->cert = $options['cert'] ? $options['cert'] : false;
        $this->folder = $options['folder'] ? $options['folder'] : 'INBOX';
        
        $ssl = $this->ssl ? '/ssl' : '';
        $cert = $this->cert ? '' : '/novalidate-cert';
        
        $this->string = '{'.$this->in.':'.$this->port.'/'.$this->mode."$ssl$cert".'}'.$this->folder;
//        echo 'con: '.$conStr;

        $this->con = (imap_open($this->string, $this->user, $pass)) or die("can't connect: " . imap_last_error());
    }
    function __destruct() {
    	imap_close($this->con);
    }
	
    
    
    function status(){
    	$con = $this->con ? true : false;
        $ret = array('connected'=>$con, 'in'=>$this->in, 'out'=>$this->out, 'port'=>$this->port, 'mode'=>$this->mode, 'user'=>$this->user, 'ssl'=>$this->ssl, 'certificate'=>$this->cert, 'folder'=>$this->folder, 'string'=>$this->string);
        $stat = (array)imap_status($this->con, $this->string, SA_ALL);
//        $check = (array)imap_check($this->con);
        return array_merge($ret, $stat);
    }
    
    
    
    
    // fetch mail-ids for given date and/or mail-address... return ids only for performance-reasons
    function ids($filter=''){
//    	echo 'filter: '.$filter;
    	$fis = explode(',',$filter);
        foreach($fis as $fi){
        	$fi = trim($fi);
            if(substr_count($fi,'@')==1){ $address = $fi; }
            if(substr_count($fi,'-')==2){ $date = $fi; }
        }
//        echo "date: $date  ::: add: $address";
        if(substr($date,0,1)=='>'){ $date = 'SINCE '.imapDate($date); }
        if(substr($date,0,1)=='<'){ $date = 'BEFORE '.imapDate($date); }
    
        if(!$address){ return imap_sort($this->con, SORTARRIVAL, 1, SE_NOPREFETCH, $date); } //,"utf-8" 
        //echo "FROM $address $date";
        // SE_NOPREFETCH      SE_UID
        $s1 = imap_sort($this->con, SORTARRIVAL, 1, SE_NOPREFETCH, "FROM $address $date");//,"utf-8"    
        $s2 = imap_sort($this->con, SORTARRIVAL, 1, SE_NOPREFETCH, "TO $address $date");//,"utf-8"
        $s3 = imap_sort($this->con, SORTARRIVAL, 1, SE_NOPREFETCH, "CC $address $date");//,"utf-8"
        $s4 = imap_sort($this->con, SORTARRIVAL, 1, SE_NOPREFETCH, "BCC $address $date");//,"utf-8"
        $s = array_merge($s1,$s2,$s3,$s4);
        return $s;
    }
    
    
    
    
    // delete mails
	function remove($ids){
    	if(!is_array($ids)){ $ids = array($ids);}
        foreach($ids as $id){
            imap_delete ($this->con, $id);
            // Mit Hilfe der Option FT_UID kann festgelegt werden das msg_number an Stelle von Nachrichtennummern UIDs enthält.
        }
        imap_expunge($this->con);
    }
    
    
    
    
    
    
    // read full messages... i.e. a combination of header and body
    function messages($ids,$files=true){
        $ret = array();
        foreach($ids as $id){
            $ret[$id] = array_merge( $this->header($id), $this->body($id,$files) );
        }
        return $ret;
    }
    
    function ls($filter='',$limit=10){
        $ids = $this->ids($filter);
        $ids = array_slice($ids, 0, $limit);
    	return $this->headers( $ids );
    }
    function headers($ids){
        $ret = array();
        foreach($ids as $id){
            $ret[$id] = $this->header($id);
        }
        return $ret;
    }
    
    
    // read the full mail-header and normalize the result
    function header($id){ 
        $head = imap_header($this->con,$id);
        //print_r($head);
        $mail = (array)$head;
        $tmp = array();
        $tmp['messageNumber'] = $id;
        $tmp['t'] = intDate(xDate($mail['date']));
        $tmp['messageID'] = trim($mail['message_id'],'<>');
        $tmp['inReplyToID'] = trim($mail['in_reply_to'],'<>'); if(!$tmp['inReplyToID']){ unset($tmp['inReplyToID']); }
    //    	$tmp['userAgent2'] = $mail['X-Mailer'];
        $tmp21 = explode(' ',$mail['references']);
        $tmp22 = explode(',',$mail['references']);
        $tmp['references'] = count($tmp21)>count($tmp22)?$tmp21:$tmp22;
        foreach($tmp['references'] as $k=>$v){$tmp['references'][$k] = trim($v,'<>');}
        if(!$tmp['references'][0]){ unset($tmp['references']); }
        $tmp['from'] = $this->normalizeMailID($mail['from']);
        $tmp['to'] = $this->normalizeMailID($mail['to']); if(!$tmp['to']){ unset($tmp['to']); }
        $tmp['cc'] = $this->normalizeMailID($mail['cc']); if(!$tmp['cc']){ unset($tmp['cc']); }
        $tmp['bcc'] = $this->normalizeMailID($mail['bcc']); if(!$tmp['bcc']){ unset($tmp['bcc']); }
        $tmp['replyTo'] = $this->normalizeMailID($mail['reply_to']);
        if (!array_diff( array_keys($tmp['replyTo']), array_keys($tmp['replyTo']) )){unset($tmp['replyTo']);}
        $tmp['seen'] = true;
        if($mail['Recent']=='N'){$tmp['seen'] = false;}
        if($mail['Unseen']=='U'){$tmp['seen'] = false;}
        $tmp['answered'] = false;
        if($mail['Answered']=='A'){$tmp['answered'] = true;}
        $tmp['subject'] = $this->decodeHeader($mail['subject']);
        $tmp['size'] = $mail['Size'];
        $tmp['userAgent'] = trim($mail['User-Agent']?$mail['User-Agent']:$mail['X-Mailer']);  
        	if(!$tmp['userAgent']){ unset($tmp['userAgent']); }
        return $tmp;
    }
    
    // normalize the mailbox/host/name - set
    function normalizeMailID($p){
        if(!$p){return array();}
        foreach($p as $q){
    /* 		print_r($q); */
//            $ret[] = array('realName'=>$this->decodeHeader($q->personal),'userName'=>$q->mailbox,'host'=>$q->host);
            $ret[$q->mailbox.'@'.$q->host] = $this->decodeHeader($q->personal);
        }
        return $ret;
    }
    
    
    function decodeHeader($h){ 
    	$ret = '';
        $tmp = imap_mime_header_decode($h); 
        foreach ($tmp as $part) {
//            echo "Charset: {$part->charset}<br/>";
//            echo "Text: {$part->text}<br/><br/>";
            if(strtolower($part->charset)=='utf-8')
            	$ret .= ($part->text);
            else
            	$ret .= utf8_encode($part->text);
        }
        return $ret;
//        return utf8_encode($tmp[0]->text); // utf8_encode
    }
    
    
    
    // return a single attachment of a given mail, decoding the body in the process.
    function file($id,$fileName){
        $tmp = $this->body($id);
        $pi = pathinfo($fileName);
        header("Content-type: ".$this->contentTypes[$pi['extension']]);
        header("Content-Disposition: attachment; filename='$fileName'");
        echo $tmp['attachments'][$fileName];
    }
    
    
    // read the full body and normalize the result
    function body($id,$files=true){ //utf8_encode
        global $msgheader,$htmlmsg,$plainmsg,$charset,$charsetRaw,$attachments;
        $this->getmsg($id);
        $ret['text'] = utf8_encode($plainmsg);
        $ret['html'] = utf8_encode($htmlmsg);
        $ret['charset'] = $charset;
        $ret['charsetRaw'] = $charsetRaw;
        //if(!$ret['body']['content']){$ret['body']['content'] = $ret['body']['html'];}
        $ret['attachments'] = $attachments;
        if(!$files){ $ret['attachments']=array_keys($ret['attachments']); }
        if(!$ret['attachments']){ unset($ret['attachments']); }
        return $ret;
    }
    
    
    // legacy function... partial copy paste from various sites... PLEASE REWRITE!!!
    function getmsg($id) {
        // input $mbox = IMAP stream, $mid = message id
        // output all the following:
        global $msgheader,$htmlmsg,$plainmsg,$charset,$attachments;
        // the message may in $htmlmsg, $plainmsg, or both
        $htmlmsg = $plainmsg = $charset = '';
        $attachments = array();
        $msgheader = array();
    
        // HEADER
        $h = imap_header($this->con,$id);
        // add code here to get date, from, to, cc, subject...
        //print_r($h);
        
        $msgheader['id'] = trim($h->message_id);
        $msgheader['number'] = trim($h->Msgno);
        $msgheader['date'] = $h->date;
        $msgheader['subject'] = $h->subject;
        foreach($h->from as $v){if(!$v->personal){$v->personal=$v->mailbox;}$msgheader['from'][] = (array)$v;}
        foreach($h->to as $v){$msgheader['to'][] = (array)$v;}
        foreach($h->reply_to as $v){$msgheader['reply'][] = (array)$v;}
        //foreach($h->bcc as $v){$msgheader['bcc'][] = (array)$v;}
    
        // BODY
        $s = imap_fetchstructure($this->con,$id);
        if (!$s->parts)  // not multipart
            $this->getpart($id, $s,0);  // no part-number, so pass 0
        else {  // multipart: iterate through each part
            foreach ($s->parts as $partno0=>$p)
                $this->getpart($id, $p, $partno0+1);
        }
    }
    
    function getpart($id, $p, $partno) {
    	if(!is_numeric($partno)){ return; }
        // $partno = '1', '2', '2.1', '2.1.3', etc if multipart, 0 if not multipart
        global $htmlmsg,$plainmsg,$charset,$charsetRaw,$attachments;
    	
        // DECODE DATA
        $data = ($partno)?
            imap_fetchbody($this->con, $id, $partno):  // multipart
            imap_body($this->con, $id);  // not multipart
        // Any part may be encoded, even plain text messages, so check everything.
        $charsetRaw = $p->encoding;
        if ($p->encoding==4){
            $data = quoted_printable_decode($data);
//	        $data = utf8_decode($data);
        }
        elseif ($p->encoding==3)
            $data = base64_decode($data);
        // no need to decode 7-bit, 8-bit, or binary
    
        // PARAMETERS
        // get all parameters, like charset, filenames of attachments, etc.
        $params = array();
        if ($p->parameters)
            foreach ($p->parameters as $x)
                $params[ strtolower( $x->attribute ) ] = $x->value;
        if ($p->dparameters)
            foreach ($p->dparameters as $x)
                $params[ strtolower( $x->attribute ) ] = $x->value;
    
        // ATTACHMENT
        // Any part with a filename is an attachment,
        // so an attached text file (type 0) is not mistaken as the message.
        if ($params['filename'] || $params['name']) {
            // filename may be given as 'Filename' or 'Name' or both
            $filename = ($params['filename'])? $params['filename'] : $params['name'];
            // filename may be encoded, so see imap_mime_header_decode()
            $attachments[$filename] = $data;  // this is a problem if two files have same name
        }
    
        // TEXT
        elseif ($p->type==0 && $data) {
            // Messages may be split in different parts because of inline attachments,
            // so append parts together with blank row.
            $charset = $params['charset'];  // assume all parts are same charset
            if($charset=='utf-8')
                $data = utf8_decode($data);
            if (strtolower($p->subtype)=='plain')
                $plainmsg .= trim($data) ."\n\n";
            else
                $htmlmsg .= $data ."<br><br>";
        }

        // EMBEDDED MESSAGE
        // Many bounce notifications embed the original message as type 2,
        // but AOL uses type 1 (multipart), which is not handled here.
        // There are no PHP functions to parse embedded messages,
        // so this just appends the raw source to the main message.
        elseif ($p->type==2 && $data) {
            $plainmsg .= trim($data) ."\n\n";
        }
    
        // SUBPART RECURSION
        if ($p->parts) {
            foreach ($p->parts as $partno0=>$p2)
                $this->getpart($this->con,$id,$p2,$partno.'.'.($partno0+1));  // 1.2, 1.2.1, etc.
        }
    }
    
    
    function send($to, $subject, $body, $options=array()){
        $authhost="{000.000.000.000:993/validate-cert/ssl}Drafts"; 
        
        $user="sadasd"; 
        $pass="sadasd"; 
        
        if ($mbox=imap_open( $authhost, $user, $pass)) 
        { 
            $dmy=date("d-M-Y H:i:s"); 
            
            $filename="filename.pdf"; 
            $attachment = chunk_split(base64_encode($filestring)); 
            
            $boundary = "------=".md5(uniqid(rand())); 
            
            $msg = ("From: Somebody\r\n" 
                . "To: test@example.co.uk\r\n" 
                . "Date: $dmy\r\n" 
                . "Subject: This is the subject\r\n" 
                . "MIME-Version: 1.0\r\n" 
                . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n" 
                . "\r\n\r\n" 
                . "--$boundary\r\n" 
                . "Content-Type: text/html;\r\n\tcharset=\"ISO-8859-1\"\r\n" 
                . "Content-Transfer-Encoding: 8bit \r\n" 
                . "\r\n\r\n" 
                . "Hello this is a test\r\n" 
                . "\r\n\r\n" 
                . "--$boundary\r\n" 
                . "Content-Transfer-Encoding: base64\r\n" 
                . "Content-Disposition: attachment; filename=\"$filename\"\r\n" 
                . "\r\n" . $attachment . "\r\n" 
                . "\r\n\r\n\r\n" 
                . "--$boundary--\r\n\r\n"); 
                
            imap_append($mbox,$authhost,$msg, "\\Draft"); 
        
            imap_close($mbox); 
        } 
        else 
        { 
            echo "<h1>FAIL!</h1>\n"; 
        } 

    }
    
}








function intDate($t){
	return $t['year4'].'-'.$t['month2'].'-'.$t['day2'].' '.$t['hour2'].':'.$t['minute2'].':'.$t['second2'];
}
function imapDate($date){
    $d = xDate($date);
    $ds = $d['day'].' '.$d['monthname']['short'].' '.$d['year'];
    if($d['minute']){$ds.=' '.$d['hour2'].':'.$d['minute2'];}
	return '"'.$ds.'"';
}









function sendMail ($p,$logID){
    $from = $p['from']['realName']." <".$p['from']['userName']."@".$p['from']['host'].">";
	$header = 'Content-type: text/html; charset=iso-8859-1' . "\r\n";    
    $header .= 'From: '.$from . "\r\n";
    $header .= 'Avatar: '.$p['photo'] . "\r\n";
    $header .= 'Public-Key: '. $p['key'] . "\r\n";
    
    if($logID){  $p['body'] .= "<img src='http://mails.luplu.com/api/log.php?id=$logID'/>"; }
    $success = mail($p['to'], $p['subject'], $p['body'], $header);
    return $success;
}

































// OLD BODY

    /*     $ret = $msgheader; */
    /*     $ret['subject'] = hdec($ret['subject']); */
    /*
        $ret['body']['text'] = utf8_encode($plainmsg);
        $ret['body']['html'] = utf8_encode($htmlmsg);
    */
    /*
        if($plainmsg){
            $ret['body']['content'] = utf8_encode($plainmsg);
            $ret['body']['type'] = 'text';
        }
        else{
            $ret['body']['content'] = utf8_encode($htmlmsg);
            $ret['body']['type'] = 'html';
        }
    */

?>