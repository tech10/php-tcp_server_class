<?php

//The beginning of the TCP server class.
class tcp_server
{

//Some variable assignments.
public $bindaddr='[::]';
public $port=6000;
public $connect_func = false;
public $disconnect_func = false;
public $recv_func = false;
public $recv_length = 8192;
public $time_interval_func = false;
public $time_interval = 1;
public $time_protocol_check = 0.2;

private $sockets = Array();
private $socket_data = Array();
private $server_socket = false;
private $stream_select_timeout_sec = 0;
private $stream_select_timeout_msec = 200000;

public function __construct($bindaddr = false, $port = 0, $connect_func = false, $disconnect_func = false, $recv_func = false, $recv_length = 0, $time_interval_func = false, $time_interval = 1)
{
if ($bindaddr)
$this->bindaddr = $bindaddr;
if ($port)
$this->port = $port;
if ($connect_func)
$this->connect_func = $connect_func;
if ($disconnect_func)
$this->disconnect_func = $disconnect_func;
if ($recv_func)
$this->recv_func = $recv_func;
if ($recv_length)
$this->recv_length = $recv_length;
if ($time_interval_func)
$this->time_interval_func = $time_interval_func;
if ($time_interval)
$this->time_interval = $time_interval;
}

public function start()
{
$this->server_socket = @stream_socket_server("tcp://$this->bindaddr:$this->port", $errno, $errstr);
if ($this->server_socket === false)
//Socket creation and binding to the address failed.
return false;
stream_set_timeout($this->server_socket, $this->stream_select_timeout_sec, $this->stream_select_timeout_msec);
stream_set_blocking($this->server_socket, 0);
return true;
}

public function sockets()
{
return $this->sockets;
}

public function do_events()
{
//Accept a socket.
$this->accept();
//Let's do some data receiving and timer checking.
$this->recv_all_with_time_check();
}

public function accept()
{
$socket = @stream_socket_accept($this->server_socket, 0);
if ($socket === false)
return false;

$time_connected = microtime(true);
stream_set_blocking($socket, 0);
stream_set_timeout($socket, $this->stream_select_timeout_sec, $this->stream_select_timeout_msec);
$this->sockets_set($socket);
$this->socket_data_set($socket, "time_connected", $time_connected);
$this->socket_data_set($socket, "time_message_received", $time_connected);
return true;
}

public function send_all($data, $socket = false)
{
foreach ($this->sockets as $s_socket)
{
if ($socket !== false && $s_socket === $socket)
continue;
$this->send($s_socket, $data);
}
}

//Send raw data without processing.
public function send_raw($socket, $data)
{
if ($data === false)
return false;
$result = @stream_socket_sendto($socket, $data);
if ($result === -1)
{
//Writing probably failed.
$this->disconnect($socket);
return false;
}
else
{
$this->socket_data_set($socket, "time_message_sent", microtime(true));
return true;
}
}

public function send($socket, $data)
{
if ($data === false)
return false;
if ($data !== "")
$data = trim($data) . "\r\n";

return $this->send_raw($socket, $data);
}

public function disconnect($socket)
{
//Do some checking so we don't have an infinite recursion.
$called = $this->socket_data_get($socket, "disconnect_func_called");
if ($called)
return;
$called = true;
$this->socket_data_set($socket, "disconnect_func_called", $called);
//Check to see if the client was actually connected, or queued for custom protocol checking.
if ($this->socket_data_get($socket, "connected"))
{
//Call the disconnect function if it exists before doing anything with internal arrays, for some consistency and so data can still be retrieved.
if ($this->disconnect_func !== false && $this->disconnect_func !== "")
call_user_func($this->disconnect_func, $this, $socket);
}

//Clear socket data and unset the array.
$this->sockets_clear($socket);

if ($socket !== false)
@fclose($socket);
return true;
}

//Receive raw data from a socket without processing the data.
public function recv_raw($socket)
{
$data = @stream_socket_recvfrom($socket, $this->recv_length);
if ($data === false || $data === "")
{
//The socket was likely closed, could be buggy, I'm not confident about this code.
$this->disconnect($socket);
return false;
}
return $data;
}

public function recv($socket)
{
$data = $this->recv_raw($socket);
if (!$data)
return "";

//Data was received.

//Check for a custom protocol.
$custom_protocol = $this->time_check_protocol($socket);
if ($custom_protocol)
{
$this->disconnect($socket);
return false;
}

//Retrieve the data from the buffer.
$data_stored = $this->socket_data_get($socket, "data_recv");
if (!$data_stored)
$data_stored = "";
$data = $data_stored . $data;

//Removing backspaces.
while (true)
{
$bs_pos = strpos($data, chr(8));
if ($bs_pos === false)
break;
if ($bs_pos !== 0)
{
$data = substr_replace($data, "", $bs_pos - 1, 2);
continue;
}
$data = substr($data, 1);
}

$data = str_replace(Array("\r\n", "\r"), "\n", $data);
//Store the current data in the buffer.
$this->socket_data_set($socket, "data_recv", $data);

//Process for new line character, and if none, continue on.
$pos = strrpos($data,"\n");
if ($pos === false)
return "";

//Make sure we're only processing lines of data that end in a new line.
if (strlen($data) > $pos + 1)
{
$data_stored = substr($data, $pos + 1);
$data = substr($data, 0, $pos + 1);
}
else
{
$data_stored = "";
}

$this->socket_data_set($socket, "data_recv", $data_stored);

//Check for nothing and continue if so.
if ($data === "")
return "";

$this->socket_data_set($socket, "time_message_received", microtime(true));

$lines = explode("\n", $data);
foreach ($lines as $index => $line)
{
if ($index === (count($lines) - 1))
break;
//Get rid of all non-printable characters now.
$line = preg_replace('/[^[:print:]]/', '', $line);

//Trim white space and new line characters from the beginning and end of the string.
$line = trim($line);

//if (!$line)
//continue;

if ($this->recv_func !== false && $this->recv_func !== "")
$result = call_user_func($this->recv_func, $this, $socket, $line);
if ($result === false)
{
$this->disconnect($socket);
break;
}
}
}

//Receive data from all clients who have sent it, without timers.
public function recv_all()
{
$null = null;
//Set an array for sockets who data can be read from.
$sockets_r = $this->sockets;
if (count($sockets_r) && !@stream_select($sockets_r, $null, $null, $this->stream_select_timeout_sec, $this->stream_select_timeout_msec))
return false;

//Here's our loop.
foreach ($sockets_r as $socket)
$this->recv($socket);
return true;
}

//Receive data from all clients who have sent it, and check all timers.
public function recv_all_with_time_check()
{
$null = null;
//Set an array for sockets who data can be read from.
$sockets_r = $this->sockets;
if (!count($this->sockets))
return false;
//Read data from any sockets who have sent it.
if (@stream_select($sockets_r, $null, $null, $this->stream_select_timeout_sec, $this->stream_select_timeout_msec) === false)
return false;
//Here's our loop.
foreach ($this->sockets as $socket)
{
if (array_search($socket, $sockets_r) !== false)
$this->recv($socket);
$custom_protocol = $this->time_check_protocol($socket);
if ($custom_protocol)
continue;
if ($this->time_check_interval($socket) && $this->time_interval_func)
call_user_func($this->time_interval_func, $this, $socket);
}
return true;
}

public function get_ip($socket)
{
if ($socket === false)
return false;
$peer = stream_socket_get_name($socket, true);
$ip = substr($peer, 0, strrpos($peer, ":") - strlen($peer));
return $this->format_ip($ip);
}

private function format_ip($ip)
{
if (strlen($ip) > 6 && substr($ip, 0, 7) == "::ffff:")
return substr($ip, 7);
return $ip;}

public function disconnect_all($msg = "")
{
foreach ($this->sockets as $socket)
{
if ($msg)
$this->send($socket, $msg);
$this->disconnect($socket);
}
}

public function stop()
{
$this->disconnect_all("Shutting down server.");
}

public function restart()
{
$this->disconnect_all("Restarting server.");
}

public function __destruct()
{
$this->stop();
}

//Setting socket data.
public function socket_data_set($socket, $param = null, $value = null)
{
$socket_index = $this->sockets_index_find($socket);
if ($socket_index === false)
return null;
if ($param && $value !== null)
{
if (!is_array($param))
{
$this->socket_data[$socket_index][$param] = $value;
return true;
}
else
{
return false;
}
}
else
{
if (is_array($param))
{
$this->socket_data[$socket_index] = $param;
return true;
}
else
{
return false;
}
}
}

//Getting socket data.
public function socket_data_get($socket, $param = null)
{
$socket_index = $this->sockets_index_find($socket);
if ($socket_index === false || ($param && !isset($this->socket_data[$socket_index][$param])))
return null;
if ($param)
return $this->socket_data[$socket_index][$param];
return $this->socket_data[$socket_index];
}

//Clearing socket data.
public function socket_data_clear($socket, $param = null)
{
$socket_index = $this->sockets_index_find($socket, $this->sockets);
if ($socket_index === false)
return null;
if ($param !== null && isset($this->socket_data[$socket_index][$param]))
{
unset($this->socket_data[$socket_index][$param]);
}
else if (isset($this->socket_data[$socket_index]))
{
unset($this->socket_data[$socket_index]);
}
return true;
}

public function socket_time_message_received($socket)
{
return $this->socket_data_get($socket, "time_message_received");
}
public function socket_time_message_sent($socket)
{
return $this->socket_data_get($socket, "time_message_sent");
}
public function socket_time_connected($socket)
{
return $this->socket_data_get($socket, "time_connected");
}
public function socket_time_idle($socket)
{
return (microtime(true) - $this->socket_data_get($socket, "time_message_received"));
}

//Check timers.
private function time_check()
{
foreach ($this->sockets as $socket)
$this->time_check_protocol($socket);
}

private function time_check_protocol($socket)
{
//Declare some variables.
$time = microtime(true);
$time_connected = $this->socket_time_connected($socket);
//Check protocol checking time.
if ($time_connected && ($time - $time_connected) >= $this->time_protocol_check)
{
//Using the telnet protocol.
if (!$this->socket_data_get($socket, "connected"))
{
$this->socket_data_set($socket, "connected", true);
$result = true;
if ($this->connect_func !== false && $this->connect_func !== "")
$result = call_user_func($this->connect_func, $this, $socket);
if ($result === false)
{
$this->disconnect($socket);
return true;
}
}
return false;
}
else
{
//Using some other protocol, maybe a web browser.
return true;
}
}

private function time_check_interval($socket)
{
if (!$this->socket_data_get($socket, "connected"))
return false;
$time = microtime(true);
$idle = round($this->socket_time_idle($socket), 3);
$time_interval = $this->socket_data_get($socket, "time_interval");
$connected = $this->socket_time_connected($socket);
if (!$time_interval)
{
$this->socket_data_set($socket, "time_interval", $connected + $this->time_interval);
return false;
}
$interval = $this->time_interval - ($time_interval - $time);
if ($interval <= $this->time_interval && $idle >= 0)
return false;
$this->socket_data_set($socket, "time_interval", $time_interval + $this->time_interval);
return true;
}

private function sockets_index_find($socket)
{
return array_search($socket, $this->sockets);
}

private function sockets_index_find_empty()
{
$rindex = 0;
foreach($this->sockets as $index => $socket)
{
if ($index !== $rindex)
return $rindex;
$rindex++;
}
return $rindex;
}

private function sockets_clear($socket)
{
$this->socket_data_clear($socket);
$index = $this->sockets_index_find($socket);
if ($index === false)
return false;
unset($this->sockets[$index]);
ksort($this->sockets);
return true;
}

private function sockets_set($socket)
{
$index = $this->sockets_index_find_empty();
$this->sockets[$index] = $socket;
return true;
}

public function socket_uid_get($socket)
{
return ($this->sockets_index_find($socket) + 1);
}

public function socket_uid_set($socket, $id)
{
if (!is_int($id))
return false;
$result = true;
$index = $id - 1;
$cur_id = $this->socket_uid_get($socket);
$cur_index = $cur_id - 1;
$cur_data = $this->socket_data_get($socket);
if ($id === $cur_id)
return true;
if (isset($this->sockets[$index]))
{
$tmpsocket = $this->sockets[$index];
if ($tmpsocket !== $socket)
{
$tmpdata = $this->socket_data_get($tmpsocket);
unset($this->sockets[$index]);
$this->sockets[$cur_index] = $tmpsocket;
$this->socket_data_set($tmpsocket, $tmpdata);
$result = $tmpsocket;
}
else
{
$this->socket_data_clear($tmpsocket);
unset($this->sockets[$cur_index]);
}
}
else
{
$this->socket_data_clear($socket);
unset($this->sockets[$cur_index]);
}
$this->sockets[$index] = $socket;
$this->socket_data_set($socket, $cur_data);
ksort($this->sockets);
return $result;
}

public function socket_uid_find($id)
{
$id = intval($id);
if (!$id || !isset($this->sockets[$id - 1]))
return false;
return $this->sockets[$id - 1];
}
}
?>
