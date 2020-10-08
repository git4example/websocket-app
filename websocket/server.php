#!/php -q
<?php  /*  >php -q server.php  */

#error_reporting(E_ALL);
error_reporting(0);
set_time_limit(0);
ob_implicit_flush();

$master  = WebSocket("0.0.0.0",8080);
$sockets = array($master);
$users   = array();
$debug   = true;

while(true){
  $changed = $sockets;
  socket_select($changed,$write=NULL,$except=NULL,NULL);
  foreach($changed as $socket){
    if($socket==$master){
      $client=socket_accept($master);
      if($client<0){ console("socket_accept() failed"); continue; }
      else{ connect($client); }
    }
    else{
      $bytes = @socket_recv($socket,$buffer,2048,0);
      if($bytes==0){ disconnect($socket); }
      else{
        $user = getuserbysocket($socket);
        if(!$user->handshake){ dohandshake($user,$buffer); }
        else{ process($user,$buffer); }
      }
    }
  }
}

//---------------------------------------------------------------
function process($user,$msg){
  $action = unwrap($msg);
  say("< ".$action);
  switch($action){
    case "hello" : send($user->socket,"hi!, human");break;
    case "hi"    : send($user->socket,"Good Afternoon, human");break;
    case "name"  : send($user->socket,"my name is Siri 2.0, silly!"); break;
    case "age"   : send($user->socket,"old enough.");       break;
    case "date"  : send($user->socket,"I remember it as ".date("Y.m.d"));           break;
    case "time"  : send($user->socket,"Around ".date("H:i:s"));     break;
    case "thanks": send($user->socket,"you're welcome");                    break;
    case "bye"   : send($user->socket,"bye");                               break;
    #default is to echo
    default      : send($user->socket,$action);                             break;
  }
}

function send($client,$msg){
  say("> ".$msg);
  socket_write($client,wrap($msg));
  #simple write
  #socket_write($client,pack("CCa*",129,strlen($msg),$msg));
}

function WebSocket($address,$port){
  $master=socket_create(AF_INET, SOCK_STREAM, SOL_TCP)     or die("socket_create() failed");
  socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1)  or die("socket_option() failed");
  socket_bind($master, $address, $port)                    or die("socket_bind() failed");
  socket_listen($master,20)                                or die("socket_listen() failed");
  echo "Server Started : ".date('Y-m-d H:i:s')."\n";
  echo "Master socket  : ".$master."\n";
  echo "Listening on   : ".$address." port ".$port."\n\n";
  return $master;
}

function connect($socket){
  global $sockets,$users;
  $user = new User();
  $user->id = uniqid();
  $user->socket = $socket;
  array_push($users,$user);
  array_push($sockets,$socket);
  console($socket." CONNECTED!");
}

function disconnect($socket){
  global $sockets,$users;
  $found=null;
  $n=count($users);
  for($i=0;$i<$n;$i++){
    if($users[$i]->socket==$socket){ $found=$i; break; }
  }
  if(!is_null($found)){ array_splice($users,$found,1); }
  $index = array_search($socket,$sockets);
  socket_close($socket);
  console($socket." DISCONNECTED!");
  if($index>=0){ array_splice($sockets,$index,1); }
}

function dohandshake($user,$buffer){
  console("\nRequesting handshake...");
  console($buffer);
  list($resource,$host,$origin,$strkey,$data) = getheaders($buffer);
  console("Handshaking...");

  $data = $strkey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
  $hash = base64_encode(sha1($data, true));

  $upgrade  = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" .
              "Upgrade: WebSocket\r\n" .
              "Connection: Upgrade\r\n" .
              "Sec-WebSocket-Origin: " . $origin . "\r\n" .
              "Sec-WebSocket-Location: ws://" . $host . $resource . "\r\n" .
              "Sec-WebSocket-Accept: " . $hash . "\r\n" .
              "\r\n";

  socket_write($user->socket,$upgrade,strlen($upgrade));
  $user->handshake=true;
  console($upgrade);
  console("Done handshaking...");
  return true;
}

function getheaders($req){
  $r=$h=$o=null;
  if(preg_match("/GET (.*) HTTP/"   ,$req,$match)){ $r=$match[1]; }
  if(preg_match("/Host: (.*)\r\n/"  ,$req,$match)){ $h=$match[1]; }
  if(preg_match("/Origin: (.*)\r\n/",$req,$match)){ $o=$match[1]; }
  if(preg_match("/Sec-WebSocket-Key: (.*)\r\n/",$req,$match)){ $key=$match[1]; }
  if(preg_match("/\r\n(.*?)\$/",$req,$match)){ $data=$match[1]; }
  return array($r,$h,$o,$key,$data);
}

