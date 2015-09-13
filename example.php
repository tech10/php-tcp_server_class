<?php
//Example use for the tcp_server class, extremely basic use.

include './tcp_server.php';

//Binds to all IPV6 addresses, listens on port 6000,
//sets up functions to process server connections,
//disconnections, receiving of data,
//sets the receive length to 8192 bites,
//sets a function to check the timers of clients,
//and sets the time interval to 1 indicating time checks will
//be executed approximately every second.

$server = new tcp_server_class('[::]', 6000, "process_connect", "process_disconnect", "process_recv", 8192, "process_time_checks", 1);

//Starts the server and checks the result, if it's false, the //server couldn't listen on the address and port given.

if (!$server->start())
die("Couldn't listen on the supplied address and port, check to make sure it's not in use.");

echo "Server started.\r\n";

//Do the server events.

while (true)
{
$server->do_events();
//Sleep for time so we're not using up a ton of CPU.
//We need this because your application can execute other tasks
//along with the server.
//WARNING: If your tasks take too long, the server will stop
//processing everything until your task completes.
usleep(5000);
}

//Stop the server.
//This is only here for demonstration and isn't actually
//needed.
//The server class has a __destruct function that will take
//care of cleanly disconnecting all clients and processing them //through your disconnect function if supplied.
$server->stop();
exit;

//Your functions must include the following arguments.
//A variable for the server object.
//A variable for the socket of the connected client.
//If it's the receive function, include a variable for the
//received data.

//In these functions, $s is the server object and $c is the
//socket.

//Define the function to process connections.
function process_connect($s, $c)
{
//Get the client ID.
$id = $s->socket_uid_get($c);

//If the client successfully connected and passed all checks,
//return true.
//Otherwise, return false.
//For now, we'll return the result of sending data to the
//client itself.
//If that failed, the data couldn't be sent and the client is
//disconnected.
//This function automatically appends a CRLF to the end of the
//sent data, so we don't have to worry about inserting our own.

//First, send to all other clients accept the one connected
//that a client, with the id from $id, has connected.
$s->send_all("Client $id has connected.", $c);

//Output to the console.
echo "Client $id has connected.\r\n";
return $s->send($c, 'Connected.');
}

//Process receiving of data.
function process_recv($s, $c, $data)
{
//Allow the client to send a message to disconnect itself.
if ($data == 'quit')
return $s->disconnect($c);

//Get the client ID.
$id = $s->socket_uid_get($c);
//Get the result of sending data to the client.
$r = $s->send($c, "You said: $data");
//If it's false, return false.
//We check so we don't send the data to all the other clients.
if ($r === false)
return false;

echo "Client $id says: $data\r\n";

//Now we send data to all other clients and return that result.
return $s->send_all("Client $id says: $data", $c);
}

//Process timers.
function process_time_checks($s, $c)
{
//This will run every second, since our time interval was set
//to 1 previously.

//We'll do some sending of data using a random value between
//ten and sixty seconds of the clients connection.

$time_s = mt_rand(10, 60);

//We'll retrieve the time the client has been connected,
//and the time the client has been idle and not sent data.
//We'll also get the time data was last sent to the client
//so we can send data every $time_s seconds.

//These values are microtime timestamps, so we subtract
//the current microtime to get the amount of seconds the
//timers are.
//The only value that isn't a time stamp is the idle time, it
//reports how long it's been, in microtime seconds, since the
//client sent a message.

$time = microtime(true);
$tc = $time - $s->socket_time_connected($c);
$ti = $s->socket_time_idle($c);
$ts = $time - $s->socket_time_message_sent($c);

//Now we'll set up our sending of data.
if (($ts - $time_s) > 0)
$s->send($c, "You have been connected for $tc seconds, idle for $ti seconds, and the last message was sent to you $ts seconds ago.");

//And that's it!
}

//Process disconnections.
function process_disconnect($s, $c)
{
//Get the client ID.
$id = $s->socket_uid_get($c);

//Let you know you disconnected.
$s->send($c, "Disconnected.");

echo "Client $id has disconnected.\r\n";

//Let the other clients know you disconnected.
$s->send_all("Client $id has disconnected.", $c);
//We don't care what the function returns here.
return;
}
?>