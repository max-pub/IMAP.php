<?
class MailCache {
	
    function __construct($imap, $db){
    	$this->IMAP = $imap;
        $this->DB = $db;
    }
	
    
    
    function save($message){
		$main = array();
        $meta = array();
        if(!$message->ID) return false;
        
        $people = array();
        foreach($message->people as $k=>$v)
            foreach($v as $k2=>$v2)
                $people[$k][] = $k2;
        $people['from'] = $people['from'][0];
        $people['replyTo'] = $people['reply_to'][0];
        unset($people['reply_to']);
        if($people['from']==$people['replyTo']) unset($people['replyTo']);
                
                
        $main = $this->copyArrayPart('time,subject,size,files,plainText,htmlSize', $message); // ,plainText,htmlText
        $main['people'] = $people;
        $main['flags'] = array();
        if(!$message->flags->seen) $main['flags'][] = 'unread';
        if($message->flags->star) $main['flags'][] = 'star';
        $main['folder'] = $message->ID->folderName;
        
        $meta = $this->copyArrayPart('ID,people,processingTime,parts', $message);
        
        $save = array(
        	'serverID'		=> $message->ID->serverID,
        	'inReplyToID'	=> $message->ID->inReplyToID,
        	'day'			=> date('Y-m-d', strtotime($main['time'])),
        	'message'		=> json_encode($main),
            'meta'			=> json_encode($meta)
        );
        $this->DB->insert('messages', $save);
//        print_r($db->errorInfo());
		$messageID = $this->DB->lastInsertId()*1;
        
        foreach($message->people as $k=>$v)
            foreach($v as $k2=>$v2)
            	$this->addEmail($k2, $v2, $k, $message->time, $messageID);
            
        $this->findThread($message->ID->serverID);
        return $save;
    }
    
    
    
    
    function copyArrayPart($keys, $array){
        $array = (array) $array;
        $keys = explode(',',$keys);
        $return = array();
        foreach($keys as $key)
            $return[$key] = $array[$key];
        return $return;
    }
    
    
    
    
    function addEmail($address, $name, $direction, $time, $messageID){
        if($direction == 'reply_to') return;
        if($direction != 'from') $direction = 'to';
        $entry = $this->DB->firstRow("SELECT * FROM emails WHERE address='$address'");
        $save = json_decode($entry['contact'],1);
        
        $save['address'] = $address;
        if(!$save['name']) $save['name'] = $name;
        $save[$direction]++;
        
        $y = date('Y', strtotime($time));
        $m = date('m', strtotime($time));
        if( $save['messages'][$y][$m]  &&  in_array($messageID, $save['messages'][$y][$m]) ) return;
        $save['messages'][$y][$m][] = $messageID;
        
    //    print_r($save);
        if($entry['address'])
            $this->DB->update('emails', array('address'=>$address, 'contact'=>json_encode($save)), array('address'=>$address) );
        else
            $this->DB->insert('emails', array('address'=>$address, 'contact'=>json_encode($save)) );
    //    print_r($this->DB->errorInfo());
    }
    
    
    
    
    
    function findThread($serverID){
        $current = $this->DB->firstRow("SELECT * FROM messages WHERE serverID='$serverID'");
        $parent = $this->DB->firstRow("SELECT * FROM messages WHERE serverID='$parentID'");
        $child = $this->DB->firstRow("SELECT * FROM messages WHERE inReplyToID='$serverID'");
        
        if(!$current['threadID']) $this->DB->update('messages', array('threadID'=>$current['messageID']), array('serverID'=>$current['serverID']) );
        if($parent) 	$this->DB->update('messages', array('threadID'=>$parent['threadID']), array('serverID'=>$current['serverID']) );
        if($child) 		$this->DB->update('messages', array('threadID'=>$current['messageID']), array('serverID'=>$child['serverID']) );
        
        if($child) echo "CHILD: $ID\n\n";
        print_r($child);
    }
    
    
    function findAllThreads(){
        $all = $this->DB->all("SELECT * FROM messages WHERE inReplyToID != ''");
        foreach($all as $msg){
        	$meta = json_decode($msg['meta']);
            $refs = $meta->ID->references;
            $refString = implode("','",$refs);
            $thread = $this->DB->all("SELECT * FROM messages WHERE serverID IN ('$refString','{$msg['serverID']}') ORDER BY day, messageID DESC");
            $threadID = $thread[0]['messageID'];
            echo "THREAD-ID: $threadID - ".count($thread)."\n\n";
            print_r($thread);
            foreach($thread as $m)
            	$this->DB->update("messages", array('threadID'=>$threadID), array('messageID'=>$m['messageID']) );
        }
    }
    
    
}	

?>