function getuserbysocket($socket){
  global $users;
  $found=null;
  foreach($users as $user){
    if($user->socket==$socket){ $found=$user; break; }
  }
  return $found;
}

function     say($msg=""){ echo $msg."\n"; }
function  wrap($msg=""){ return frame($msg,null,'text',false); }
function  unwrap($msg=""){ return decode($msg); }
function console($msg=""){ global $debug; if($debug){ echo $msg."\n"; }}

function frame($message, $user, $messageType='text', $messageContinues=false) {
    $sendingContinuous = false;
    switch ($messageType) {
      case 'continuous':
        $b1 = 0;
        break;
      case 'text':
        $b1 = ($sendingContinuous) ? 0 : 1;
        break;
      case 'binary':
        $b1 = ($sendingContinuous) ? 0 : 2;
        break;
      case 'close':
        $b1 = 8;
        break;
      case 'ping':
        $b1 = 9;
        break;
      case 'pong':
        $b1 = 10;
        break;
    }
    if ($messageContinues) {
      $sendingContinuous = true;
    } 
    else {
      $b1 += 128;
      $sendingContinuous = false;
    }
    $length = strlen($message);
    $lengthField = "";
    if ($length < 126) {
      $b2 = $length;
    } 
    elseif ($length < 65536) {
      $b2 = 126;
      $hexLength = dechex($length);
      //$this->stdout("Hex Length: $hexLength");
      if (strlen($hexLength)%2 == 1) {
        $hexLength = '0' . $hexLength;
      } 
      $n = strlen($hexLength) - 2;
      for ($i = $n; $i >= 0; $i=$i-2) {
        $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
      }
      while (strlen($lengthField) < 2) {
        $lengthField = chr(0) . $lengthField;
      }
    } 
    else {
      $b2 = 127;
      $hexLength = dechex($length);
      if (strlen($hexLength)%2 == 1) {
        $hexLength = '0' . $hexLength;
      } 
      $n = strlen($hexLength) - 2;
      for ($i = $n; $i >= 0; $i=$i-2) {
        $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
      }
      while (strlen($lengthField) < 8) {
        $lengthField = chr(0) . $lengthField;
      }
    }
    return chr($b1) . chr($b2) . $lengthField . $message;
  }
function decode($data) {
  $payloadLength = '';
  $mask = '';
  $unmaskedPayload = '';
  $decodedData = array();
  // estimate frame type:
  $firstByteBinary = sprintf('%08b', ord($data[0]));
  $secondByteBinary = sprintf('%08b', ord($data[1]));
  $opcode = bindec(substr($firstByteBinary, 4, 4));
  $isMasked = ($secondByteBinary[0] == '1') ? true : false;
  $payloadLength = ord($data[1]) & 127;
  // @TODO: close connection if unmasked frame is received.
  switch($opcode)
  {
    // text frame:
    case 1:
      $decodedData['type'] = 'text';
    break;
    // connection close frame:
    case 8:
      $decodedData['type'] = 'close';
    break;
    // ping frame:
    case 9:
      $decodedData['type'] = 'ping';
    break;
    // pong frame:
    case 10:
      $decodedData['type'] = 'pong';
    break;
    default:
      // @TODO: Close connection on unknown opcode.
    break;
  }
  if($payloadLength === 126)
  {
     $mask = substr($data, 4, 4);
     $payloadOffset = 8;
  }
  elseif($payloadLength === 127)
  {
    $mask = substr($data, 10, 4);
    $payloadOffset = 14;
  }
  else
  {
    $mask = substr($data, 2, 4);
    $payloadOffset = 6;
  }
  $dataLength = strlen($data);
  if($isMasked === true)
  {
    for($i = $payloadOffset; $i < $dataLength; $i++)
    {
      $j = $i - $payloadOffset;
      $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
    }
    $decodedData['payload'] = $unmaskedPayload;
  }
  else
  {
    $payloadOffset = $payloadOffset - 4;
    $decodedData['payload'] = substr($data, $payloadOffset);
  }
  return $decodedData['payload'];
}

class User{
  var $id;
  var $socket;
  var $handshake;
}

?>
