<?php

$topic = $_GET["topic"];

if ($topic) {
    header('Content-Type: text/event-stream');
    header('Grip-Hold: stream');
    header('Grip-Channel: ' . $topic);
    header('Grip-Keep-Alive: :\n\n; format=cstring; timeout=55');

    print "event: message\ndata: stream open\n\n";
} else {
    header('Content-Type: text/plain');

    print "missing parameter: topic\n";
}

?>
